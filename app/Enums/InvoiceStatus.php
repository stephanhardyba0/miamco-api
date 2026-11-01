<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case Sent = 'sent';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Unpaid => 'Impayée',
            self::Sent => 'Envoyée',
            self::Paid => 'Payée',
            self::PartiallyPaid => 'Partiellement payée',
            self::Cancelled => 'Annulée',
        };
    }
}
