<?php

namespace App\Services;

use App\Events\StockSaved;
use App\Models\MenuItemIngredient;
use App\Models\Order;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockLedgerService
{
    /**
     * Décrémente le stock des ingrédients consommés par une commande
     * et enregistre une ligne dans le journal des mouvements.
     * Le solde décrémenté est réellement ce qui était disponible ;
     * le journal reçoit la quantité réellement sortie (jamais de divergence).
     */
    public function deductForOrder(Order $order, string $reason): void
    {
        $order->loadMissing('orderItems');

        $quantitiesByStockItem = [];

        foreach ($order->orderItems as $line) {
            if (! $line->menu_item_id) {
                continue;
            }

            $ingredients = MenuItemIngredient::where('menu_item_id', $line->menu_item_id)->get();

            foreach ($ingredients as $ingredient) {
                $key = $ingredient->stock_item_id;
                $quantitiesByStockItem[$key] = ($quantitiesByStockItem[$key] ?? 0)
                    + ((float) $ingredient->quantity * (int) $line->quantity);
            }
        }

        if ($quantitiesByStockItem === []) {
            return;
        }

        DB::transaction(function () use ($quantitiesByStockItem, $reason, $order) {
            foreach ($quantitiesByStockItem as $stockItemId => $requested) {
                $item = StockItem::whereKey($stockItemId)->lockForUpdate()->first();
                if (! $item) {
                    continue;
                }

                $actual = min((float) $item->quantity, (float) $requested);
                if ($actual <= 0) {
                    continue;
                }

                StockMovement::create([
                    'stock_item_id' => $item->id,
                    'user_id' => $order->user_id,
                    'type' => 'out',
                    'quantity' => $actual,
                    'reason' => $reason,
                ]);

                $item->quantity = (float) $item->quantity - $actual;
                $item->save();

                StockSaved::dispatch($item, 'updated');
            }
        });
    }

    /**
     * Restitue le stock déduit lors du service/vente d'une commande annulée.
     *
     * On restitue exactement ce qui est sorti du journal (mouvements `out`
     * portant la raison de vente de CETTE commande), et non la recette
     * théorique : la déduction initiale avait pu être tronquée par un stock
     * insuffisant, et reconstituer la demande aurait créé du stock du
     * néant. Si rien n'a jamais été déduit, c'est un no-op.
     */
    public function restoreForOrder(Order $order, string $reason, ?int $userId = null): void
    {
        $deductionReasons = [
            "Vente commande {$order->order_number}",
            "Vente POS #{$order->order_number}",
        ];

        $movements = StockMovement::query()
            ->whereIn('reason', $deductionReasons)
            ->where('type', 'out')
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        $quantitiesByStockItem = $movements
            ->groupBy('stock_item_id')
            ->map(fn ($rows) => (float) $rows->sum('quantity'));

        DB::transaction(function () use ($quantitiesByStockItem, $reason, $order, $userId) {
            foreach ($quantitiesByStockItem as $stockItemId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $item = StockItem::whereKey($stockItemId)->lockForUpdate()->first();
                if (! $item) {
                    continue;
                }

                StockMovement::create([
                    'stock_item_id' => $item->id,
                    'user_id' => $userId ?? $order->user_id,
                    'type' => 'in',
                    'quantity' => $quantity,
                    'reason' => $reason,
                ]);

                $item->quantity = (float) $item->quantity + $quantity;
                $item->save();

                StockSaved::dispatch($item, 'updated');
            }
        });
    }
}
