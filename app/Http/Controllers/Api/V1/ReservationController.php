<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reservations = Reservation::with(['table', 'client'])
            ->when($request->get('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->get('date'), fn ($q, $date) => $q->where('reservation_date', $date))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($reservations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_id' => 'required|exists:tables,id',
            'client_id' => 'nullable|exists:clients,id',
            'guest_name' => 'required|string|max:255',
            'guest_count' => 'required|integer|min:1',
            'reservation_date' => 'required|date',
            'reservation_time' => 'required|date_format:H:i',
            'notes' => 'nullable|string',
        ]);

        $validated['status'] = ReservationStatus::Pending;

        $reservation = Reservation::create($validated);
        $reservation->load(['table', 'client']);

        return response()->json(['message' => 'Réservation créée.', 'reservation' => $reservation], 201);
    }

    public function show($id): JsonResponse
    {
        $reservation = Reservation::with(['table', 'client'])->findOrFail($id);

        return response()->json(['reservation' => $reservation]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $reservation = Reservation::findOrFail($id);

        $validated = $request->validate([
            'table_id' => 'sometimes|required|exists:tables,id',
            'client_id' => 'nullable|exists:clients,id',
            'guest_name' => 'sometimes|required|string|max:255',
            'guest_count' => 'sometimes|required|integer|min:1',
            'reservation_date' => 'sometimes|required|date',
            'reservation_time' => 'sometimes|required|date_format:H:i',
            'notes' => 'nullable|string',
        ]);

        $reservation->update($validated);
        $reservation->load(['table', 'client']);

        return response()->json(['message' => 'Réservation mise à jour.', 'reservation' => $reservation]);
    }

    public function destroy($id): JsonResponse
    {
        Reservation::findOrFail($id)->delete();

        return response()->json(['message' => 'Réservation supprimée.']);
    }

    public function confirm($id): JsonResponse
    {
        $reservation = Reservation::findOrFail($id);
        $reservation->update(['status' => ReservationStatus::Confirmed]);

        return response()->json(['message' => 'Réservation confirmée.', 'reservation' => $reservation]);
    }

    public function cancel($id): JsonResponse
    {
        $reservation = Reservation::findOrFail($id);
        $reservation->update(['status' => ReservationStatus::Cancelled]);

        return response()->json(['message' => 'Réservation annulée.', 'reservation' => $reservation]);
    }
}
