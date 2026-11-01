<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_open',
        'current_balance',
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'current_balance' => 'decimal:2',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }
}
