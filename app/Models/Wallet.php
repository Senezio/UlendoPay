<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = ['user_id','account_id','currency_code','status'];

    public function user()    { return $this->belongsTo(User::class); }
    public function account() { return $this->belongsTo(Account::class); }

    // Convenience: get the current balance via the account
    public function getBalanceAttribute(): string
    {
        return $this->account?->balance?->balance ?? '0.000000';
    }
}
