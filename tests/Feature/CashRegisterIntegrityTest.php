<?php

namespace Tests\Feature;

use App\Enums\CashTransactionType;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CashRegisterWorkflowService;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Intégrité du tableau de caisse.
 *
 * Régresse sur les deux causes d'écart relevées en production :
 * un encaissement sans caisse ouverte était perdu en silence, et un
 * règlement non cash était compté comme de la monnaie manipulée.
 */
class CashRegisterIntegrityTest extends TestCase
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

    private function makeOrder(User $user, float $total = 5000): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'pending',
            'total' => $total,
        ]);
    }

    public function test_pos_sale_is_refused_without_an_open_register(): void
    {
        $caissier = $this->makeUser('Caissier');
        $item = $this->makeMenuItem(1000);

        $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertUnprocessable();

        // La vente est refusée dans son intégralité : aucune trace fantôme.
        $this->assertSame(0, Order::count());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_invoice_payment_is_refused_without_an_open_register(): void
    {
        $caissier = $this->makeUser('Caissier');
        $admin = $this->makeUser('Admin');
        $invoice = $this->makeUnpaidInvoice($admin, 5000);

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/invoices/{$invoice->id}/pay", ['payment_method' => 'cash'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_sale_reaches_the_drawer(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $caissier);
        $item = $this->makeMenuItem(1000);

        $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 3, 'unit_price' => 1000]],
        ])->assertCreated();

        $this->assertDatabaseHas('cash_transactions', [
            'cash_register_session_id' => $session->id,
            'type' => 'sale',
            'amount' => 3000,
            'payment_method' => 'cash',
        ]);
    }

    public function test_non_cash_sale_does_not_reach_the_drawer(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $caissier);
        $item = $this->makeMenuItem(1000);

        $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'card',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 4, 'unit_price' => 1000]],
        ])->assertCreated();

        // La recette est bien encaissée…
        $this->assertDatabaseHas('payments', ['amount' => 4000, 'payment_method' => 'card']);
        // …mais aucune monnaie n'a traversé le tiroir.
        $this->assertDatabaseCount('cash_transactions', 0);

        $expected = app(CashRegisterWorkflowService::class)->calculateExpectedBalance($session->fresh());
        $this->assertSame(500.0, $expected);
    }

    public function test_a_sale_by_another_user_lands_on_the_open_register(): void
    {
        $titulaire = $this->makeUser('Caissier');
        $serveur = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $titulaire);
        $item = $this->makeMenuItem(2500);

        // Le serveur encaisse alors qu'il n'a pas de session personnelle.
        $this->actingAs($serveur, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 2500]],
        ])->assertCreated();

        // L'argent part sur la seule caisse ouverte : plus de paiement perdu.
        $this->assertDatabaseHas('cash_transactions', [
            'cash_register_session_id' => $session->id,
            'amount' => 2500,
            'user_id' => $serveur->id,
        ]);
    }

    public function test_opening_the_same_register_twice_is_refused(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $service = app(CashRegisterWorkflowService::class);

        $service->openSession($register->id, $caissier->id, 500);

        $this->expectException(\InvalidArgumentException::class);
        $service->openSession($register->id, $caissier->id, 500);
    }

    public function test_closing_counts_only_physical_cash(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $caissier, 500);
        $cash = $this->makeMenuItem(1000);

        $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $cash->id, 'quantity' => 2, 'unit_price' => 1000]],
        ])->assertCreated();

        $card = $this->makeMenuItem(3000);
        $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'card',
            'items' => [['menu_item_id' => $card->id, 'quantity' => 1, 'unit_price' => 3000]],
        ])->assertCreated();

        // Fond 500 + 2000 d'espèces. Les 3000 par carte n'entrent pas.
        $response = $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 2500])
            ->assertOk();

        $response->assertJsonPath('expected_balance', 2500);
        $response->assertJsonPath('discrepancy', 0);

        // Le solde de la caisse physique suit le comptage réel.
        $this->assertDatabaseHas('cash_registers', ['id' => $register->id, 'current_balance' => 2500]);
    }

    public function test_closing_reports_the_real_discrepancy(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $caissier, 500);

        app(CashRegisterWorkflowService::class)->addTransaction(
            session: $session,
            type: CashTransactionType::Sale,
            amount: 1000,
            userId: $caissier->id,
            paymentMethod: 'cash',
        );

        // Fonds 500 + 1000 d'espèces = 1500 attendus, 1400 comptés.
        $response = $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 1400])
            ->assertOk();

        $response->assertJsonPath('expected_balance', 1500);
        $response->assertJsonPath('counted_amount', 1400);
        $response->assertJsonPath('discrepancy', -100);
    }

    public function test_session_index_exposes_the_expected_balance(): void
    {
        $caissier = $this->makeUser('Admin');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $caissier, 500);

        $response = $this->actingAs($caissier, 'sanctum')->getJson('/api/v1/cash-register-sessions')
            ->assertOk();

        $response->assertJsonPath('data.0.id', $session->id);
        $response->assertJsonPath('data.0.expected_balance', 500);
    }

    public function test_a_transaction_of_a_closed_session_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('Admin');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $admin, 500);

        $transaction = $session->transactions()->create([
            'user_id' => $admin->id,
            'type' => 'sale',
            'amount' => 700,
            'payment_method' => 'cash',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 1200])
            ->assertOk();

        // Même l'Admin ne peut plus réécrire l'historique d'une caisse fermée.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/cash-transactions/{$transaction->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('cash_transactions', ['id' => $transaction->id, 'deleted_at' => null]);
    }

    public function test_a_session_with_transactions_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('Admin');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $admin, 500);

        $transaction = $session->transactions()->create([
            'user_id' => $admin->id,
            'type' => 'sale',
            'amount' => 700,
            'payment_method' => 'cash',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 1200])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/cash-register-sessions/{$session->id}")
            ->assertUnprocessable();

        // La clé étrangère est en cascadeOnDelete : sans ce garde-fou, la
        // session ET ses mouvements auraient été effacés de la base, sans
        // possibilité de récupération.
        $this->assertDatabaseHas('cash_register_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('cash_transactions', ['id' => $transaction->id]);
    }

    public function test_an_empty_closed_session_still_can_be_deleted(): void
    {
        $admin = $this->makeUser('Admin');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $admin, 500);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 500])
            ->assertOk();

        // Une session sans aucun mouvement ne porte rien d'irremplaçable :
        // elle peut être nettoyée.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/cash-register-sessions/{$session->id}")
            ->assertOk();

        $this->assertDatabaseMissing('cash_register_sessions', ['id' => $session->id]);
    }

    private function makeUnpaidInvoice(User $user, float $total, ?int $clientId = null): Invoice
    {
        $order = $this->makeOrder($user, $total);

        return Invoice::create([
            'order_id' => $order->id,
            'client_id' => $clientId,
            'invoice_number' => 'INV-'.uniqid(),
            'subtotal' => $total,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $total,
            'status' => 'unpaid',
        ]);
    }

    public function test_unpay_reverses_the_client_loyalty_it_credited(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $this->openSession($register, $caissier);

        $client = Client::create(['first_name' => 'Aline', 'last_name' => 'Test', 'phone' => '04'.uniqid()]);
        $invoice = $this->makeUnpaidInvoice($caissier, 2500, $client->id);

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/invoices/{$invoice->id}/pay", ['payment_method' => 'cash'])
            ->assertOk();

        $client->refresh();
        $this->assertSame(1, (int) $client->visits);
        $this->assertSame(2500.0, (float) $client->total_spent);

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/invoices/{$invoice->id}/unpay")
            ->assertOk();

        // L'annulation doit annuler la fidélité : sans cela, `total_spent`
        // restait gonflé à vie et les rapports clients dérivaient vers le haut.
        $client->refresh();
        $this->assertSame(0, (int) $client->visits);
        $this->assertSame(0.0, (float) $client->total_spent);
    }

    public function test_unpay_is_refused_once_the_session_is_closed(): void
    {
        $caissier = $this->makeUser('Caissier');
        $register = $this->makeRegister();
        $session = $this->openSession($register, $caissier);
        $invoice = $this->makeUnpaidInvoice($caissier, 5000);

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/invoices/{$invoice->id}/pay", ['payment_method' => 'cash'])
            ->assertOk();

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 5500])
            ->assertOk();

        // Rouvrir la caisse pour effacer un encaissement déjà clôturé
        // réécrivait l'historique de la session fermée.
        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/invoices/{$invoice->id}/unpay")
            ->assertUnprocessable();

        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'deleted_at' => null]);
    }
}
