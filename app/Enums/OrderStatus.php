<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Confirmed => 'Confirmé',
            self::Preparing => 'En préparation',
            self::Ready => 'Prêt',
            self::Served => 'Servi',
            self::Completed => 'Terminé',
            self::Cancelled => 'Annulé',
        };
    }
}
