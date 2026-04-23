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
        'provider',
        'provider_reference',
        'correspondent',
        'provider_request_payload',
        'provider_response_payload',
        'provider_webhook_payload',
        'status',
        'failure_reason',
        'initiated_at',
        'completed_at',
        'failed_at',
    ];

    protected $hidden = [
        'provider_request_payload',
        'provider_response_payload',
        'provider_webhook_payload',
    ];

    protected $casts = [
        'amount'                    => 'decimal:6',
        'provider_request_payload'  => 'array',
        'provider_response_payload' => 'array',
        'provider_webhook_payload'  => 'array',
        'initiated_at'              => 'datetime',
        'completed_at'              => 'datetime',
        'failed_at'                 => 'datetime',
    ];

    public function user()   { return $this->belongsTo(User::class); }
    public function wallet() { return $this->belongsTo(Wallet::class); }

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
