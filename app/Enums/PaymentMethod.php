<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Mobile = 'mobile';
    case AirtelMoney = 'airtel_money';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::Card => 'Carte',
            self::Transfer => 'Virement',
            self::Mobile => 'Mobile Money',
            self::AirtelMoney => 'Airtel Money',
        };
    }
}
