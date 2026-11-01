<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtrage des factures.
 *
 * La page Factures n'avait que « recherche + 3 statuts ». Les statistiques du
 * haut, elles, étaient calculées sur TOUTES les factures de tous les temps :
 * filtrer une période changeait la liste mais pas les cartes, et l'en-tête
 * affichait « 20/10/2026 - 1 234 facture(s) » laissant croire à un compteur
 * du jour.
 */
class InvoiceFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = [
            'orders' => ['view', 'create', 'edit', 'delete'],
            'invoices' => ['view', 'create', 'edit', 'delete'],
            'cash_registers' => ['view', 'create', 'edit', 'delete'],
            'menu' => ['view', 'create', 'edit', 'delete'],
            'settings' => ['view', 'edit'],
            'reports' => ['view'],
            'pos' => ['view', 'create'],
            'clients' => ['view', 'create'],
            'stock' => ['view', 'create', 'edit'],
            'tables' => ['view'],
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

    private function makeUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'Caissier', 'guard_name' => 'web']);

        $user = User::create([
            'name' => 'Filtre User',
            'username' => 'f-'.uniqid(),
            'email' => 'f-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeInvoice(
        User $user,
        float $total,
        string $status = 'unpaid',
        ?\DateTimeInterface $createdAt = null,
        ?string $paymentMethod = null,
        ?int $tableId = null
    ): Invoice {
        $createdAt ??= now();

        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $user->id,
            'table_id' => $tableId,
            'status' => 'served',
            'total' => $total,
        ]);

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-'.uniqid(),
            'subtotal' => $total,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $total,
            'status' => $status,
        ]);

        Invoice::whereKey($invoice->id)->update(['created_at' => $createdAt]);

        if ($paymentMethod) {
            Payment::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'amount' => $total,
                'tip_amount' => 0,
                'payment_method' => $paymentMethod,
            ]);
        }

        return $invoice->refresh();
    }

    private function index(User $user, array $query = []): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/invoices'.($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->json();
    }

    public function test_date_range_filters_both_the_list_and_the_stats(): void
    {
        $user = $this->makeUser();
        $this->makeInvoice($user, 1000, 'unpaid', now()->subDays(10));
        $this->makeInvoice($user, 2000, 'unpaid', now()->subDays(1));
        $this->makeInvoice($user, 3000, 'unpaid', now()->subDay());

        $payload = $this->index($user, [
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        // La liste et les cartes doivent partager le même périmètre.
        $this->assertCount(2, $payload['data']);
        $this->assertSame(2, (int) $payload['stats']['total']);
        $this->assertSame(5000.0, (float) $payload['stats']['total_unpaid']);
    }

    public function test_status_filter_does_not_zero_the_other_cards(): void
    {
        $user = $this->makeUser();
        $this->makeInvoice($user, 1000, 'paid', now(), 'cash');
        $this->makeInvoice($user, 2500, 'unpaid', now());

        $payload = $this->index($user, ['status' => 'unpaid']);

        $this->assertCount(1, $payload['data']);
        // Filtrer « Impayées » ne doit pas afficher « Payées : 0 » ni vider
        // le chiffre d'affaires encaissé.
        $this->assertSame(2, (int) $payload['stats']['total']);
        $this->assertSame(1, (int) $payload['stats']['paid_count']);
        $this->assertSame(1, (int) $payload['stats']['unpaid_count']);
        $this->assertSame(1000.0, (float) $payload['stats']['total_revenue']);
    }

    public function test_invoices_can_be_filtered_by_payment_method(): void
    {
        $user = $this->makeUser();
        $this->makeInvoice($user, 5000, 'paid', now(), 'cash');
        $this->makeInvoice($user, 6000, 'paid', now(), 'airtel_money');
        $this->makeInvoice($user, 7000, 'unpaid', now());

        $payload = $this->index($user, ['payment_method' => 'airtel_money']);

        $this->assertCount(1, $payload['data']);
        $this->assertSame(6000.0, (float) $payload['data'][0]['total']);

        $cash = $this->index($user, ['payment_method' => 'cash']);
        $this->assertCount(1, $cash['data']);
        $this->assertSame(5000.0, (float) $cash['data'][0]['total']);

        // La facture impayée, sans aucun règlement, n'appartient à aucun mode.
        $this->assertSame(0, (int) $this->index($user, ['payment_method' => 'transfer'])['stats']['total']);
        $this->assertSame(3, (int) $this->index($user)['stats']['total']);
    }

    public function test_search_matches_the_table_name(): void
    {
        $user = $this->makeUser();
        $table = Table::firstOrCreate(['name' => 'Table '.uniqid()]);
        $this->makeInvoice($user, 1000, 'paid', now(), 'cash', $table->id);
        $this->makeInvoice($user, 2000, 'paid', now(), 'cash');

        $payload = $this->index($user, ['search' => $table->name]);

        $this->assertCount(1, $payload['data']);
        $this->assertSame($table->name, $payload['data'][0]['order']['table']['name'] ?? null);
    }

    public function test_sorting_is_whitelisted(): void
    {
        $user = $this->makeUser();
        $this->makeInvoice($user, 1000, 'paid', now()->subDays(3), 'cash');
        $this->makeInvoice($user, 9000, 'paid', now()->subDays(1), 'cash');

        $asc = $this->index($user, ['sort' => 'total', 'order' => 'asc']);
        $desc = $this->index($user, ['sort' => 'total', 'order' => 'desc']);

        $this->assertSame(1000.0, (float) $asc['data'][0]['total']);
        $this->assertSame(9000.0, (float) $desc['data'][0]['total']);

        // Une colonne hors liste blanche est rejetée : rien n'est injecté
        // dans l'ordre SQL.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/invoices?sort=evil_column')
            ->assertUnprocessable();

        $this->assertDatabaseHas('invoices', ['id' => Invoice::first()->id]);
    }

    public function test_an_unknown_payment_method_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/invoices?payment_method=bitcoin')
            ->assertUnprocessable();
    }
}
