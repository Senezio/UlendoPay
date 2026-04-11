<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'from_currency','to_currency','rate','inverse_rate',
        'margin_percent','source','is_active','fetched_at','expires_at'
    ];
    protected $casts = [
        'rate'          => 'decimal:8',
        'inverse_rate'  => 'decimal:8',
        'is_active'     => 'boolean',
        'fetched_at'    => 'datetime',
        'expires_at'    => 'datetime',
    ];

    public function rateLocks() { return $this->hasMany(RateLock::class); }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->where('expires_at', '>', now());
    }
}
