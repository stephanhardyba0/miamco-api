<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = MenuItem::with(['menuCategory', 'ingredients'])
            ->when($request->get('category_id'), fn ($q, $id) => $q->where('menu_category_id', $id))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'menu_category_id' => 'required|exists:menu_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_available' => 'nullable|boolean',
            'ingredients' => 'nullable|array',
            'ingredients.*.stock_item_id' => 'required|exists:stock_items,id',
            'ingredients.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $ingredients = $validated['ingredients'] ?? [];
        unset($validated['ingredients']);

        $item = MenuItem::create($validated);
        $this->syncIngredients($item, $ingredients);

        return response()->json(['message' => 'Article créé.', 'item' => $item->load('ingredients')], 201);
    }

    public function show($id): JsonResponse
    {
        $item = MenuItem::with('menuCategory')->findOrFail($id);

        return response()->json(['item' => $item]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = MenuItem::findOrFail($id);

        $validated = $request->validate([
            'menu_category_id' => 'sometimes|required|exists:menu_categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'is_available' => 'nullable|boolean',
            'ingredients' => 'nullable|array',
            'ingredients.*.stock_item_id' => 'required|exists:stock_items,id',
            'ingredients.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $ingredients = $validated['ingredients'] ?? null;
        unset($validated['ingredients']);

        $item->update($validated);

        if ($ingredients !== null) {
            $this->syncIngredients($item, $ingredients);
        }

        return response()->json(['message' => 'Article mis à jour.', 'item' => $item->load('ingredients')]);
    }

    private function syncIngredients(MenuItem $item, array $ingredients): void
    {
        $item->ingredients()->sync(
            collect($ingredients)->mapWithKeys(fn ($ingredient) => [
                $ingredient['stock_item_id'] => ['quantity' => $ingredient['quantity']],
            ])->all()
        );
    }

    public function destroy($id): JsonResponse
    {
        MenuItem::findOrFail($id)->delete();

        return response()->json(['message' => 'Article supprimé.']);
    }

    public function toggleAvailability($id): JsonResponse
    {
        $item = MenuItem::findOrFail($id);
        $item->update(['is_available' => ! $item->is_available]);

        return response()->json(['message' => 'Disponibilité mise à jour.', 'is_available' => $item->is_available]);
    }
}
