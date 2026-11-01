<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Events\TableSaved;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tables = Table::with('zone')
            ->when($request->get('zone_id'), fn ($q, $id) => $q->where('zone_id', $id))
            ->when($request->get('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->json($tables);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'zone_id' => 'required|exists:zones,id',
            'name' => 'required|string|unique:tables',
            'seats' => 'required|integer|min:1',
        ]);

        $validated['status'] = TableStatus::Available;

        $table = Table::create($validated);

        TableSaved::dispatch($table, 'created');

        return response()->json(['message' => 'Table créée.', 'table' => $table], 201);
    }

    public function show($id): JsonResponse
    {
        $table = Table::with('zone')->findOrFail($id);

        return response()->json(['table' => $table]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $table = Table::findOrFail($id);

        $validated = $request->validate([
            'zone_id' => 'sometimes|required|exists:zones,id',
            'name' => 'sometimes|required|string|unique:tables,name,'.$id,
            'seats' => 'sometimes|required|integer|min:1',
            'status' => 'sometimes|string|in:available,occupied,reserved',
        ]);

        if (isset($validated['status'])) {
            $validated['status'] = TableStatus::from($validated['status']);
        }

        $table->update($validated);

        TableSaved::dispatch($table->refresh(), 'updated');

        return response()->json(['message' => 'Table mise à jour.', 'table' => $table]);
    }

    public function destroy($id): JsonResponse
    {
        $table = Table::findOrFail($id);
        $table->delete();

        TableSaved::dispatch($table, 'deleted');

        return response()->json(['message' => 'Table supprimée.']);
    }

    public function occupy($id): JsonResponse
    {
        $table = Table::findOrFail($id);
        $table->update(['status' => TableStatus::Occupied]);

        TableSaved::dispatch($table->refresh(), 'updated');

        return response()->json(['message' => 'Table occupée.', 'status' => $table->status->value]);
    }

    public function release($id): JsonResponse
    {
        $table = Table::findOrFail($id);
        $table->update(['status' => TableStatus::Available]);

        TableSaved::dispatch($table->refresh(), 'updated');

        return response()->json(['message' => 'Table libérée.', 'status' => $table->status->value]);
    }

    public function reserve(Request $request, $id): JsonResponse
    {
        $table = Table::findOrFail($id);

        $validated = $request->validate([
            'guest_name' => 'nullable|string|max:255',
            'guest_count' => 'nullable|integer|min:1',
            'reservation_date' => 'nullable|date',
            'reservation_time' => 'nullable|string|max:5',
            'notes' => 'nullable|string|max:255',
        ]);

        $reservation = Reservation::create([
            'table_id' => $table->id,
            'guest_name' => $validated['guest_name'] ?? null,
            'guest_count' => $validated['guest_count'] ?? null,
            'reservation_date' => $validated['reservation_date'] ?? now()->toDateString(),
            'reservation_time' => $validated['reservation_time'] ?? null,
            'status' => ReservationStatus::Pending,
            'notes' => $validated['notes'] ?? null,
        ]);

        $table->update(['status' => TableStatus::Reserved]);

        TableSaved::dispatch($table->refresh(), 'updated');

        return response()->json([
            'message' => 'Table réservée.',
            'status' => $table->status->value,
            'reservation' => $reservation,
        ]);
    }
}
