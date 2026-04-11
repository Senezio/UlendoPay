<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class RateLock extends Model
{
    protected $fillable = [
        'user_id','exchange_rate_id','from_currency','to_currency',
        'locked_rate','fee_percent','fee_flat','status','expires_at','used_at'
    ];
    protected $casts = [
        'locked_rate'  => 'decimal:8',
        'fee_percent'  => 'decimal:4',
        'fee_flat'     => 'decimal:6',
        'expires_at'   => 'datetime',
        'used_at'      => 'datetime',
    ];

    public function user()         { return $this->belongsTo(User::class); }
    public function exchangeRate() { return $this->belongsTo(ExchangeRate::class); }
    public function transaction()  { return $this->hasOne(Transaction::class); }

    public function scopeActive($q)
    {
        return $q->where('status', 'active')->where('expires_at', '>', now());
    }
}
