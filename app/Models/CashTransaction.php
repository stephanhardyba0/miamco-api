<?php

namespace App\Models;

use App\Enums\CashTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'cash_register_session_id',
        'user_id',
        'type',
        'amount',
        'reason',
        'payment_method',
        'reference_id',
        'reference_type',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'type' => CashTransactionType::class,
        ];
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
