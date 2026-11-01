<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceLockdownTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $roleName): User
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'invoices.view'],
            ['module' => 'invoices', 'action' => 'view', 'guard_name' => 'web']
        );
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $role->permissions()->sync([$permission->id]);

        $user = User::create([
            'name' => $roleName.' User',
            'username' => strtolower($roleName).'-'.uniqid(),
            'email' => strtolower($roleName).'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeOrder(User $user): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'served',
            'total' => 1000,
        ]);
    }

    private function makeInvoice(Order $order, InvoiceStatus $status, bool $withPayment = false): Invoice
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-'.uniqid(),
            'order_id' => $order->id,
            'subtotal' => 1000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 1000,
            'status' => $status,
        ]);

        if ($withPayment) {
            Payment::create([
                'invoice_id' => $invoice->id,
                'user_id' => $order->user_id,
                'amount' => 1000,
                'payment_method' => 'cash',
            ]);
        }

        return $invoice;
    }

    public function test_paid_invoice_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('Admin');
        $invoice = $this->makeInvoice($this->makeOrder($admin), InvoiceStatus::Paid, withPayment: true);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/invoices/{$invoice->id}");

        $response->assertStatus(422);
        $this->assertNull(Invoice::withTrashed()->find($invoice->id)->deleted_at);
    }

    public function test_unpaid_invoice_without_payments_can_be_deleted(): void
    {
        $admin = $this->makeUser('Admin');
        $invoice = $this->makeInvoice($this->makeOrder($admin), InvoiceStatus::Unpaid);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk();
        $this->assertNotNull(Invoice::withTrashed()->find($invoice->id)->deleted_at);
    }

    public function test_caissier_cannot_delete_invoices(): void
    {
        $caissier = $this->makeUser('Caissier');
        $invoice = $this->makeInvoice($this->makeOrder($caissier), InvoiceStatus::Unpaid);

        $response = $this->actingAs($caissier, 'sanctum')->deleteJson("/api/v1/invoices/{$invoice->id}");

        $response->assertForbidden();
    }

    public function test_restore_command_restores_soft_deleted_financial_rows(): void
    {
        $admin = $this->makeUser('Admin');
        $invoice = $this->makeInvoice($this->makeOrder($admin), InvoiceStatus::Paid, withPayment: true);

        $paymentId = $invoice->payments()->first()->id;
        $invoice->payments()->delete();
        $invoice->delete();

        $this->artisan('finance:restore-deleted')->assertSuccessful();
        $this->assertNotNull(Invoice::withTrashed()->find($invoice->id)->deleted_at);

        $this->artisan('finance:restore-deleted --force')->assertSuccessful();
        $this->assertNull(Invoice::withTrashed()->find($invoice->id)->deleted_at);
        $this->assertNull(Payment::withTrashed()->find($paymentId)->deleted_at);
    }
}
