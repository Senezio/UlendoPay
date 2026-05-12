<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerOperator extends Model
{
    protected $fillable = [
        'partner_id',
        'country',
        'currency',
        'correspondent',
        'operation_type',
        'min_amount',
        'max_amount',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'min_amount'     => 'float',
        'max_amount'     => 'float',
        'is_active'      => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPayout($query)
    {
        return $query->where('operation_type', 'PAYOUT');
    }

    public function scopeForCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }
}
