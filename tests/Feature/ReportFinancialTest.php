<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Invoice;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rapport financier.
 *
 * Le rapport n'avait aucun test : ses chiffres étaient incohérents entre
 * eux (les lignes journalières ne sommaient jamais le total) parce que le
 * CA était rattaché à la création de la facture tandis que le détail était
 * groupé sur la date de paiement.
 */
class ReportFinancialTest extends TestCase
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
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $user = User::create([
            'name' => 'Report User',
            'username' => 'rp-'.uniqid(),
            'email' => 'rp-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeInvoice(User $user, float $total, \DateTimeInterface $createdAt, InvoiceStatus $status = InvoiceStatus::Paid): Invoice
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
            'status' => $status,
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

    private function report(User $user, string $start, string $end): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/reports/financial?start_date={$start}&end_date={$end}")
            ->assertOk()
            ->json();
    }

    public function test_daily_revenue_sums_exactly_to_total_revenue(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($user, 5000, now()->subDays(2));

        $this->makePayment($user, $invoice, 5000, 0, now()->subDays(2));
        $this->makePayment($user, $invoice, 3000, 0, now()->subDay());

        $report = $this->report($user, now()->startOfMonth()->toDateString(), now()->toDateString());

        // L'invariant d'équilibrage : le détail journalière doit sommer le total.
        $this->assertSame(
            round((float) $report['summary']['total_revenue'], 2),
            round((float) array_sum($report['daily_revenue']), 2)
        );
        $this->assertSame(8000.0, (float) $report['summary']['total_revenue']);
    }

    public function test_revenue_is_anchored_on_the_payment_not_the_invoice(): void
    {
        $user = $this->makeUser();

        // Facture créée avant la période, payée pendant : elle appartient à
        // la période de paiement.
        $old = $this->makeInvoice($user, 10000, now()->subMonths(2));
        $this->makePayment($user, $old, 10000, 0, now()->subDay());

        // Facture créée pendant la période, payée après : elle n'appartient
        // à aucune période tant qu'elle n'est pas réglée.
        $future = $this->makeInvoice($user, 7000, now()->subDay(), InvoiceStatus::Unpaid);

        $report = $this->report($user, now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(10000.0, (float) $report['summary']['total_revenue']);
        $this->assertSame(7000.0, (float) $report['summary']['total_unpaid']);
        $this->assertSame(0.0, (float) $report['summary']['total_tips']);
    }

    public function test_tips_are_reported_separately_from_revenue(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($user, 4000, now()->subDay());
        $this->makePayment($user, $invoice, 4500, 500, now()->subDay());

        $report = $this->report($user, now()->startOfMonth()->toDateString(), now()->toDateString());

        // Le pourboire n'est pas du chiffre d'affaires : l'afficher en CA et
        // à part le comptait deux fois.
        $this->assertSame(4000.0, (float) $report['summary']['total_revenue']);
        $this->assertSame(500.0, (float) $report['summary']['total_tips']);
        $this->assertSame(4500.0, (float) $report['summary']['total_collected']);
        $this->assertSame(
            (float) $report['summary']['total_revenue'] + (float) $report['summary']['total_tips'],
            (float) $report['summary']['total_collected']
        );
    }

    public function test_avg_basket_uses_the_same_base_as_revenue(): void
    {
        $user = $this->makeUser();

        $a = $this->makeInvoice($user, 2000, now()->subDay());
        $this->makePayment($user, $a, 2000, 0, now()->subDay());

        $b = $this->makeInvoice($user, 4000, now()->subDay());
        $this->makePayment($user, $b, 4000, 0, now()->subDay());

        // Une commande non réglée ne doit pas diluer le panier moyen.
        $this->makeInvoice($user, 9000, now()->subDay(), InvoiceStatus::Unpaid);

        $report = $this->report($user, now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(2, (int) $report['summary']['payment_count']);
        $this->assertSame(3000.0, (float) $report['summary']['avg_basket']);
    }

    public function test_top_selling_items_excludes_pending_and_cancelled_orders(): void
    {
        $user = $this->makeUser();
        $category = MenuCategory::create(['slug' => 'cat-'.uniqid(), 'name' => 'Cat']);
        $dish = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Plat secret',
            'slug' => 'plat-'.uniqid(),
            'price' => 1000,
            'is_available' => true,
        ]);

        $sold = Order::create(['order_number' => 'ORD-'.uniqid(), 'user_id' => $user->id, 'status' => 'served', 'total' => 2000]);
        $sold->orderItems()->create(['menu_item_id' => $dish->id, 'quantity' => 2, 'unit_price' => 1000, 'total_price' => 2000]);

        $pending = Order::create(['order_number' => 'ORD-'.uniqid(), 'user_id' => $user->id, 'status' => 'pending', 'total' => 5000]);
        $pending->orderItems()->create(['menu_item_id' => $dish->id, 'quantity' => 5, 'unit_price' => 1000, 'total_price' => 5000]);

        $cancelled = Order::create(['order_number' => 'ORD-'.uniqid(), 'user_id' => $user->id, 'status' => 'cancelled', 'total' => 3000]);
        $cancelled->orderItems()->create(['menu_item_id' => $dish->id, 'quantity' => 3, 'unit_price' => 1000, 'total_price' => 3000]);

        $report = $this->report($user, now()->startOfMonth()->toDateString(), now()->toDateString());

        // Seules les ventes effectives entrent au classement.
        $this->assertSame(2, (int) $report['top_selling_items'][0]['total_quantity']);
        $this->assertSame(2000.0, (float) $report['top_selling_items'][0]['total_revenue']);
    }

    public function test_sessions_report_the_real_expected_balance_and_discrepancy(): void
    {
        $user = $this->makeUser();
        $register = CashRegister::create(['name' => 'Caisse Principale', 'is_open' => true, 'current_balance' => 0]);

        $session = CashRegisterSession::create([
            'cash_register_id' => $register->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opening_amount' => 500,
            'opened_at' => now(),
        ]);

        $session->transactions()->create([
            'user_id' => $user->id,
            'type' => 'sale',
            'amount' => 1000,
            'payment_method' => 'cash',
        ]);

        $report = $this->report($user, now()->startOfMonth()->toDateString(), now()->toDateString());
        $reported = collect($report['sessions'])->firstWhere('id', $session->id);

        $this->assertSame(1500.0, (float) $reported['expected_balance']);
        $this->assertNull($reported['discrepancy'], 'Une session encore ouverte ne peut pas avoir d\'écart.');

        CashRegisterSession::whereKey($session->id)->update(['status' => 'closed', 'closing_amount' => 1400, 'closed_at' => now()]);

        $reported = collect($this->report($user, now()->startOfMonth()->toDateString(), now()->toDateString())['sessions'])->firstWhere('id', $session->id);

        $this->assertSame(1500.0, (float) $reported['expected_balance']);
        $this->assertSame(-100.0, (float) $reported['discrepancy']);
    }
}
