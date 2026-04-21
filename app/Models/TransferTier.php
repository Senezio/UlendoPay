<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class TransferTier extends Model
{
    protected $fillable = [
        'name', 'label', 'daily_limit', 'monthly_limit',
        'per_transaction_limit', 'fee_discount_percent', 'limit_currency', 'is_active'
    ];

    protected $casts = [
        'daily_limit'           => 'decimal:6',
        'monthly_limit'         => 'decimal:6',
        'per_transaction_limit' => 'decimal:6',
        'fee_discount_percent'  => 'decimal:2',
        'is_active'             => 'boolean',
    ];

    public static function forUser(User $user): self
    {
        return static::where('name', $user->tier)->firstOrFail();
    }
}
