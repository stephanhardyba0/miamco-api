<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Invoice;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionsAndPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = [
            'users' => ['view', 'create', 'edit', 'delete'],
            'roles' => ['view', 'create', 'edit', 'delete'],
            'menu' => ['view', 'create', 'edit', 'delete'],
            'zones' => ['view', 'create', 'edit', 'delete'],
            'tables' => ['view', 'create', 'edit', 'delete'],
            'orders' => ['view', 'create', 'edit', 'delete'],
            'invoices' => ['view', 'create', 'edit', 'delete'],
            'cash_registers' => ['view', 'create', 'edit', 'delete'],
            'clients' => ['view', 'create', 'edit', 'delete'],
            'stock' => ['view', 'create', 'edit', 'delete'],
            'settings' => ['view', 'edit'],
            'reports' => ['view'],
            'reservations' => ['view', 'create', 'edit', 'delete'],
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

    private function makeOrder(User $user, float $total = 1000): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'pending',
            'total' => $total,
        ]);
    }

    private function makeMenuItem(float $price): MenuItem
    {
        $unique = uniqid();
        $category = MenuCategory::firstOrCreate(
            ['slug' => 'cat-'.$unique],
            ['name' => 'Cat '.$unique]
        );

        return MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Plat '.$unique,
            'slug' => 'plat-'.$unique,
            'price' => $price,
            'is_available' => true,
        ]);
    }

    /**
     * Ouvre une session de caisse pour l'utilisateur.
     *
     * Un encaissement exige désormais une caisse ouverte : sans elle la
     * vente ne peut plus être portée au tableau de caisse.
     */
    private function openCashSessionFor(User $user, float $openingAmount = 500): CashRegisterSession
    {
        $register = CashRegister::firstOrCreate(
            ['name' => 'Caisse Principale'],
            ['is_open' => false, 'current_balance' => 0]
        );

        CashRegisterSession::where('cash_register_id', $register->id)
            ->where('status', 'open')
            ->update(['status' => 'closed', 'closed_at' => now()]);

        return CashRegisterSession::create([
            'cash_register_id' => $register->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opening_amount' => $openingAmount,
            'opened_at' => now(),
        ]);
    }

    public function test_admin_cannot_change_own_roles(): void
    {
        $admin = $this->makeUser('Admin');
        $caissier = Role::where('name', 'Caissier')->first();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/users/{$admin->id}", ['roles' => [$caissier->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Vous ne pouvez pas modifier vos propres rôles.');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/users/{$admin->id}/permissions", ['roles' => [], 'permissions' => []])
            ->assertNotFound();
    }

    public function test_caissier_cannot_delete_orders(): void
    {
        $caissier = $this->makeUser('Caissier');
        $order = $this->makeOrder($caissier);

        $this->actingAs($caissier, 'sanctum')
            ->deleteJson("/api/v1/orders/{$order->id}")
            ->assertForbidden();
    }

    public function test_cuisinier_cannot_delete_menu_categories(): void
    {
        $cuisinier = $this->makeUser('Cuisinier');

        $this->actingAs($cuisinier, 'sanctum')
            ->deleteJson('/api/v1/menu/categories/1')
            ->assertForbidden();
    }

    public function test_roles_api_is_read_only(): void
    {
        $manager = $this->makeUser('Manager');
        $target = $this->makeUser('Serveur');

        // Roles figes : les routes d'ecriture n'existent plus (lecture seule)
        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}/permissions", ['roles' => [], 'permissions' => []])
            ->assertNotFound();

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/v1/roles/1/sync-permissions', ['permissions' => []])
            ->assertNotFound();

        // L'ecriture de role est impossible, meme pour un Admin
        $admin = $this->makeUser('Admin');
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/roles/1', ['name' => 'Hack'])
            ->assertStatus(405);
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/roles', ['name' => 'Hack'])
            ->assertStatus(405);
        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/roles/1')
            ->assertStatus(405);
    }

    public function test_non_admin_cannot_assign_admin_role(): void
    {
        $manager = $this->makeUser('Manager');
        $adminRole = Role::where('name', 'Admin')->first();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Escalade',
                'username' => 'escalade-'.uniqid(),
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'roles' => [$adminRole->id],
            ])
            ->assertForbidden();
    }

    public function test_invoice_store_ignores_client_subtotal(): void
    {
        $caissier = $this->makeUser('Caissier');
        $order = $this->makeOrder($caissier, 5000);

        $response = $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/invoices', [
            'order_id' => $order->id,
            'subtotal' => 1,
            'tax_rate' => 100,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'subtotal' => 5000,
        ]);
    }

    public function test_pos_sale_applies_discount_and_tip(): void
    {
        $caissier = $this->makeUser('Caissier');
        $this->openCashSessionFor($caissier);
        $item = $this->makeMenuItem(1000);

        $response = $this->actingAs($caissier, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $item->id, 'quantity' => 2, 'unit_price' => 1000]],
            'discount' => 500,
            'tip' => 200,
        ]);

        $response->assertCreated();
        $orderId = $response->json('order.id');

        // 2 × 1000 − 500 de remise
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'total' => 1500, 'discount' => 500]);
        // paiement = facture (1500 + TVA 0) + pourboire 200
        $this->assertDatabaseHas('payments', ['invoice_id' => $response->json('invoice.id'), 'amount' => 1700, 'tip_amount' => 200]);
    }

    public function test_invoice_cannot_be_paid_twice(): void
    {
        $caissier = $this->makeUser('Caissier');
        $this->openCashSessionFor($caissier);
        $order = $this->makeOrder($caissier, 1000);
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-'.uniqid(),
            'subtotal' => 1000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 1000,
            'status' => InvoiceStatus::Unpaid,
        ]);

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/invoices/{$invoice->id}/pay", ['payment_method' => 'cash', 'tip' => 100])
            ->assertOk();

        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'tip_amount' => 100]);

        $this->actingAs($caissier, 'sanctum')
            ->putJson("/api/v1/invoices/{$invoice->id}/pay", ['payment_method' => 'cash'])
            ->assertUnprocessable();
    }

    public function test_user_cannot_close_another_users_cash_session(): void
    {
        $owner = $this->makeUser('Caissier');
        $other = $this->makeUser('Caissier');

        $register = CashRegister::firstOrCreate(['name' => 'Caisse Test'], ['is_open' => true, 'current_balance' => 0]);
        $session = CashRegisterSession::create([
            'cash_register_id' => $register->id,
            'user_id' => $owner->id,
            'status' => 'open',
            'opening_amount' => 100,
            'opened_at' => now(),
        ]);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 100])
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/cash-register-sessions/{$session->id}/close", ['closing_amount' => 100])
            ->assertOk();
    }

    public function test_login_response_hides_pin_fields(): void
    {
        $user = $this->makeUser('Caissier');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('pin', $response->json('user'));
        $this->assertArrayNotHasKey('pin_hash', $response->json('user'));
    }
}
