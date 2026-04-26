<?php

namespace App\Models;
use Laravel\Sanctum\HasApiTokens;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

#[Fillable([
    'name', 'email', 'password', 'pin',
    'phone_encrypted', 'phone_hash', 'country_code',
    'kyc_status', 'status', 'kyc_verified_at', 'tier',
    'referral_code', 'referred_by', 'referral_discount_percent',
    'phone_verified_at', 'last_login_at', 'last_login_method',
])]
#[Hidden(['password', 'remember_token', 'phone_encrypted', 'phone_hash', 'pin'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'kyc_verified_at'    => 'datetime',
            'phone_verified_at'  => 'datetime',
            'last_login_at'      => 'datetime',
            'password'           => 'hashed',
        ];
    }

    // Store phone as encrypted + hash for lookup
    public function setPhoneAttribute(string $value): void
    {
        $this->attributes['phone_encrypted'] = encrypt($value);
        $this->attributes['phone_hash']      = hash('sha256', $value);
    }

    // Decrypt phone when reading
    public function getPhoneAttribute(): ?string
    {
        return $this->phone_encrypted ? decrypt($this->phone_encrypted) : null;
    }

    // Store PIN as hashed — never plaintext
    public function setPinAttribute(string $value): void
    {
        $this->attributes['pin'] = Hash::make($value);
    }

    public function verifyPin(string $pin): bool
    {
        return Hash::check($pin, $this->pin);
    }

    public function isPhoneVerified(): bool
    {
        return !is_null($this->phone_verified_at);
    }

    public function isKycVerified(): bool  { return $this->kyc_status === 'verified'; }
    public function isActive(): bool       { return $this->status === 'active'; }

    public function kycRecords()      { return $this->hasMany(KycRecord::class); }
    public function wallets()         { return $this->hasMany(Wallet::class); }
    public function accounts()        { return $this->morphMany(Account::class, 'owner'); }
    public function recipients()      { return $this->hasMany(Recipient::class); }
    public function transactions()    { return $this->hasMany(Transaction::class, 'sender_id'); }
    public function rateLocks()       { return $this->hasMany(RateLock::class); }
    public function idempotencyKeys() { return $this->hasMany(IdempotencyKey::class); }
    public function otpCodes()        { return $this->hasMany(OtpCode::class); }
    public function twoFactorAuth()   { return $this->hasOne(TwoFactorAuth::class); }

    public function wallet(string $currency)
    {
        return $this->hasMany(Wallet::class)
            ->where('currency_code', $currency)
            ->first();
    }
}
