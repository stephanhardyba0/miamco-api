<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clients = Client::when($request->get('search'), fn ($q, $s) => $q->where(function ($q) use ($s) {
            $q->where('first_name', 'like', "%{$s}%")
                ->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%");
        }))
            ->latest()
            ->paginate($request->get('per_page', 15));

        $payload = $clients->toArray();
        $payload['stats'] = [
            'total' => Client::count(),
            'subscribers' => Client::where('type', 'subscriber')->count(),
            'vip' => Client::where('type', 'vip')->count(),
            'total_loyalty' => (int) Client::sum('loyalty_points'),
        ];

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:clients',
            'phone' => 'nullable|string|max:255',
            'type' => 'nullable|string|in:occasional,subscriber,vip',
            'notes' => 'nullable|string',
        ]);

        $client = Client::create($validated);

        return response()->json(['message' => 'Client créé.', 'client' => $client], 201);
    }

    public function show($id): JsonResponse
    {
        $client = Client::with(['orders', 'invoices'])->findOrFail($id);

        return response()->json(['client' => $client]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:clients,email,'.$id,
            'phone' => 'nullable|string|max:255',
            'type' => 'nullable|string|in:occasional,subscriber,vip',
            'notes' => 'nullable|string',
        ]);

        $client->update($validated);

        return response()->json(['message' => 'Client mis à jour.', 'client' => $client]);
    }

    public function destroy($id): JsonResponse
    {
        Client::findOrFail($id)->delete();

        return response()->json(['message' => 'Client supprimé.']);
    }

    public function toggleActive($id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $client->update(['is_active' => ! ($client->is_active ?? true)]);
        $client->refresh();

        return response()->json(['message' => 'Statut mis à jour.', 'is_active' => $client->is_active]);
    }
}
