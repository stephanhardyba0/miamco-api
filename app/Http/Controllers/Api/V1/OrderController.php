<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\OrderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderWorkflowService $workflowService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::with(['table', 'client', 'user', 'orderItems.menuItem'])
            ->when($request->get('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->get('table_id'), fn ($q, $id) => $q->where('table_id', $id))
            ->when($request->get('search'), fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                    ->orWhereHas('table', fn ($t) => $t->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('client', fn ($c) => $c->where(function ($cq) use ($s) {
                        $cq->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%");
                    }));
            }))
            ->latest()
            ->paginate($request->get('per_page', 15));

        $payload = $orders->toArray();
        $payload['status_counts'] = Order::groupBy('status')->selectRaw('status, COUNT(*) as c')->pluck('c', 'status')->all();

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'table_id' => 'nullable|exists:tables,id',
                'client_id' => 'nullable|exists:clients,id',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.menu_item_id' => 'required|exists:menu_items,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
            ]);

            $menuItemIds = array_column($validated['items'], 'menu_item_id');
            $menuItems = MenuItem::whereIn('id', $menuItemIds)->get()->keyBy('id');

            foreach ($validated['items'] as $index => $item) {
                $menuItem = $menuItems->get($item['menu_item_id']);
                if ($menuItem && (float) $item['unit_price'] !== (float) $menuItem->price) {
                    return response()->json([
                        'message' => "Le prix de l'article \"{$menuItem->name}\" ne correspond pas au catalogue.",
                    ], 422);
                }
            }

            $order = $this->workflowService->createOrder($validated, $request->user());

            return response()->json(['message' => 'Commande créée.', 'order' => $order], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la création de la commande.'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        $order = Order::with(['table', 'client', 'user', 'orderItems.menuItem', 'invoice'])
            ->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $order->update($validated);
        $order->load(['table', 'client', 'user', 'orderItems.menuItem']);

        return response()->json(['message' => 'Commande mise à jour.', 'order' => $order]);
    }

    public function destroy($id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing])) {
            return response()->json(['message' => 'Impossible de supprimer une commande active. Veuillez d\'abord l\'annuler.'], 403);
        }

        $order->delete();

        return response()->json(['message' => 'Commande supprimée.']);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $order = Order::findOrFail($id);

            $validated = $request->validate([
                'status' => 'required|string|in:'.implode(',', array_column(OrderStatus::cases(), 'value')),
            ]);

            $newStatus = OrderStatus::from($validated['status']);

            $this->validateTransitionPermission($request->user(), $order->status, $newStatus);

            $order = $this->workflowService->transitionStatus($order, $newStatus, $request->user()->id);

            return response()->json([
                'message' => 'Statut mis à jour.',
                'status' => $order->status->value,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        try {
            $order = Order::findOrFail($id);

            $order = $this->workflowService->cancelOrder($order, $request->user()->id);

            return response()->json([
                'message' => 'Commande annulée.',
                'status' => $order->status->value,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function serve(Request $request, $id): JsonResponse
    {
        try {
            $order = Order::with(['table', 'client', 'user', 'orderItems.menuItem'])->findOrFail($id);

            $order = $this->workflowService->forceServe($order);

            return response()->json([
                'message' => 'Commande servie et facture créée.',
                'order' => $order->fresh(['table', 'client', 'user', 'orderItems.menuItem', 'invoice']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors du service de la commande.'], 500);
        }
    }

    private function validateTransitionPermission($user, OrderStatus $from, OrderStatus $to): void
    {
        // Unification : un compte qui dispose du module Commandes a toutes
        // les actions du module — les roles ne sont plus son referentiel.
        if ($user->modules !== null && in_array('orders', $user->modules, true)) {
            return;
        }

        $transitionRoles = [
            OrderStatus::Confirmed->value => ['Admin', 'Manager', 'Caissier'],
            OrderStatus::Preparing->value => ['Admin', 'Manager', 'Cuisinier'],
            OrderStatus::Ready->value => ['Admin', 'Manager', 'Cuisinier'],
            OrderStatus::Served->value => ['Admin', 'Manager', 'Caissier', 'Serveur'],
            OrderStatus::Completed->value => ['Admin', 'Manager', 'Caissier', 'Serveur'],
            OrderStatus::Cancelled->value => ['Admin', 'Manager', 'Caissier'],
        ];

        $allowedRoles = $transitionRoles[$to->value] ?? ['Admin'];

        foreach ($allowedRoles as $role) {
            if ($user->hasRole($role)) {
                return;
            }
        }

        throw new \InvalidArgumentException(
            "Vous n'avez pas le droit d'effectuer cette transition."
        );
    }
}
