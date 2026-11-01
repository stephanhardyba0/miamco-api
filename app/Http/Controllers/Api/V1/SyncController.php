<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\CashRegisterSession;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Table;
use App\Services\OrderWorkflowService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function changes(Request $request): JsonResponse
    {
        $since = $request->get('since');
        $lastSync = $since ? Carbon::parse($since) : Carbon::now()->subMinutes(5);

        $models = [
            'orders' => Order::with(['table', 'client', 'user', 'orderItems.menuItem'])
                ->where('updated_at', '>', $lastSync)
                ->latest('updated_at')
                ->limit(200)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'table_id' => $o->table_id,
                    'client_id' => $o->client_id,
                    'user_id' => $o->user_id,
                    'status' => $o->status->value,
                    'total' => (float) $o->total,
                    'notes' => $o->notes,
                    'created_at' => $o->created_at->toIso8601String(),
                    'updated_at' => $o->updated_at->toIso8601String(),
                    'table' => $o->table ? ['id' => $o->table->id, 'name' => $o->table->name] : null,
                    'client' => $o->client ? ['id' => $o->client->id, 'first_name' => $o->client->first_name] : null,
                    'user' => ['id' => $o->user->id, 'name' => $o->user->name],
                    'items' => $o->orderItems->map(fn ($item) => [
                        'id' => $item->id,
                        'menu_item_id' => $item->menu_item_id,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'total_price' => (float) $item->total_price,
                        'notes' => $item->notes,
                        'menu_item' => $item->menuItem ? ['id' => $item->menuItem->id, 'name' => $item->menuItem->name] : null,
                    ]),
                ]),

            'tables' => Table::with('zone')
                ->where('updated_at', '>', $lastSync)
                ->latest('updated_at')
                ->limit(100)
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'seats' => $t->seats,
                    'status' => $t->status->value,
                    'zone_id' => $t->zone_id,
                    'zone' => $t->zone ? ['id' => $t->zone->id, 'name' => $t->zone->name] : null,
                    'updated_at' => $t->updated_at->toIso8601String(),
                ]),

            'menu_items' => MenuItem::with('menuCategory')
                ->where('updated_at', '>', $lastSync)
                ->latest('updated_at')
                ->limit(200)
                ->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'description' => $m->description,
                    'price' => (float) $m->price,
                    'is_available' => $m->is_available,
                    'menu_category_id' => $m->menu_category_id,
                    'category' => $m->menuCategory ? ['id' => $m->menuCategory->id, 'name' => $m->menuCategory->name] : null,
                    'updated_at' => $m->updated_at->toIso8601String(),
                ]),

            'cash_sessions' => CashRegisterSession::with('cashRegister')
                ->where('updated_at', '>', $lastSync)
                ->latest('updated_at')
                ->limit(50)
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'cash_register_id' => $s->cash_register_id,
                    'user_id' => $s->user_id,
                    'status' => $s->status->value,
                    'opening_amount' => (float) $s->opening_amount,
                    'closing_amount' => $s->closing_amount ? (float) $s->closing_amount : null,
                    'opened_at' => $s->opened_at?->toIso8601String(),
                    'closed_at' => $s->closed_at?->toIso8601String(),
                    'updated_at' => $s->updated_at->toIso8601String(),
                ]),

            'notifications' => AppNotification::where('user_id', $request->user()->id)
                ->where('updated_at', '>', $lastSync)
                ->latest('updated_at')
                ->limit(100)
                ->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'title' => $n->title,
                    'message' => $n->message,
                    'type' => $n->type,
                    'is_read' => $n->is_read,
                    'created_at' => $n->created_at->toIso8601String(),
                    'updated_at' => $n->updated_at->toIso8601String(),
                ]),
        ];

        return response()->json([
            'synced_at' => Carbon::now()->toIso8601String(),
            'changes' => $models,
        ]);
    }

    public function push(Request $request): JsonResponse
    {
        $request->validate([
            'orders' => 'sometimes|array',
            'orders.*.local_id' => 'required|string',
            'orders.*.table_id' => 'nullable|exists:tables,id',
            'orders.*.items' => 'required|array|min:1',
            'orders.*.items.*.menu_item_id' => 'required|exists:menu_items,id',
            'orders.*.items.*.quantity' => 'required|integer|min:1',
            'orders.*.items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $results = [];

        if ($request->has('orders')) {
            $workflowService = app(OrderWorkflowService::class);

            foreach ($request->orders as $localOrder) {
                try {
                    $order = $workflowService->createOrder([
                        'table_id' => $localOrder['table_id'],
                        'client_id' => $localOrder['client_id'] ?? null,
                        'notes' => $localOrder['notes'] ?? null,
                        'items' => $localOrder['items'],
                    ], $request->user());

                    $results[] = [
                        'local_id' => $localOrder['local_id'],
                        'server_id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => 'synced',
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'local_id' => $localOrder['local_id'],
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        return response()->json(['results' => $results]);
    }
}
