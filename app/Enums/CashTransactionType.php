<?php

namespace App\Enums;

enum CashTransactionType: string
{
    case Sale = 'sale';
    case Refund = 'refund';
    case Expense = 'expense';
    case Deposit = 'deposit';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Vente',
            self::Refund => 'Remboursement',
            self::Expense => 'Dépense',
            self::Deposit => 'Dépôt',
        };
    }
}
