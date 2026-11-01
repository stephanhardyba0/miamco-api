<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with('user');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        if ($request->has('action')) {
            $query->where('action', $request->get('action'));
        }

        if ($request->has('model_type')) {
            $query->where('model_type', $request->get('model_type'));
        }

        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->get('date_from'));
        }

        if ($request->has('date_to')) {
            // Inclusif sur la journée choisie : une comparaison brute avec
            // « 2026-10-24 » exclurait toute la journée (00h00 effectifs).
            $query->where('created_at', '<', \Illuminate\Support\Carbon::parse($request->get('date_to'))->addDay());
        }

        $activities = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($activities);
    }

    public function orderHistory(int $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);

        $activities = AuditLog::with('user')
            ->where('model_type', Order::class)
            ->where('model_id', $orderId)
            ->latest()
            ->get();

        return response()->json([
            'order' => $order->only('id', 'order_number', 'status'),
            'activities' => $activities,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 7);
        $since = now()->subDays($days);

        $actionsPerHour = AuditLog::where('created_at', '>=', $since)
            ->select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy(DB::raw('HOUR(created_at)'))
            ->orderBy('hour')
            ->get();

        $mostActiveUsers = AuditLog::where('created_at', '>=', $since)
            ->select('user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(10)
            ->with('user:id,name')
            ->get();

        $actionsByModule = AuditLog::where('created_at', '>=', $since)
            ->select('model_type', DB::raw('COUNT(*) as count'))
            ->groupBy('model_type')
            ->orderByDesc('count')
            ->get();

        $totalActions = AuditLog::where('created_at', '>=', $since)->count();

        $actionsPerDay = AuditLog::where('created_at', '>=', $since)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return response()->json([
            'period_days' => $days,
            'total_actions' => $totalActions,
            'actions_per_hour' => $actionsPerHour,
            'actions_per_day' => $actionsPerDay,
            'most_active_users' => $mostActiveUsers,
            'actions_by_module' => $actionsByModule,
        ]);
    }
}
