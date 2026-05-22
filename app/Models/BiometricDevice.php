<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiometricDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'device_name',
        'platform',
        'public_key',
        'challenge',
        'challenge_expires_at',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'challenge_expires_at' => 'datetime',
        'last_used_at'         => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
