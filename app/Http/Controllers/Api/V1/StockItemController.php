<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\StockSaved;
use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = StockItem::when($request->get('low_stock'), fn ($q) => $q->lowStock())
            ->when($request->get('search'), fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")->orWhere('category', 'like', "%{$s}%");
            }))
            ->with('movements')
            ->latest()
            ->paginate($request->get('per_page', 15));

        $payload = $items->toArray();
        $payload['stats'] = [
            'total' => StockItem::count(),
            'low_stock' => StockItem::lowStock()->count(),
            'out_of_stock' => StockItem::where('quantity', 0)->count(),
            'total_value' => round((float) StockItem::sum(\DB::raw('quantity * unit_cost')), 2),
        ];

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'unit' => 'required|string|max:50',
            'quantity' => 'required|numeric|min:0',
            'min_quantity' => 'required|numeric|min:0',
            'unit_cost' => 'required|numeric|min:0',
        ]);

        $item = StockItem::create($validated);

        /*
         * La quantité initiale est un vrai approvisionnement : elle doit
         * apparaître au journal. Sans cette ligne, reconstruire le stock à
         * partir des mouvements seul divergeait dès la création.
         */
        if ((float) $validated['quantity'] > 0) {
            StockMovement::create([
                'stock_item_id' => $item->id,
                'user_id' => $request->user()->id,
                'type' => 'in',
                'quantity' => $validated['quantity'],
                'reason' => 'Stock initial à la création',
            ]);
        }

        StockSaved::dispatch($item, 'created');

        return response()->json(['message' => 'Article créé.', 'item' => $item], 201);
    }

    public function show($id): JsonResponse
    {
        $item = StockItem::with('movements')->findOrFail($id);

        return response()->json(['item' => $item]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = StockItem::findOrFail($id);

        // La quantité n'est JAMAIS modifiable ici : tout changement de solde
        // passe par un mouvement de stock (journal d'audit intégral).
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:255',
            'unit' => 'sometimes|required|string|max:50',
            'min_quantity' => 'sometimes|required|numeric|min:0',
            'unit_cost' => 'sometimes|required|numeric|min:0',
        ]);

        $item->update($validated);

        StockSaved::dispatch($item->refresh(), 'updated');

        return response()->json(['message' => 'Article mis à jour.', 'item' => $item]);
    }

    public function destroy($id): JsonResponse
    {
        $item = StockItem::findOrFail($id);
        $item->delete();

        StockSaved::dispatch($item, 'deleted');

        return response()->json(['message' => 'Article archivé.']);
    }
}
