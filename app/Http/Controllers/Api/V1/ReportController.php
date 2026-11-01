<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterSession;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CashRegisterWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(
        private readonly CashRegisterWorkflowService $cashRegisterService
    ) {}

    public function financial(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'payment_method' => ['nullable', 'string', Rule::enum(PaymentMethod::class)],
                'user_id' => 'nullable|integer',
            ]);

            $startDate = $request->start_date ? now()->parse($request->start_date)->startOfDay() : now()->startOfMonth()->startOfDay();
            $endDate = $request->end_date ? now()->parse($request->end_date)->endOfDay() : now()->endOfDay();

            // Deux recoupements indispensables à l'exploitation : quel mode de
            // paiement compose le CA, et combien chaque agent a encaissé.
            $method = $request->filled('payment_method') ? $request->string('payment_method')->toString() : null;
            $userId = $request->filled('user_id') ? (int) $request->input('user_id') : null;

            /*
             * Factures de la période, recoupées par agent et par mode de
             * paiement. Filtrer « Airtel Money » laissait sinon les factures
             * intactes pendant que le CA retombait à la part de ce mode, et
             * deux cartes du rapport se contredisaient.
             */
            $invoiceQuery = fn () => Invoice::query()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($userId, fn ($iq, $id) => $iq->whereHas('order', fn ($o) => $o->where('user_id', $id)))
                ->when($method, fn ($iq, $m) => $iq->whereHas('payments', fn ($p) => $p->where('payment_method', $m)));

            /*
             * Commandes réglées par le mode de paiement demandé. Réutilisé pour
             * le comptage et pour le classement des articles, sans quoi les
             * deux ne portaient pas sur le même ensemble.
             */
            $paidByMethod = function ($q) use ($method) {
                $q->whereIn('orders.id', function ($sub) use ($method) {
                    $sub->select('invoices.order_id')
                        ->from('invoices')
                        ->whereIn('invoices.id', function ($inner) use ($method) {
                            $inner->select('invoice_id')->from('payments')->where('payment_method', $method);
                        });
                });
            };

            /*
             * Base unique du rapport : le paiement, c'est-à-dire le moment où
             * l'argent a réellement bougé.
             *
             * Rattacher le chiffre d'affaires à la date de création de la
             * facture décalait le CA d'un mois à l'autre : une facture créée
             * le 30 et payée le 5 appartenait au mois précédent, et une
             * facture créée avant la période puis payée dedans n'apparaissait
             * nulle part. `daily_revenue`, groupé sur `paid_at`, ne sommait
             * donc jamais `total_revenue`.
             */
            $payments = Payment::whereBetween('created_at', [$startDate, $endDate])
                ->with('user')
                ->when($method, fn ($q, $m) => $q->where('payment_method', $m))
                ->when($userId, fn ($q, $id) => $q->where('user_id', $id))
                ->get();

            $totalCollected = (float) $payments->sum('amount');
            $totalTips = (float) $payments->sum('tip_amount');
            // Le pourboire est une recette pour l'agent, pas du chiffre
            // d'affaires : l'afficher en CA et à part le comptait deux fois.
            $totalRevenue = round($totalCollected - $totalTips, 2);

            $totalOrders = Order::whereBetween('created_at', [$startDate, $endDate])
                ->when($userId, fn ($q, $id) => $q->where('user_id', $id))
                ->when($method, $paidByMethod)
                ->count();

            $invoices = $invoiceQuery()->get();
            $paidInvoices = $invoices->filter(fn (Invoice $inv) => $inv->status === InvoiceStatus::Paid);
            $unpaidInvoices = $invoices->filter(fn (Invoice $inv) => $inv->status === InvoiceStatus::Unpaid);
            $totalPaid = (float) $paidInvoices->sum('total');
            $totalUnpaid = (float) $unpaidInvoices->sum('total');

            $paymentsByMethod = $payments
                ->groupBy(fn (Payment $p) => $p->payment_method instanceof \BackedEnum ? $p->payment_method->value : $p->payment_method)
                ->map(fn ($group) => [
                    'count' => $group->count(),
                    'total' => round((float) $group->sum('amount'), 2),
                ])->toArray();

            // Même base que le CA : on divise par le nombre de règlements,
            // pas par le nombre de commandes (les commandes non réglées
            // faussaient le panier moyen à la baisse).
            $avgBasket = $payments->count() > 0 ? round($totalRevenue / $payments->count(), 2) : 0;

            $dailyRevenue = $payments
                ->groupBy(fn (Payment $p) => $p->created_at->format('Y-m-d'))
                ->map(fn ($group) => round((float) $group->sum('amount') - (float) $group->sum('tip_amount'), 2))
                ->sortKeys()
                ->toArray();

            $dailyOrders = Order::whereBetween('created_at', [$startDate, $endDate])
                ->when($userId, fn ($q, $id) => $q->where('user_id', $id))
                ->when($method, $paidByMethod)
                ->get()
                ->groupBy(fn (Order $o) => $o->created_at->format('Y-m-d'))
                ->map(fn ($group) => $group->count())
                ->sortKeys()
                ->toArray();

            /*
             * Courbe horaire : les 24 cases sont présentes, même vides.
             * Un objet sparse (« 11 » puis « 19 ») donne un graphique illisible
             * et interdit tout calcul de pointe.
             */
            $groupedHourly = $payments
                ->groupBy(fn (Payment $p) => $p->created_at->format('H'))
                ->map(fn ($group) => round((float) $group->sum('amount') - (float) $group->sum('tip_amount'), 2));

            $hourlyRevenue = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
                $hourlyRevenue[$key] = $groupedHourly->get($key, 0);
            }

            /*
             * Encaissements par agent : indispensable pour savoir qui a pris
             * l'argent et rapprocher un écart de caisse avec une personne.
             */
            $revenueByUser = $payments
                ->groupBy(fn (Payment $p) => (string) $p->user_id)
                ->map(function ($group) {
                    $collected = (float) $group->sum('amount');
                    $tips = (float) $group->sum('tip_amount');

                    return [
                        'user_id' => $group->first()->user_id,
                        'name' => $group->first()->user?->name ?? 'N/A',
                        'count' => $group->count(),
                        'collected' => round($collected, 2),
                        'tips' => round($tips, 2),
                        'revenue' => round($collected - $tips, 2),
                    ];
                })
                ->sortByDesc('revenue')
                ->values()
                ->toArray();

            $sessions = CashRegisterSession::whereBetween('created_at', [$startDate, $endDate])
                ->when($userId, fn ($q, $id) => $q->where('user_id', $id))
                ->with('user')
                ->orderByDesc('created_at')
                ->get()
                ->map(function (CashRegisterSession $session) {
                    $expected = round($this->cashRegisterService->calculateExpectedBalance($session), 2);

                    return [
                        'id' => $session->id,
                        'user' => $session->user?->name ?? 'N/A',
                        'status' => $session->status instanceof \BackedEnum ? $session->status->value : $session->status,
                        'opening_amount' => (float) $session->opening_amount,
                        'closing_amount' => $session->closing_amount ? (float) $session->closing_amount : null,
                        'expected_balance' => $expected,
                        // Calculé ici et non côté client : le front reconstituait
                        // cet écart depuis le seul fond de caisse.
                        'discrepancy' => $session->closing_amount !== null
                            ? round((float) $session->closing_amount - $expected, 2)
                            : null,
                        'opened_at' => $session->opened_at,
                        'closed_at' => $session->closed_at,
                    ];
                });

            /*
             * Top des ventes : seules les commandes servies comptent.
             * Sans filtre de statut ni de suppression, les commandes en
             * attente et les commandes annulées ou soft-délétées gonflaient
             * le classement. `total_revenue` y désigne le brut des lignes
             * (hors remise, hors TVA) — c'est un volume vendu, pas un CA.
             */
            $topSellingItems = DB::table('order_items')
                ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.created_at', [$startDate, $endDate])
                ->when($userId, fn ($q, $id) => $q->where('orders.user_id', $id))
                ->when($method, $paidByMethod)
                ->whereNull('orders.deleted_at')
                ->whereIn('orders.status', [OrderStatus::Served->value, OrderStatus::Completed->value])
                ->select(
                    'menu_items.name',
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('SUM(order_items.total_price) as total_revenue')
                )
                ->groupBy('menu_items.name')
                ->orderByDesc('total_quantity')
                ->limit(10)
                ->get();

            return response()->json([
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'payment_method' => $method,
                    'user_id' => $userId,
                ],
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_tips' => round($totalTips, 2),
                    'total_collected' => round($totalCollected, 2),
                    'total_orders' => $totalOrders,
                    'total_paid' => round($totalPaid, 2),
                    'total_unpaid' => round($totalUnpaid, 2),
                    'avg_basket' => $avgBasket,
                    'payment_count' => $payments->count(),
                    'paid_count' => $paidInvoices->count(),
                    'unpaid_count' => $unpaidInvoices->count(),
                ],
                'payments_by_method' => $paymentsByMethod,
                'top_selling_items' => $topSellingItems,
                'daily_revenue' => $dailyRevenue,
                'daily_orders' => $dailyOrders,
                'hourly_revenue' => $hourlyRevenue,
                'revenue_by_user' => $revenueByUser,
                'sessions' => $sessions,
            ]);
        } catch (ValidationException $e) {
            // `ValidationException` hérite de `Exception` : sans ce catch
            // dédié, un paramètre invalide était avalé par le catch générique
            // et renvoyait un 500 — l'utilisateur ne voyait jamais quelle
            // donnée était fausse.
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la génération du rapport.'], 500);
        }
    }
}
