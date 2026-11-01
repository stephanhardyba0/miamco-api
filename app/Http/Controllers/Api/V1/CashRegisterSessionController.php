<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CashRegisterSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Services\CashRegisterWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashRegisterSessionController extends Controller
{
    public function __construct(
        private readonly CashRegisterWorkflowService $workflowService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $query = CashRegisterSession::with('user', 'cashRegister')
                ->withCount('transactions')
                ->orderByDesc('created_at');

            if ($request->status) {
                $query->where('status', $request->status);
            }

            $sessions = $query->paginate($request->per_page ?? 25);

            // Le solde attendu dérive des transactions de la session : sans lui,
            // le front est obligé de le reconstituer et se trompe de base.
            $sessions->getCollection()->transform(fn (CashRegisterSession $session) => array_merge($session->toArray(), [
                'expected_balance' => round($this->workflowService->calculateExpectedBalance($session), 2),
            ]));

            return response()->json($sessions);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la récupération des sessions.'], 500);
        }
    }

    public function open(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cash_register_id' => 'required|exists:cash_registers,id',
                'opening_amount' => 'required|numeric|min:0',
            ]);

            $session = $this->workflowService->openSession(
                cashRegisterId: $validated['cash_register_id'],
                userId: $request->user()->id,
                openingAmount: $validated['opening_amount'],
            );

            $session->load('cashRegister', 'user');

            return response()->json([
                'message' => 'Session de caisse ouverte avec succès.',
                'session' => $session,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Erreur de validation.', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de l\'ouverture de la session.'], 500);
        }
    }

    public function close(Request $request, CashRegisterSession $session): JsonResponse
    {
        try {
            if ($denied = $this->assertSessionOwnership($request, $session)) {
                return $denied;
            }

            $validated = $request->validate([
                'closing_amount' => 'required|numeric|min:0',
            ]);

            $result = $this->workflowService->closeSession($session, $validated['closing_amount']);

            $result['session']->load('cashRegister', 'user', 'transactions');

            return response()->json([
                'message' => 'Session de caisse fermée avec succès.',
                'session' => $result['session'],
                'expected_balance' => $result['expected_balance'],
                'counted_amount' => $result['counted_amount'],
                'discrepancy' => $result['discrepancy'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Erreur de validation.', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la fermeture de la session.'], 500);
        }
    }

    /**
     * Détail d'une session : la page de drill-down a besoin de la session
     * ET de son solde attendu — le reconstituer côté front tromperait de
     * base (la formule vit ici, avec le service).
     */
    public function show(CashRegisterSession $session): JsonResponse
    {
        try {
            $session->load('user', 'cashRegister')->loadCount('transactions');

            return response()->json([
                'session' => array_merge($session->toArray(), [
                    'expected_balance' => round($this->workflowService->calculateExpectedBalance($session), 2),
                ]),
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la récupération de la session.'], 500);
        }
    }

    public function current(Request $request): JsonResponse
    {
        try {
            $session = $this->workflowService->findOpenSessionForUser($request->user()->id);

            /*
             * L'admin (ou n'importe qui avec le module caisse sans session
             * propre) doit lire le tiroir en service : on retombe sur la
             * session ouverte la plus recente — c'est celle de la caissiere
             * que la page doit afficher, transactions comprises.
             */
            if (! $session) {
                $session = CashRegisterSession::with('user', 'transactions')
                    ->where('status', CashRegisterSessionStatus::Open)
                    ->latest('opened_at')
                    ->first();
            }

            if (! $session) {
                return response()->json(['data' => null, 'session' => null]);
            }

            $session->load('cashRegister', 'transactions');

            return response()->json(['data' => $session, 'session' => $session]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la récupération de la session.'], 500);
        }
    }

    public function reopen(Request $request, $id): JsonResponse
    {
        try {
            $session = CashRegisterSession::findOrFail($id);

            if ($denied = $this->assertSessionOwnership($request, $session)) {
                return $denied;
            }

            if ($session->status !== CashRegisterSessionStatus::Closed) {
                return response()->json(['message' => 'Cette session n\'est pas fermée.'], 422);
            }

            $existingOpen = CashRegisterSession::where('cash_register_id', $session->cash_register_id)
                ->where('status', CashRegisterSessionStatus::Open)
                ->where('id', '!=', $session->id)
                ->exists();

            if ($existingOpen) {
                return response()->json(['message' => 'Il y a déjà une session ouverte pour cette caisse.'], 422);
            }

            $session->update([
                'status' => CashRegisterSessionStatus::Open,
                'closed_at' => null,
                'closing_amount' => null,
            ]);

            CashRegister::where('id', $session->cash_register_id)->update(['is_open' => true]);

            $session->load('cashRegister', 'user');

            return response()->json([
                'message' => 'Session de caisse rouverte avec succès.',
                'session' => $session,
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la réouverture de la session.'], 500);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $session = CashRegisterSession::findOrFail($id);

            if ($denied = $this->assertSessionOwnership($request, $session)) {
                return $denied;
            }

            if ($session->status === CashRegisterSessionStatus::Open) {
                return response()->json(['message' => 'Impossible de supprimer une session ouverte.'], 422);
            }

            /*
             * Une session qui porte des mouvements de caisse est une pièce
             * comptable, pas une ligne jetable.
             *
             * `cash_transactions.cash_register_session_id` est en cascadeOnDelete
             * et le modèle n'a pas de soft-delete : sans ce garde-fou, supprimer
             * la session effacerait définitivement les espèces encaissées, les
             * entrées et les sorties. Comme les transactions de type `sale`
             * disparaîtraient alors que `payments` resterait en base, la caisse
             * et les factures se déséquilibreraient à nouveau — exactement le
             * déséquilibre que cette refonte avait pour but d'éliminer. Aucune
             * exception, même pour un Administrateur.
             */
            if ($session->transactions()->exists()) {
                return response()->json([
                    'message' => 'Suppression impossible : cette session porte des mouvements de caisse.',
                ], 422);
            }

            $session->delete();

            return response()->json(['message' => 'Session supprimée.']);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la suppression.'], 500);
        }
    }

    private function assertSessionOwnership(Request $request, CashRegisterSession $session): ?JsonResponse
    {
        $user = $request->user();

        if ($user && (
            $user->id === $session->user_id
            || $user->hasRole('Admin')
            || $user->hasRole('Manager')
            || ($user->modules !== null && in_array('cash_registers', $user->modules, true))
        )) {
            return null;
        }

        return response()->json(['message' => 'Vous ne pouvez gérer que vos propres sessions de caisse.'], 403);
    }
}
