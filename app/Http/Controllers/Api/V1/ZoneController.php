<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function index(): JsonResponse
    {
        $zones = Zone::withCount('tables')->get();

        return response()->json($zones);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:50',
        ]);

        $zone = Zone::create($validated);

        return response()->json(['message' => 'Zone créée.', 'zone' => $zone], 201);
    }

    public function show($id): JsonResponse
    {
        $zone = Zone::with('tables')->findOrFail($id);

        return response()->json(['zone' => $zone]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $zone = Zone::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'color' => 'nullable|string|max:50',
        ]);

        $zone->update($validated);

        return response()->json(['message' => 'Zone mise à jour.', 'zone' => $zone]);
    }

    public function destroy($id): JsonResponse
    {
        Zone::findOrFail($id)->delete();

        return response()->json(['message' => 'Zone supprimée.']);
    }
}
