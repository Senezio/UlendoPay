<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateLimitBucket extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'key',
        'action',
        'attempts',
        'window_start',
        'blocked_until',
        'created_at',
    ];

    protected $casts = [
        'window_start'  => 'datetime',
        'blocked_until' => 'datetime',
        'created_at'    => 'datetime',
    ];

    public function isBlocked(): bool
    {
        return $this->blocked_until && $this->blocked_until->isFuture();
    }

    public function isWindowExpired(int $windowMinutes): bool
    {
        return $this->window_start->addMinutes($windowMinutes)->isPast();
    }
}
