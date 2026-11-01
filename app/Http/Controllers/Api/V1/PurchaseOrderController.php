<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PurchaseOrderStatus;
use App\Events\StockSaved;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = PurchaseOrder::with(['supplier', 'user', 'items.stockItem'])
            ->when($request->get('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.stock_item_id' => 'nullable|exists:stock_items,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $total = collect($validated['items'])->sum(fn ($item) => $item['unit_price'] * $item['quantity']);
        $orderNumber = $this->generateOrderNumber();

        $order = PurchaseOrder::create([
            'order_number' => $orderNumber,
            'supplier_id' => $validated['supplier_id'],
            'user_id' => $request->user()->id,
            'status' => PurchaseOrderStatus::Draft,
            'total' => $total,
            'notes' => $validated['notes'] ?? null,
        ]);

        foreach ($validated['items'] as $item) {
            $order->items()->create([
                'name' => $item['name'],
                'stock_item_id' => $item['stock_item_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['unit_price'] * $item['quantity'],
            ]);
        }

        $order->load(['supplier', 'user', 'items.stockItem']);

        return response()->json(['message' => 'Commande fournisseur créée.', 'purchase_order' => $order], 201);
    }

    public function show($id): JsonResponse
    {
        $order = PurchaseOrder::with(['supplier', 'user', 'items.stockItem'])->findOrFail($id);

        return response()->json(['purchase_order' => $order]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $order->update($validated);
        $order->load(['supplier', 'user', 'items']);

        return response()->json(['message' => 'Commande mise à jour.', 'purchase_order' => $order]);
    }

    public function destroy($id): JsonResponse
    {
        PurchaseOrder::findOrFail($id)->delete();

        return response()->json(['message' => 'Commande supprimée.']);
    }

    /**
     * Réception d'un bon de commande : approvisionne réellement le stock.
     *
     * Chaque ligne liée à un article de stock (`stock_item_id`) incrémente
     * la quantité sous verrou et écrit une entrée `in` au journal. Auparavant
     * la réception ne changeait qu'un statut : les boissons réceptionnées
     * n'entraient jamais dans l'inventaire.
     */
    public function receive(Request $request, $id): JsonResponse
    {
        $order = PurchaseOrder::with('items')->findOrFail($id);

        if ($order->status !== PurchaseOrderStatus::Draft) {
            return response()->json(['message' => 'Seules les commandes en brouillon peuvent être reçues.'], 422);
        }

        try {
            DB::transaction(function () use ($order, $request) {
                // Re-lecture verrouillée : une double réception concurrente
                // ne doit pas approvisionner deux fois.
                $order = PurchaseOrder::with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($order->status !== PurchaseOrderStatus::Draft) {
                    throw new \InvalidArgumentException('Cette commande a déjà été reçue.');
                }

                foreach ($order->items as $item) {
                    if (! $item->stock_item_id) {
                        continue;
                    }

                    $stockItem = StockItem::whereKey($item->stock_item_id)->lockForUpdate()->first();
                    if (! $stockItem) {
                        continue;
                    }

                    StockMovement::create([
                        'stock_item_id' => $stockItem->id,
                        'user_id' => $request->user()->id,
                        'type' => 'in',
                        'quantity' => $item->quantity,
                        'reason' => "Réception {$order->order_number} — {$item->name}",
                    ]);

                    $stockItem->quantity = (float) $stockItem->quantity + (int) $item->quantity;
                    $stockItem->save();

                    StockSaved::dispatch($stockItem, 'updated');
                }

                $order->update([
                    'status' => PurchaseOrderStatus::Received,
                    'received_at' => now(),
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $order->refresh()->load(['supplier', 'user', 'items.stockItem']);

        return response()->json([
            'message' => 'Commande reçue, stock approvisionné.',
            'status' => $order->status->value,
            'purchase_order' => $order,
        ]);
    }

    public function cancel($id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);

        if ($order->status === PurchaseOrderStatus::Cancelled) {
            return response()->json(['message' => 'La commande est déjà annulée.'], 422);
        }

        if ($order->status === PurchaseOrderStatus::Received) {
            return response()->json(['message' => 'Impossible d\'annuler une commande déjà reçue.'], 422);
        }

        $order->update(['status' => PurchaseOrderStatus::Cancelled]);

        return response()->json(['message' => 'Commande annulée.', 'status' => $order->status->value]);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', array_column(PurchaseOrderStatus::cases(), 'value')),
        ]);

        $order = PurchaseOrder::findOrFail($id);
        $newStatus = PurchaseOrderStatus::from($validated['status']);

        $order->update(['status' => $newStatus]);

        return response()->json([
            'message' => 'Statut mis à jour.',
            'status' => $order->status->value,
        ]);
    }

    private function generateOrderNumber(): string
    {
        $lastOrder = PurchaseOrder::latest('id')->value('order_number');

        if (! $lastOrder) {
            return 'PO-000001';
        }

        $lastNumber = (int) substr($lastOrder, 3);

        return 'PO-'.str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
    }
}
