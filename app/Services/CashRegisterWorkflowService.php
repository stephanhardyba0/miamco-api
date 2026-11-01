<?php

namespace App\Services;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\CashTransactionType;
use App\Enums\PaymentMethod;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\CashTransaction;
use Illuminate\Support\Facades\DB;

class CashRegisterWorkflowService
{
    /**
     * Ouvre une session de caisse.
     *
     * La ligne de caisse est verrouillée en écriture : deux ouvertures
     * simultanées sur la même caisse sont sérialisées, la seconde voit la
     * session créée par la première et échoue au contrôle d'unicité.
     */
    public function openSession(int $cashRegisterId, int $userId, float $openingAmount): CashRegisterSession
    {
        return DB::transaction(function () use ($cashRegisterId, $userId, $openingAmount) {
            $register = CashRegister::whereKey($cashRegisterId)->lockForUpdate()->firstOrFail();

            $activeSession = CashRegisterSession::where('cash_register_id', $cashRegisterId)
                ->where('status', CashRegisterSessionStatus::Open)
                ->lockForUpdate()
                ->exists();

            if ($activeSession) {
                throw new \InvalidArgumentException(
                    'Il y a déjà une session ouverte pour cette caisse.'
                );
            }

            $session = CashRegisterSession::create([
                'cash_register_id' => $cashRegisterId,
                'user_id' => $userId,
                'opening_amount' => $openingAmount,
                'status' => CashRegisterSessionStatus::Open,
                'opened_at' => now(),
            ]);

            $register->update(['is_open' => true]);

            return $session;
        });
    }

    /**
     * Ferme une session de caisse.
     *
     * Le verrou sur la session empêche une transaction concurrente d'être
     * ajoutée entre le calcul du solde attendu et la passation au statut
     * fermé — sinon cette transaction serait perdue du calcul.
     */
    public function closeSession(CashRegisterSession $session, float $countedAmount): array
    {
        return DB::transaction(function () use ($session, $countedAmount) {
            $session = CashRegisterSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== CashRegisterSessionStatus::Open) {
                throw new \InvalidArgumentException('Cette session n\'est pas ouverte.');
            }

            $expectedBalance = $this->calculateExpectedBalance($session);
            $discrepancy = $countedAmount - $expectedBalance;

            $session->update([
                'closing_amount' => $countedAmount,
                'status' => CashRegisterSessionStatus::Closed,
                'closed_at' => now(),
            ]);

            CashRegister::whereKey($session->cash_register_id)
                ->update(['is_open' => false, 'current_balance' => $countedAmount]);

            return [
                'session' => $session,
                'expected_balance' => $expectedBalance,
                'counted_amount' => $countedAmount,
                'discrepancy' => $discrepancy,
            ];
        });
    }

    public function addTransaction(
        CashRegisterSession $session,
        CashTransactionType $type,
        float $amount,
        int $userId,
        ?string $reason = null,
        ?string $paymentMethod = null,
        ?int $referenceId = null,
        ?string $referenceType = null,
    ): CashTransaction {
        /*
         * Re-lecture verrouillée de la session au moment de l'écriture.
         *
         * `$session` vient d'une résolution faite plus tôt dans la requête :
         * son statut en mémoire peut dater d'avant une clôture concurrente.
         * En l'état, une vente résolvait la session juste avant la fermeture,
         * puis déposait sa ligne `sale` dans une session déjà close — après
         * le calcul de l'écart, qui devenait donc faux. Le verrou aligne le
         * contrôle sur la réalité de la base : ou la vente passe avant la
         * clôture (et la clôture la comptera), ou la session est close et
         * l'encaissement échoue proprement.
         */
        return DB::transaction(function () use ($session, $type, $amount, $userId, $reason, $paymentMethod, $referenceId, $referenceType) {
            $fresh = CashRegisterSession::whereKey($session->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status !== CashRegisterSessionStatus::Open) {
                throw new \InvalidArgumentException('La session de caisse n\'est pas ouverte.');
            }

            return CashTransaction::create([
                'cash_register_session_id' => $fresh->id,
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'payment_method' => $paymentMethod,
                'reference_id' => $referenceId,
                'reference_type' => $referenceType,
            ]);
        });
    }

    /**
     * Porte un encaissement au tableau de caisse.
     *
     * Seules les espèces entrent réellement dans le tiroir : un règlement
     * par carte, virement ou mobile money est une recette, pas de la
     * monnaie manipulée. Les inclure dans le solde attendu ferait
     * apparaître un écart exactement égal au chiffre d'affaires non cash.
     *
     * @return CashTransaction|null null si le règlement n'est pas en espèces.
     */
    public function recordEncaissement(
        CashRegisterSession $session,
        float $amount,
        int $userId,
        string $paymentMethod,
        string $reason,
        ?int $referenceId = null,
        ?string $referenceType = null,
    ): ?CashTransaction {
        if ($paymentMethod !== PaymentMethod::Cash->value) {
            return null;
        }

        return $this->addTransaction(
            session: $session,
            type: CashTransactionType::Sale,
            amount: $amount,
            userId: $userId,
            reason: $reason,
            paymentMethod: $paymentMethod,
            referenceId: $referenceId,
            referenceType: $referenceType,
        );
    }

    public function calculateExpectedBalance(CashRegisterSession $session): float
    {
        $sales = $session->transactions()
            ->where('type', CashTransactionType::Sale)
            ->sum('amount');

        $expenses = $session->transactions()
            ->where('type', CashTransactionType::Expense)
            ->sum('amount');

        $deposits = $session->transactions()
            ->where('type', CashTransactionType::Deposit)
            ->sum('amount');

        $refunds = $session->transactions()
            ->where('type', CashTransactionType::Refund)
            ->sum('amount');

        return (float) $session->opening_amount + $sales + $deposits - $expenses - $refunds;
    }

    public function findOpenSessionForUser(int $userId): ?CashRegisterSession
    {
        return CashRegisterSession::where('user_id', $userId)
            ->where('status', CashRegisterSessionStatus::Open)
            ->with('cashRegister')
            ->first();
    }

    /**
     * Résout la session de caisse à créditer lors d'un encaissement.
     *
     * Un encaissement appartient à la caisse physique ouverte, pas à un
     * utilisateur : si le serveur qui encaisse n'a pas de session propre,
     * la vente repart sur la seule session ouverte. Le cas ambigu (plusieurs
     * sessions ouvertes) lève une erreur plutôt que d'être tranché au hasard.
     *
     * @throws \InvalidArgumentException si aucune session n'est ouverte, ou s'il y en a plusieurs.
     */
    public function resolveOpenSessionForSale(int $userId): CashRegisterSession
    {
        $ownSession = $this->findOpenSessionForUser($userId);

        if ($ownSession) {
            return $ownSession;
        }

        $openSessions = CashRegisterSession::where('status', CashRegisterSessionStatus::Open)->get();

        if ($openSessions->count() === 1) {
            return $openSessions->first();
        }

        throw new \InvalidArgumentException(
            $openSessions->isEmpty()
                ? 'Aucune session de caisse ouverte : ouvrez une caisse avant d\'encaisser.'
                : 'Plusieurs sessions de caisse sont ouvertes : fermez-en une avant d\'encaisser.'
        );
    }
}
