<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TopUp extends Model
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
        'pawapay_deposit_id',
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

    // Relationships
    public function user()   { return $this->belongsTo(User::class); }
    public function wallet() { return $this->belongsTo(Wallet::class); }

    // Generate unique reference
    public static function generateReference(): string
    {
        do {
            $ref = 'TUP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (self::where('reference', $ref)->exists());

        return $ref;
    }

    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isFailed(): bool    { return $this->status === 'failed'; }
}
