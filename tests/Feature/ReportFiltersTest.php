<?php

namespace Tests\Feature;

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
 * Recoupements du rapport financier.
 *
 * Un rapport qui ne se laisse filtrer ni par mode de paiement ni par agent ne
 * répond à aucune des deux questions d'exploitation : « quelle part du CA
 * vient d'Airtel Money ? » et « combien chaque caissier a-t-il pris ? ». Le
 * second point est aussi un outil de réconciliation : face à un écart de
 * caisse, il faut rattacher l'argent à une personne.
 */
class ReportFiltersTest extends TestCase
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

    private function makeUser(string $role = 'Caissier'): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

        $user = User::create([
            'name' => 'Agent '.uniqid(),
            'username' => 'a-'.uniqid(),
            'email' => 'a-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user;
    }

    private function makeSale(
        User $user,
        float $total,
        string $method = 'cash',
        float $tip = 0,
        ?\DateTimeInterface $at = null,
        string $orderStatus = 'served'
    ): Invoice {
        $at ??= now();

        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $user->id,
            'status' => $orderStatus,
            'total' => $total,
        ]);

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-'.uniqid(),
            'subtotal' => $total,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $total,
            'status' => 'paid',
        ]);

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'amount' => $total + $tip,
            'tip_amount' => $tip,
            'payment_method' => $method,
        ]);

        Order::whereKey($order->id)->update(['created_at' => $at]);
        Invoice::whereKey($invoice->id)->update(['created_at' => $at]);
        Payment::whereKey($payment->id)->update(['created_at' => $at]);

        return $invoice->refresh();
    }

    private function report(User $user, array $query = []): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/financial'.($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->json();
    }

    public function test_filtering_by_payment_method_narrows_every_section(): void
    {
        $admin = $this->makeUser('Admin');
        $agent = $this->makeUser('Caissier');

        $this->makeSale($agent, 5000, 'cash');
        $this->makeSale($agent, 7000, 'airtel_money');
        $this->makeSale($agent, 9000, 'airtel_money');

        $all = $this->report($admin);
        $this->assertSame(21000.0, (float) $all['summary']['total_revenue']);
        $this->assertSame(3, (int) $all['summary']['total_orders']);

        $airtel = $this->report($admin, ['payment_method' => 'airtel_money']);

        // Chaque section doit raconter la même histoire : si le CA tombe à la
        // part d'Airtel Money, les commandes et les articles doivent tomber
        // avec lui — sinon deux cartes du rapport se contredisent.
        $this->assertSame(16000.0, (float) $airtel['summary']['total_revenue']);
        $this->assertSame(2, (int) $airtel['summary']['total_orders']);
        $this->assertSame(2, (int) $airtel['summary']['payment_count']);
        $this->assertCount(1, $airtel['payments_by_method']);
        $this->assertArrayHasKey('airtel_money', $airtel['payments_by_method']);
        $this->assertSame(16000.0, (float) $airtel['payments_by_method']['airtel_money']['total']);
        $this->assertSame(16000.0, round((float) array_sum($airtel['daily_revenue']), 2));
        $this->assertSame(2, (int) array_sum($airtel['daily_orders']));
        $this->assertSame(
            16000.0,
            (float) array_sum($airtel['hourly_revenue'])
        );

        $this->assertSame('airtel_money', $airtel['period']['payment_method']);
    }

    public function test_filtering_by_user_isolates_one_agent(): void
    {
        $admin = $this->makeUser('Admin');
        $alice = $this->makeUser('Caissier');
        $bob = $this->makeUser('Caissier');

        $this->makeSale($alice, 3000, 'cash', 500);
        $this->makeSale($alice, 4000, 'cash');
        $this->makeSale($bob, 8000, 'card');

        $aliceReport = $this->report($admin, ['user_id' => $alice->id]);

        $this->assertSame(7000.0, (float) $aliceReport['summary']['total_revenue']);
        $this->assertSame(7500.0, (float) $aliceReport['summary']['total_collected']);
        $this->assertSame(500.0, (float) $aliceReport['summary']['total_tips']);
        $this->assertSame(2, (int) $aliceReport['summary']['total_orders']);
        $this->assertSame([$alice->id], array_column($aliceReport['revenue_by_user'], 'user_id'));

        $bobReport = $this->report($admin, ['user_id' => $bob->id]);
        $this->assertSame(8000.0, (float) $bobReport['summary']['total_revenue']);
        $this->assertSame(1, (int) $bobReport['summary']['total_orders']);
    }

    public function test_revenue_by_user_is_ranked_and_carries_the_tips(): void
    {
        $admin = $this->makeUser('Admin');
        $small = $this->makeUser('Caissier');
        $big = $this->makeUser('Caissier');

        $this->makeSale($small, 1000, 'cash', 200);
        $this->makeSale($big, 12000, 'cash', 1000);

        $report = $this->report($admin);
        $byUser = collect($report['revenue_by_user']);

        $this->assertCount(2, $byUser);
        // Classé par CA décroissant : c'est le classement qu'on lit.
        $this->assertSame($big->id, $byUser->first()['user_id']);
        $this->assertSame(12000.0, (float) $byUser->first()['revenue']);
        $this->assertSame(1000.0, (float) $byUser->first()['tips']);
        $this->assertSame(13000.0, (float) $byUser->first()['collected']);
        $this->assertSame(200.0, (float) $byUser->last()['tips']);

        // La somme des agents doit retomber sur le total, sans double compte.
        $this->assertSame(
            (float) $report['summary']['total_revenue'],
            round((float) $byUser->sum('revenue'), 2)
        );
        $this->assertSame(
            (int) $report['summary']['payment_count'],
            (int) $byUser->sum('count')
        );
    }

    public function test_hourly_revenue_is_a_dense_twenty_four_hour_series(): void
    {
        $admin = $this->makeUser('Admin');
        $agent = $this->makeUser('Caissier');

        $this->makeSale($agent, 2000, 'cash', 0, now()->setTime(21, 15));
        $this->makeSale($agent, 3000, 'cash', 0, now()->setTime(21, 45));
        $this->makeSale($agent, 1000, 'cash', 0, now()->setTime(12, 5));

        $hourly = $this->report($admin)['hourly_revenue'];

        // 24 cases, y compris les vides : une série creuse donne un graphique
        // illisible et interdit tout calcul de pointe.
        $this->assertCount(24, $hourly);
        $this->assertSame(
            array_map(fn (int $h) => str_pad((string) $h, 2, '0', STR_PAD_LEFT), range(0, 23)),
            // Les clés « 10 » à « 23 » sont converties en entiers par PHP,
            // on les re-normalise avant toute comparaison.
            array_map('strval', array_keys($hourly))
        );

        $this->assertSame(5000.0, (float) $hourly['21']);
        $this->assertSame(1000.0, (float) $hourly['12']);
        $this->assertSame(0.0, (float) $hourly['03']);

        $this->assertSame(
            (float) $this->report($admin)['summary']['total_revenue'],
            round((float) array_sum($hourly), 2)
        );
    }

    public function test_an_unknown_payment_method_is_rejected(): void
    {
        $admin = $this->makeUser('Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/financial?payment_method=cheque')
            ->assertUnprocessable();
    }
}
