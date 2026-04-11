<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Withdrawal extends Model
{
    protected $fillable = [
        'reference',
        'user_id',
        'wallet_id',
        'amount',
        'currency_code',
        'phone_number',
        'mobile_operator',
        'country_code',
        'pawapay_payout_id',
        'correspondent',
        'pawapay_request_payload',
        'pawapay_response_payload',
        'pawapay_webhook_payload',
        'status',
        'failure_reason',
        'initiated_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'amount'                   => 'decimal:6',
        'pawapay_request_payload'  => 'array',
        'pawapay_response_payload' => 'array',
        'pawapay_webhook_payload'  => 'array',
        'initiated_at'             => 'datetime',
        'completed_at'             => 'datetime',
        'failed_at'                => 'datetime',
    ];

    protected $hidden = [
        'pawapay_request_payload',
        'pawapay_response_payload',
        'pawapay_webhook_payload',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public static function generateReference(): string
    {
        return 'WDR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
