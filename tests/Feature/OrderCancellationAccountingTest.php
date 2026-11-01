<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockItem;
use App\Models\User;
use App\Services\CashRegisterWorkflowService;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Non-régression du règlement comptable d'une annulation et de
 * l'approvisionnement du stock.
 *
 * Régresse sur trois défauts relevés en audit :
 * - annuler une commande payée laissait CA, caisse et fidélité crédités ;
 * - le tiroir du dashboard ne comptait que le fond d'ouverture ;
 * - créer un article de stock n'écrivait pas de mouvement d'entrée.
 */
class OrderCancellationAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = [
            'orders' => ['view', 'create', 'edit', 'delete'],
            'invoices' => ['view', 'create', 'edit', 'delete'],
            'cash_registers' => ['view', 'create', 'edit', 'delete'],
            'clients' => ['view', 'create', 'edit', 'delete'],
            'stock' => ['view', 'create', 'edit', 'delete'],
            'menu' => ['view', 'create', 'edit', 'delete'],
            'tables' => ['view', 'create', 'edit', 'delete'],
            'pos' => ['view', 'create'],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(
                    ['name' => "{$module}.{$action}"],
                    ['module' => $module, 'action' => $action, 'guard_name' => 'web']
                );
            }
        }

        $all = Permission::pluck('id')->toArray();
        foreach (RolePermissionMatrix::map() as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->permissions()->sync($permissions === '*' ? $all : Permission::whereIn('name', $permissions)->pluck('id')->toArray());
        }
    }

    private function makeUser(string $roleName): User
    {
        $user = User::create([
            'name' => $roleName.' User',
            'username' => strtolower($roleName).'-'.uniqid(),
            'email' => strtolower($roleName).'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->sync([Role::where('name', $roleName)->first()->id]);

        return $user;
    }

    private function makeRegister(): CashRegister
    {
        return CashRegister::create(['name' => 'Caisse Principale', 'is_open' => false, 'current_balance' => 0]);
    }

    private function openSession(CashRegister $register, User $user, float $openingAmount = 500): CashRegisterSession
    {
        $register->update(['is_open' => true]);

        return CashRegisterSession::create([
            'cash_register_id' => $register->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opening_amount' => $openingAmount,
            'opened_at' => now(),
        ]);
    }

    private function makeMenuItem(float $price): MenuItem
    {
        $unique = uniqid();
        $category = MenuCategory::firstOrCreate(['slug' => 'cat-'.$unique], ['name' => 'Cat '.$unique]);

        return MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Plat '.$unique,
            'slug' => 'plat-'.$unique,
            'price' => $price,
            'is_available' => true,
        ]);
    }

    public function test_cancelling_a_paid_pos_cash_order_records_a_refund_in_the_open_session(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $caissier, 500);
        $item = $this->makeMenuItem(1000);

        $sale = $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 2, 'unit_price' => 1000]],
        ])->assertCreated();

        $orderId = $sale->json('order.id');

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk();

        // Le journal est réécrit : la vente reste, le remboursement sort.
        $this->assertDatabaseHas('cash_transactions', ['reference_type' => null, 'type' => 'refund', 'amount' => 2000]);
        $this->assertSame(1, $session->transactions()->where('type', 'refund')->count());
        $this->assertSame(1, $session->transactions()->where('type', 'sale')->count());

        // Solde attendu redevenu au fond de caisse seul (2000 encaissés, 2000 remboursés).
        $expected = app(CashRegisterWorkflowService::class)->calculateExpectedBalance($session->fresh());
        $this->assertSame(500.0, $expected);

        // La facture est annulée (plus d'impayé fantôme) et les paiements sortent du CA.
        $invoiceId = $sale->json('invoice.id');
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => 'cancelled']);
        $this->assertSoftDeleted('payments', ['invoice_id' => $invoiceId]);

        // La commande est bien annulée.
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'cancelled']);
    }

    public function test_cancelling_a_paid_cash_order_is_refused_without_an_open_register(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $caissier, 500);
        $item = $this->makeMenuItem(1000);

        $sale = $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertCreated();

        $orderId = $sale->json('order.id');
        $invoiceId = $sale->json('invoice.id');

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 1500])
            ->assertOk();

        // Sans tiroir ouvert, le remboursement en espèces est impossible :
        // on refuse plutôt que de laisser la caisse créditée à tort.
        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/orders/{$orderId}/cancel")
            ->assertUnprocessable();

        // Transaction annulée entièrement : la commande n'est PAS annulée.
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'served']);
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoiceId, 'deleted_at' => null]);
    }

    public function test_cancelling_a_served_order_restores_the_stock(): void
    {
        $caissier = $this->makeUser('Caissier');
        $item = $this->makeMenuItem(5000);

        $stock = StockItem::create([
            'name' => 'Bière '.uniqid(),
            'category' => 'Boissons',
            'unit' => 'bouteille',
            'quantity' => 10,
            'min_quantity' => 2,
            'unit_cost' => 500,
        ]);

        MenuItemIngredient::create([
            'menu_item_id' => $item->id,
            'stock_item_id' => $stock->id,
            'quantity' => 2,
        ]);

        $order = $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 5000]],
        ])->assertCreated()->json('order');

        // Servir déduit les ingrédients : 10 - 2 = 8.
        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/orders/{$order['id']}/serve")
            ->assertOk();
        $this->assertSame(8.0, (float) $stock->fresh()->quantity);

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/orders/{$order['id']}/cancel")
            ->assertOk();

        // L'annulation restitue exactement ce qui était sorti.
        $this->assertSame(10.0, (float) $stock->fresh()->quantity);
        $this->assertSame(1, $stock->movements()->where('type', 'in')->count());
        $this->assertStringContainsString('Annulation commande', $stock->movements()->where('type', 'in')->first()->reason);
    }

    public function test_cancelling_a_paid_order_reverses_the_client_loyalty(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $this->openSession($register, $caissier, 500);
        $client = Client::create(['first_name' => 'Aline', 'last_name' => 'Test', 'phone' => '04'.uniqid()]);

        $sale = $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $this->makeMenuItem(1000)->id, 'quantity' => 3, 'unit_price' => 1000]],
            'client_id' => $client->id,
        ])->assertCreated();

        $client->refresh();
        $this->assertSame(1, (int) $client->visits);
        $this->assertSame(3000.0, (float) $client->total_spent);

        $this->actingAs($caissier, 'sanctum')
            ->putJson('/api/v1/orders/'.$sale->json('order.id').'/cancel')
            ->assertOk();

        $client->refresh();
        $this->assertSame(0, (int) $client->visits);
        $this->assertSame(0.0, (float) $client->total_spent);
    }

    public function test_dashboard_cash_in_hand_counts_the_drawer_movements(): void
    {
        $caissier = $this->makeUser('Admin');
        $register = $this->makeRegister();
        $this->openSession($register, $caissier, 500);

        $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $this->makeMenuItem(1000)->id, 'quantity' => 2, 'unit_price' => 1000]],
        ])->assertCreated();

        $response = $this->actingAs($caissier, 'sanctum')->getJson('/api/v1/dashboard?refresh=1')
            ->assertOk();

        // Fond 500 + 2000 d'espèces encaissées : le tiroir réel, pas le fond seul.
        $response->assertJsonPath('cash_in_hand', 2500);
    }

    public function test_stock_item_creation_journals_the_initial_quantity(): void
    {
        $admin = $this->makeUser('Admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-items', [
            'name' => 'Coca 33cl '.uniqid(),
            'category' => 'Boissons',
            'unit' => 'bouteille',
            'quantity' => 24,
            'min_quantity' => 6,
            'unit_cost' => 300,
        ])->assertCreated();

        $itemId = $response->json('item.id');

        // La quantité initiale est un approvisionnement : elle doit au journal.
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $itemId,
            'type' => 'in',
            'quantity' => 24,
            'reason' => 'Stock initial à la création',
        ]);

        // Un article créé à zéro n'écrit pas de faux mouvement.
        $empty = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-items', [
            'name' => 'Jus '.uniqid(),
            'category' => 'Boissons',
            'unit' => 'bouteille',
            'quantity' => 0,
            'min_quantity' => 2,
            'unit_cost' => 100,
        ])->assertCreated();

        $this->assertSame(0, \App\Models\StockMovement::where('stock_item_id', $empty->json('item.id'))->count());
    }
}
