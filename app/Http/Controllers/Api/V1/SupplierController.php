<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $suppliers = Supplier::when($request->get('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $supplier = Supplier::create($validated);

        return response()->json(['message' => 'Fournisseur créé.', 'supplier' => $supplier], 201);
    }

    public function show($id): JsonResponse
    {
        $supplier = Supplier::with('supplierProducts')->findOrFail($id);

        return response()->json(['supplier' => $supplier]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $supplier->update($validated);

        return response()->json(['message' => 'Fournisseur mis à jour.', 'supplier' => $supplier]);
    }

    public function destroy($id): JsonResponse
    {
        Supplier::findOrFail($id)->delete();

        return response()->json(['message' => 'Fournisseur supprimé.']);
    }

    public function toggleActive($id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update(['is_active' => ! ($supplier->is_active ?? true)]);
        $supplier->refresh();

        return response()->json(['message' => 'Statut mis à jour.', 'is_active' => $supplier->is_active]);
    }

    public function addProduct(Request $request, $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'unit_price' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'min_order' => 'nullable|integer|min:1',
        ]);

        $product = $supplier->supplierProducts()->create($validated);

        return response()->json(['message' => 'Produit ajouté.', 'product' => $product], 201);
    }

    public function removeProduct($supplierId, $productId): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplierId);
        $supplier->supplierProducts()->where('id', $productId)->delete();

        return response()->json(['message' => 'Produit supprimé.']);
    }
}
