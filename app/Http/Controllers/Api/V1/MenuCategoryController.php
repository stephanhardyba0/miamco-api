<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MenuCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = MenuCategory::withCount('menuItems')->orderBy('sort_order')->get();

        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:menu_categories,slug',
            'icon' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if (! isset($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category = MenuCategory::create($validated);

        return response()->json(['message' => 'Catégorie créée.', 'category' => $category], 201);
    }

    public function show($id): JsonResponse
    {
        $category = MenuCategory::with('menuItems')->findOrFail($id);

        return response()->json(['category' => $category]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $category = MenuCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:menu_categories,slug,'.$id,
            'icon' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $category->update($validated);

        return response()->json(['message' => 'Catégorie mise à jour.', 'category' => $category]);
    }

    public function destroy($id): JsonResponse
    {
        MenuCategory::findOrFail($id)->delete();

        return response()->json(['message' => 'Catégorie supprimée.']);
    }
}
