<?php

namespace App\Enums;

enum CashRegisterSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouverte',
            self::Closed => 'Fermée',
        };
    }
}
