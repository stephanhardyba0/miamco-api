<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CashRegisterSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterSession;
use App\Models\CashTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashTransactionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cash_register_session_id' => 'nullable|exists:cash_register_sessions,id',
                'type' => 'required|in:sale,refund,expense,deposit',
                'amount' => 'required|numeric|min:0.01',
                'reason' => 'nullable|string|max:255',
                'payment_method' => 'nullable|string|max:50',
            ]);

            if (empty($validated['cash_register_session_id'])) {
                $session = CashRegisterSession::where('user_id', $request->user()->id)
                    ->where('status', CashRegisterSessionStatus::Open)
                    ->first();

                if (! $session) {
                    return response()->json(['message' => 'Aucune session de caisse ouverte. Veuillez ouvrir une caisse d\'abord.'], 422);
                }

                $validated['cash_register_session_id'] = $session->id;
            } else {
                $session = CashRegisterSession::findOrFail($validated['cash_register_session_id']);
            }

            if ($session->status !== CashRegisterSessionStatus::Open) {
                return response()->json(['message' => 'Cette session de caisse n\'est pas ouverte.'], 422);
            }

            $transaction = CashTransaction::create([
                'cash_register_session_id' => $session->id,
                'user_id' => $request->user()->id,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'reason' => $validated['reason'] ?? null,
                'payment_method' => $validated['payment_method'] ?? null,
            ]);

            $transaction->load('cashRegisterSession', 'user');

            return response()->json([
                'message' => 'Transaction enregistrée avec succès.',
                'transaction' => $transaction,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Erreur de validation.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de l\'enregistrement de la transaction.'], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $transactions = CashTransaction::with(['cashRegisterSession', 'user'])
                ->when($request->cash_register_session_id, fn ($q, $id) => $q->where('cash_register_session_id', $id))
                ->when($request->type, fn ($q, $t) => $q->where('type', $t))
                ->latest()
                ->paginate($request->get('per_page', 15));

            $scope = fn ($q) => $request->cash_register_session_id
                ? $q->where('cash_register_session_id', $request->cash_register_session_id)
                : $q->whereDate('created_at', now()->toDateString());

            $payload = $transactions->toArray();
            $payload['stats'] = [
                'sales' => round((float) $scope(clone CashTransaction::query()->where('type', 'sale'))->sum('amount'), 2),
                'deposits' => round((float) $scope(clone CashTransaction::query()->where('type', 'deposit'))->sum('amount'), 2),
                'expenses' => round((float) $scope(clone CashTransaction::query()->whereIn('type', ['expense', 'refund']))->sum('amount'), 2),
                'count' => $scope(clone CashTransaction::query())->count(),
            ];

            return response()->json($payload);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la récupération des transactions.'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $transaction = CashTransaction::with('cashRegisterSession')->findOrFail($id);

            if ($transaction->cashRegisterSession?->status !== CashRegisterSessionStatus::Open) {
                return response()->json([
                    'message' => 'Impossible de supprimer une transaction d\'une session fermée : l\'historique de caisse est figé.',
                ], 422);
            }

            $transaction->delete();

            return response()->json(['message' => 'Transaction supprimée.']);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la suppression de la transaction.'], 500);
        }
    }
}
