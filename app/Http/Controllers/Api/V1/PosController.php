<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\CashRegisterWorkflowService;
use App\Services\StockLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function __construct(
        private readonly CashRegisterWorkflowService $cashRegisterService
    ) {}

    public function sale(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'table_id' => 'nullable|exists:tables,id',
                'payment_method' => 'required|string|in:'.implode(',', array_column(PaymentMethod::cases(), 'value')),
                'items' => 'required|array|min:1',
                'items.*.menu_item_id' => 'required|exists:menu_items,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'notes' => 'nullable|string',
                'discount' => 'nullable|numeric|min:0',
                'tip' => 'nullable|numeric|min:0',
                'client_id' => 'nullable|exists:clients,id',
            ]);

            $result = DB::transaction(function () use ($request, $validated) {
                $user = $request->user();

                // Résolue en amont : si aucune caisse n'est ouverte, la vente
                // échoue entièrement plutôt que d'être encaissée sans être
                // portée au tableau de caisse.
                $openSession = $this->cashRegisterService->resolveOpenSessionForSale($user->id);

                $menuItemIds = array_map('intval', array_column($validated['items'], 'menu_item_id'));
                $menuItems = MenuItem::whereIn('id', $menuItemIds)->get()->keyBy('id');

                foreach ($validated['items'] as $item) {
                    $menuItem = $menuItems->get($item['menu_item_id']);
                    if ($menuItem && abs((float) $item['unit_price'] - (float) $menuItem->price) > 0.01) {
                        throw ValidationException::withMessages([
                            'items' => "Le prix de \"{$menuItem->name}\" ne correspond pas au catalogue.",
                        ]);
                    }
                }

                $itemsTotal = collect($validated['items'])->sum(fn ($item) => $item['unit_price'] * $item['quantity']);
                $discount = min((float) ($validated['discount'] ?? 0), $itemsTotal);
                $tip = round((float) ($validated['tip'] ?? 0), 2);
                $orderNumber = $this->generateOrderNumber();

                $order = Order::create([
                    'order_number' => $orderNumber,
                    'table_id' => $validated['table_id'] ?? null,
                    'client_id' => $validated['client_id'] ?? null,
                    'user_id' => $user->id,
                    'status' => OrderStatus::Served,
                    'notes' => $validated['notes'] ?? null,
                    'total' => $itemsTotal - $discount,
                    'discount' => $discount,
                ]);

                foreach ($validated['items'] as $item) {
                    $order->orderItems()->create([
                        'menu_item_id' => $item['menu_item_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['unit_price'] * $item['quantity'],
                    ]);
                }

                $taxRate = (float) Setting::get('tax_rate', 0);
                $subtotal = $itemsTotal - $discount;
                $taxAmount = round($subtotal * ($taxRate / 100), 2);
                $invoiceNumber = 'INV-'.str_pad((int) \DB::table('invoices')->max('id') + 1, 6, '0', STR_PAD_LEFT);

                $invoice = Invoice::create([
                    'order_id' => $order->id,
                    'client_id' => $validated['client_id'] ?? null,
                    'invoice_number' => $invoiceNumber,
                    'subtotal' => $subtotal,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'total' => $subtotal + $taxAmount,
                    'status' => InvoiceStatus::Paid,
                    'paid_at' => now(),
                ]);

                $payment = Payment::create([
                    'invoice_id' => $invoice->id,
                    'user_id' => $user->id,
                    'amount' => (float) $invoice->total + $tip,
                    'tip_amount' => $tip,
                    'payment_method' => $validated['payment_method'],
                ]);

                $this->cashRegisterService->recordEncaissement(
                    session: $openSession,
                    amount: (float) $payment->amount,
                    userId: $user->id,
                    paymentMethod: (string) $validated['payment_method'],
                    reason: "Vente POS #{$orderNumber}",
                    referenceId: $payment->id,
                    referenceType: Payment::class,
                );

                if ($order->client_id) {
                    $client = Client::find($order->client_id);
                    if ($client) {
                        $client->increment('visits');
                        $client->increment('total_spent', (float) $payment->amount);
                        $client->increment('loyalty_points', (int) floor((float) $payment->amount / 1000));
                    }
                }

                if ($order->table) {
                    $order->table->update(['status' => 'available']);
                }

                $order->load(['table', 'orderItems.menuItem']);
                $invoice->load('payments');

                // Consommation des ingrédients (recettes) liés aux articles vendus.
                app(StockLedgerService::class)
                    ->deductForOrder($order, "Vente POS #{$orderNumber}");

                return [
                    'order' => $order,
                    'invoice' => $invoice,
                ];
            });

            return response()->json([
                'message' => 'Vente enregistrée.',
                'order' => $result['order'],
                'invoice' => $result['invoice'],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la vente: '.$e->getMessage()], 500);
        }
    }

    private function generateOrderNumber(): string
    {
        $lastNumber = (int) \DB::table('orders')->max('id');

        return 'ORD-'.str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
    }
}
