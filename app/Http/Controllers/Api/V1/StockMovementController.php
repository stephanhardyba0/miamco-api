<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\StockSaved;
use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_item_id' => 'required|exists:stock_items,id',
            'type' => 'required|string|in:in,out',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $validated['user_id'] = $request->user()->id;

        $movement = DB::transaction(function () use ($validated) {
            $stockItem = StockItem::whereKey($validated['stock_item_id'])->lockForUpdate()->firstOrFail();

            if ($validated['type'] === 'out' && $validated['quantity'] > (float) $stockItem->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Stock insuffisant : {$stockItem->name} n'a que {$stockItem->quantity} {$stockItem->unit} en stock.",
                ]);
            }

            $movement = StockMovement::create($validated);

            if ($validated['type'] === 'in') {
                $stockItem->quantity = (float) $stockItem->quantity + $validated['quantity'];
            } else {
                $stockItem->quantity = (float) $stockItem->quantity - $validated['quantity'];
            }

            $stockItem->save();

            return $movement;
        });

        StockSaved::dispatch(
            StockItem::findOrFail($validated['stock_item_id']),
            'updated'
        );

        return response()->json(['message' => 'Mouvement enregistré.', 'movement' => $movement], 201);
    }
}
