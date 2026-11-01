<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockItem;
use App\Services\CashRegisterWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const CACHE_KEY = 'dashboard';

    public function __construct(
        private readonly CashRegisterWorkflowService $cashRegisterService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            /*
             * Le bouton « Rafraîchir » de l'écran ne servait à rien : la clé de
             * cache est fixe et le paramètre `force` envoyé par le front ne
             * purgait rien côté serveur, on pouvait cliquer pendant 30 s sans
             * jamais voir de donnée nouvelle.
             */
            if ($request->boolean('refresh')) {
                Cache::forget(self::CACHE_KEY);
            }

            $data = Cache::remember(self::CACHE_KEY, 30, function () {
                $today = now()->startOfDay();
                $month = now()->startOfMonth();

                $todayOrders = Order::whereDate('created_at', $today)->count();
                $monthOrders = Order::whereDate('created_at', '>=', $month)->count();

                $todayAgg = $this->paymentAggregate(
                    fn ($query) => $query->whereDate('created_at', $today)
                );
                $monthAgg = $this->paymentAggregate(
                    fn ($query) => $query->where('created_at', '>=', $month)
                );

                $pendingOrders = Order::where('status', OrderStatus::Pending)
                    ->orWhere('status', OrderStatus::Confirmed)
                    ->orWhere('status', OrderStatus::Preparing)
                    ->count();

                $completedTodayOrders = Order::whereDate('created_at', $today)
                    ->where('status', OrderStatus::Completed)
                    ->count();

                $lowStockItems = StockItem::lowStock()->count();
                $totalClients = Client::count();

                // Mêmes filtres que le rapport financier : seules les ventes
                // effectives entrent, sans les commandes annulées ni celles
                // qui ont été supprimées.
                $topSellingItems = DB::table('order_items')
                    ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->whereBetween('orders.created_at', [$month, now()])
                    ->whereNull('orders.deleted_at')
                    ->whereIn('orders.status', [OrderStatus::Served->value, OrderStatus::Completed->value])
                    ->select('menu_items.name', DB::raw('SUM(order_items.quantity) as total_quantity'))
                    ->groupBy('menu_items.name')
                    ->orderByDesc('total_quantity')
                    ->limit(5)
                    ->get();

                $recentOrders = Order::with(['table', 'user'])
                    ->latest()
                    ->limit(5)
                    ->get();

                $unpaidInvoices = Invoice::where('status', InvoiceStatus::Unpaid)
                    ->sum('total');

                /*
                 * Espèces réellement dans le tiroir : solde d'ouverture PLUS
                 * les mouvements de la session (ventes, entrées, sorties,
                 * remboursements). Additionner les seuls `opening_amount`
                 * affichait un tiroir qui ignorait tout le chiffre d'affaires
                 * encaissé en espèces de la journée.
                 */
                $cashInHand = CashRegisterSession::where('status', 'open')
                    ->get()
                    ->sum(fn (CashRegisterSession $session) => $this->cashRegisterService->calculateExpectedBalance($session));

                return [
                    'today' => $this->periodBlock($todayAgg, $todayOrders),
                    'month' => $this->periodBlock($monthAgg, $monthOrders),
                    'pending_orders' => $pendingOrders,
                    'completed_today_orders' => $completedTodayOrders,
                    'low_stock_items' => $lowStockItems,
                    'total_clients' => $totalClients,
                    'top_selling_items' => $topSellingItems,
                    'recent_orders' => $recentOrders,
                    'unpaid_invoices' => round($unpaidInvoices, 2),
                    'cash_in_hand' => round($cashInHand, 2),
                ];
            });

            return response()->json($data);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la récupération du tableau de bord.'], 500);
        }
    }

    /**
     * Référence unique du chiffre d'affaires.
     *
     * `revenue` est calculé une fois pour toutes ici : sommes des règlements
     * moins les pourboires, tous moyens de paiement confondus. Le tableau de
     * bord affichait `SUM(amount)` — donc le pourboire **inclus** — tandis que
     * les rapports l'excluaient et que la caisse ne comptait que les espèces :
     * trois écrans, trois chiffres différents pour la même journée.
     */
    private function periodBlock(object $agg, int $orders): array
    {
        $collected = (float) $agg->collected;
        $tips = (float) $agg->tips;
        $payments = (int) $agg->payments;
        $revenue = round($collected - $tips, 2);

        return [
            'orders' => $orders,
            'revenue' => $revenue,
            'collected' => round($collected, 2),
            'tips' => round($tips, 2),
            'payments' => $payments,
            // Base identique au rapport : CA ÷ règlements, et non ÷ commandes.
            'avg_basket' => $payments > 0 ? round($revenue / $payments, 2) : 0,
        ];
    }

    private function paymentAggregate(\Closure $scope): object
    {
        return $scope(Payment::query())
            ->selectRaw('COALESCE(SUM(amount), 0) AS collected, COALESCE(SUM(tip_amount), 0) AS tips, COUNT(*) AS payments')
            ->first()
            ?? (object) ['collected' => 0, 'tips' => 0, 'payments' => 0];
    }
}
