<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\CashTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\CashRegisterWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly CashRegisterWorkflowService $cashRegisterService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string',
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page' => 'nullable|integer|min:1',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'payment_method' => ['nullable', 'string', Rule::enum(PaymentMethod::class)],
            'user_id' => 'nullable|integer',
            'client_id' => 'nullable|integer',
            'table_id' => 'nullable|integer',
            'min_total' => 'nullable|numeric|min:0',
            'max_total' => 'nullable|numeric|min:0',
            'sort' => 'nullable|string|in:created_at,invoice_number,total,status,paid_at',
            'order' => 'nullable|string|in:asc,desc',
        ]);

        /*
         * Filtres communs à la liste et aux statistiques.
         *
         * Le filtre de statut est volontairement EXCLU des stats : filtrer
         * « Impayées » ne doit pas afficher « Payées : 0 ». En revanche,
         * appliquer une période, un mode de paiement ou un caissier doit
         * déplacer les cartes du haut — elles étaient figées sur toutes les
         * factures de tous les temps alors que la liste était filtrée.
         */
        $base = Invoice::query()
            ->with(['order.table', 'order.user', 'order.orderItems.menuItem', 'client', 'payments'])
            ->when($validated['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($validated['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($validated['payment_method'] ?? null, fn ($q, $m) => $q->whereHas(
                'payments',
                fn ($p) => $p->where('payment_method', $m)
            ))
            ->when($validated['user_id'] ?? null, fn ($q, $id) => $q->whereHas('order', fn ($o) => $o->where('user_id', $id)))
            ->when($validated['table_id'] ?? null, fn ($q, $id) => $q->whereHas('order.table', fn ($t) => $t->where('tables.id', $id)))
            ->when($validated['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when(($validated['min_total'] ?? null) !== null, fn ($q) => $q->where('total', '>=', (float) $validated['min_total']))
            ->when(($validated['max_total'] ?? null) !== null, fn ($q) => $q->where('total', '<=', (float) $validated['max_total']))
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', "%{$s}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$s}%"))
                    // Chercher « Table 4 » n'avait aucun effet : le nom de la
                    // table n'était jamais interrogé.
                    ->orWhereHas('order.table', fn ($t) => $t->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('client', fn ($c) => $c->where(function ($cq) use ($s) {
                        $cq->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%");
                    }));
            }));

        $sortColumn = in_array($validated['sort'] ?? null, ['created_at', 'invoice_number', 'total', 'status', 'paid_at'], true)
            ? $validated['sort']
            : 'created_at';
        $sortOrder = ($validated['order'] ?? null) === 'asc' ? 'asc' : 'desc';

        $invoices = (clone $base)
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy($sortColumn, $sortOrder)
            ->paginate($validated['per_page'] ?? 15);

        $payload = $invoices->toArray();
        $payload['stats'] = [
            'total' => (clone $base)->count(),
            'paid_count' => (clone $base)->where('status', 'paid')->count(),
            'unpaid_count' => (clone $base)->where('status', 'unpaid')->count(),
            'total_revenue' => round((float) (clone $base)->where('status', 'paid')->sum('total'), 2),
            'total_unpaid' => round((float) (clone $base)->where('status', 'unpaid')->sum('total'), 2),
        ];
        $payload['filters'] = [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'payment_method' => $validated['payment_method'] ?? null,
            'status' => $validated['status'] ?? null,
            'search' => $validated['search'] ?? null,
        ];

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $order = Order::with('orderItems')->findOrFail($validated['order_id']);

        // Les montants sont toujours calculés côté serveur à partir de la commande.
        $subtotal = (float) $order->total;
        $taxRate = (float) Setting::get('tax_rate', 0);
        $taxAmount = round($subtotal * ($taxRate / 100), 2);

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'client_id' => $validated['client_id'] ?? $order->client_id,
            'invoice_number' => 'INV-'.str_pad((int) \DB::table('invoices')->max('id') + 1, 6, '0', STR_PAD_LEFT),
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $subtotal + $taxAmount,
            'status' => InvoiceStatus::Unpaid,
        ]);

        return response()->json(['message' => 'Facture créée.', 'invoice' => $invoice], 201);
    }

    public function show($id): JsonResponse
    {
        $invoice = Invoice::with(['order', 'client', 'payments'])->findOrFail($id);

        return response()->json(['invoice' => $invoice]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $invoice->update($validated);

        return response()->json(['message' => 'Facture mise à jour.', 'invoice' => $invoice]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        /*
         * Interdiction formelle : une facture est une piece comptable.
         * Seul un compte a ACCES COMPLET (role Admin ou perimetre
         * total des modules) peut en supprimer une — avoir le module
         * Factures ne suffit pas.
         */
        if (! $request->user()?->hasFullAccess()) {
            return response()->json([
                'message' => 'Suppression des factures réservée : seul un administrateur disposant de la totalité des modules peut la supprimer.',
            ], 403);
        }

        $invoice = Invoice::findOrFail($id);

        if ($invoice->status === InvoiceStatus::Paid || $invoice->payments()->exists()) {
            return response()->json([
                'message' => 'Facture payée ou liée à des paiements : suppression interdite. Utilisez "Marquer impayée" avec un motif pour corriger.',
            ], 422);
        }

        $invoice->delete();

        return response()->json(['message' => 'Facture supprimée.']);
    }

    public function pay(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'payment_method' => 'required|string|in:'.implode(',', array_column(PaymentMethod::cases(), 'value')),
                'tip' => 'nullable|numeric|min:0',
            ]);

            $invoice = DB::transaction(function () use ($request, $validated, $id) {
                // Verrou de ligne : empêche le double paiement en cas d'appuis concurrents.
                $invoice = Invoice::whereKey($id)->lockForUpdate()->firstOrFail();

                if ($invoice->status !== InvoiceStatus::Unpaid) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'Seules les factures impayées peuvent être marquées comme payées.',
                    ]);
                }

                // Résolue avant tout écrit : un encaissement sans caisse ouverte
                // échoue entrement plutôt que d'être perdu du tableau de caisse.
                $openSession = $this->cashRegisterService->resolveOpenSessionForSale($request->user()->id);

                $tip = round((float) ($validated['tip'] ?? 0), 2);

                $payment = Payment::create([
                    'invoice_id' => $invoice->id,
                    'user_id' => $request->user()->id,
                    'amount' => (float) $invoice->total + $tip,
                    'tip_amount' => $tip,
                    'payment_method' => $validated['payment_method'],
                ]);

                $invoice->update([
                    'status' => InvoiceStatus::Paid,
                    'paid_at' => now(),
                ]);

                $this->cashRegisterService->recordEncaissement(
                    session: $openSession,
                    amount: (float) $payment->amount,
                    userId: $request->user()->id,
                    paymentMethod: (string) $validated['payment_method'],
                    reason: "Facture {$invoice->invoice_number} payée",
                    referenceId: $payment->id,
                    referenceType: Payment::class,
                );

                if ($invoice->client_id) {
                    $this->incrementClientLoyalty($invoice->client_id, (float) $payment->amount);
                }

                return $invoice->fresh();
            });

            $invoice->refresh()->load('payments');

            return response()->json([
                'message' => 'Facture payée.',
                'invoice' => $invoice,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Erreur de validation.', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function incrementClientLoyalty(int $clientId, float $amount): void
    {
        $client = Client::find($clientId);
        if (! $client) {
            return;
        }

        $client->increment('visits');
        $client->increment('total_spent', $amount);
        $client->increment('loyalty_points', (int) floor($amount / 1000));
    }

    /**
     * Annule exactement ce qu'a crédité `incrementClientLoyalty`.
     *
     * Les points sont retirés paiement par paiement : la somme des arrondis
     * à l'unité n'est pas égale à l'arrondi de la somme.
     */
    private function decrementClientLoyalty(?int $clientId, float $amount, int $visits, int $points): void
    {
        if (! $clientId) {
            return;
        }

        $client = Client::find($clientId);
        if (! $client) {
            return;
        }

        $client->decrement('visits', $visits);
        $client->decrement('loyalty_points', $points);
        $client->total_spent = max(0, (float) $client->total_spent - $amount);
        $client->save();
    }

    public function unpay($id): JsonResponse
    {
        $invoice = Invoice::with('payments')->findOrFail($id);

        DB::transaction(function () use ($invoice) {
            $payments = $invoice->payments()->get();

            if ($payments->isEmpty()) {
                throw ValidationException::withMessages([
                    'invoice' => 'Cette facture n\'a aucun paiement à annuler.',
                ]);
            }

            $cashTransactions = CashTransaction::where('reference_type', Payment::class)
                ->where('reference_id', $payments->pluck('id'))
                ->get();

            // Annuler un encaissement retire la monnaie du tiroir : sur une
            // caisse déjà fermée, l'historique de clôture serait réécrit.
            $closedSession = $cashTransactions->first(
                fn (CashTransaction $t) => $t->cashRegisterSession?->status !== CashRegisterSessionStatus::Open
            );

            if ($closedSession) {
                throw ValidationException::withMessages([
                    'invoice' => 'La caisse liée à ce paiement est fermée : annulation impossible.',
                ]);
            }

            $cashTransactions->each->delete();
            $payments->each->delete();

            $invoice->update(['status' => InvoiceStatus::Unpaid, 'paid_at' => null]);

            $this->decrementClientLoyalty(
                clientId: $invoice->client_id,
                amount: (float) $payments->sum('amount'),
                visits: $payments->count(),
                points: (int) $payments->sum(fn (Payment $p) => floor((float) $p->amount / 1000)),
            );
        });

        $invoice->refresh();

        return response()->json(['message' => 'Facture impayée.', 'status' => $invoice->status->value]);
    }
}
