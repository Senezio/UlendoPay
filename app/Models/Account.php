<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = [
        'code','type','currency_code','owner_id','owner_type',
        'corridor','normal_balance','is_active'
    ];
    protected $casts = ['is_active' => 'boolean'];

    public function balance()  { return $this->hasOne(AccountBalance::class); }
    public function entries()  { return $this->hasMany(JournalEntry::class); }
    public function wallet()   { return $this->hasOne(Wallet::class); }
    public function owner()    { return $this->morphTo(); }
}
