<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwoFactorAuth extends Model
{
    protected $table = 'two_factor_auth';

    protected $fillable = [
        'user_id',
        'secret_encrypted',
        'recovery_codes_encrypted',
        'is_enabled',
        'enabled_at',
        'last_used_at',
    ];

    protected $casts = [
        'is_enabled'  => 'boolean',
        'enabled_at'  => 'datetime',
        'last_used_at'=> 'datetime',
    ];

    protected $hidden = [
        'secret_encrypted',
        'recovery_codes_encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getSecret(): string
    {
        return decrypt($this->secret_encrypted);
    }

    public function getRecoveryCodes(): array
    {
        return json_decode(decrypt($this->recovery_codes_encrypted), true);
    }

    public function setSecret(string $secret): void
    {
        $this->update(['secret_encrypted' => encrypt($secret)]);
    }

    public function setRecoveryCodes(array $codes): void
    {
        $this->update(['recovery_codes_encrypted' => encrypt(json_encode($codes))]);
    }

    public function markUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
