<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookSignature extends Model
{
    protected $fillable = [
        'partner_id',
        'secret_encrypted',
        'algorithm',
        'is_active',
        'rotated_at',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'rotated_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_encrypted',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
