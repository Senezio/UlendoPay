<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class UserBankAccount extends Model
{
    protected $fillable = [
        'user_id', 'label', 'bank_name', 'bank_code',
        'account_number_encrypted', 'account_number_masked',
        'account_name', 'branch_code', 'currency_code',
        'country_code', 'is_default', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    protected $hidden = ['account_number_encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function maskAccountNumber(string $number): string
    {
        $clean = preg_replace('/\D/', '', $number);
        return str_repeat('*', max(0, strlen($clean) - 4)) . substr($clean, -4);
    }

    public function getPlainAccountNumber(): string
    {
        return Crypt::decryptString($this->account_number_encrypted);
    }
}

