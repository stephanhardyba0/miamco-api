<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Définition unique du chiffre d'affaires.
 *
 * Le logiciel affichait trois chiffres différents pour une même journée :
 * le tableau de bord additionnait `SUM(amount)` — pourboire compris — le
 * rapport financier l'excluait, et la caisse ne comptait que les espèces.
 * Un manager qui rapprochait les trois écrans concluait à une erreur de
 * caisse là où il n'y en avait aucune.
 */
class RevenueDefinitionTest extends TestCase
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

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

        $user = User::create([
            'name' => $roleName.' User',
            'username' => 'u-'.uniqid(),
            'email' => 'u-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeInvoice(User $user, float $total, \DateTimeInterface $createdAt): Invoice
    {
        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $user->id,
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
            'status' => InvoiceStatus::Paid->value,
        ]);

        Invoice::whereKey($invoice->id)->update(['created_at' => $createdAt]);

        return $invoice->refresh();
    }

    private function makePayment(User $user, Invoice $invoice, float $amount, float $tip, \DateTimeInterface $at): Payment
    {
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'amount' => $amount,
            'tip_amount' => $tip,
            'payment_method' => 'cash',
        ]);

        Payment::whereKey($payment->id)->update(['created_at' => $at]);

        return $payment->refresh();
    }

    private function dashboard(User $user): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard?refresh=1')
            ->assertOk()
            ->json();
    }

    private function report(User $user, string $start, string $end): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/reports/financial?start_date={$start}&end_date={$end}")
            ->assertOk()
            ->json();
    }

    public function test_dashboard_and_report_give_the_same_revenue_for_one_day(): void
    {
        $user = $this->makeUser('Admin');
        $invoice = $this->makeInvoice($user, 4000, now());
        $this->makePayment($user, $invoice, 4500, 500, now());

        $today = now()->toDateString();

        $dashboard = $this->dashboard($user);
        $report = $this->report($user, $today, $today);

        // L'invariant : deux écrans, un seul chiffre.
        $this->assertSame(
            (float) $report['summary']['total_revenue'],
            (float) $dashboard['today']['revenue'],
            'Le tableau de bord et le rapport financier affichent deux CA différents.'
        );

        $this->assertSame(4000.0, (float) $dashboard['today']['revenue']);
        $this->assertSame(500.0, (float) $dashboard['today']['tips']);
        $this->assertSame(4500.0, (float) $dashboard['today']['collected']);
        $this->assertSame(1, (int) $dashboard['today']['payments']);
        $this->assertSame(4000.0, (float) $dashboard['today']['avg_basket']);
    }

    public function test_dashboard_revenue_excludes_tips(): void
    {
        $user = $this->makeUser('Admin');
        $invoice = $this->makeInvoice($user, 3000, now());
        // `payments.amount` = total de la facture + pourboire.
        $this->makePayment($user, $invoice, 4000, 1000, now());

        $today = $this->dashboard($user)['today'];

        // Avant correction, `revenue` valait 4000 — le pourboire était dans le
        // CA du jour, puis affiché une seconde fois en pourboire.
        $this->assertSame(3000.0, (float) $today['revenue']);
        $this->assertSame(4000.0, (float) $today['collected']);
        $this->assertSame(
            (float) $today['revenue'] + (float) $today['tips'],
            (float) $today['collected']
        );
    }

    public function test_avg_basket_is_based_on_payments_not_orders(): void
    {
        $user = $this->makeUser('Admin');

        $a = $this->makeInvoice($user, 2000, now());
        $this->makePayment($user, $a, 2000, 0, now());

        $b = $this->makeInvoice($user, 4000, now());
        $this->makePayment($user, $b, 4000, 0, now());

        // Commande non réglée : elle ne doit pas faire baisser le panier.
        $this->makeInvoice($user, 9000, now());

        $today = $this->dashboard($user)['today'];

        $this->assertSame(3, (int) $today['orders']);
        $this->assertSame(2, (int) $today['payments']);
        $this->assertSame(3000.0, (float) $today['avg_basket']);
    }

    public function test_a_sale_at_half_past_midnight_counts_on_the_right_day(): void
    {
        $user = $this->makeUser('Admin');
        $invoice = $this->makeInvoice($user, 7000, now()->startOfDay()->addMinutes(30));
        $this->makePayment($user, $invoice, 7000, 0, now()->startOfDay()->addMinutes(30));

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // En UTC, 00:30 heure locale tombait la veille : la vente quittait les
        // « ventes du jour » du caissier.
        $this->assertSame(
            7000.0,
            (float) $this->report($user, $today, $today)['summary']['total_revenue']
        );
        $this->assertSame(
            0.0,
            (float) $this->report($user, $yesterday, $yesterday)['summary']['total_revenue'],
            'Une vente de 00h30 est restée comptée la veille.'
        );
        $this->assertSame(7000.0, (float) $this->dashboard($user)['today']['revenue']);
    }

    public function test_cashier_can_read_reports_but_cook_cannot(): void
    {
        $cashier = $this->makeUser('Caissier');
        $cook = $this->makeUser('Cuisinier');

        // Un caissier doit pouvoir vérifier ses propres ventes du jour.
        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertOk();

        $this->actingAs($cook, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertForbidden();

        // Le droit dédié ne rouvre rien d'autre : la cuisine reste hors des
        // rôles, permissions et de la configuration.
        $this->actingAs($cook, 'sanctum')
            ->getJson('/api/v1/permissions')
            ->assertForbidden();
    }
}
