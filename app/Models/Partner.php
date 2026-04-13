<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = [
        'name','code','type','country_code','api_config',
        'timeout_seconds','max_retries','retry_delay_seconds',
        'success_rate','avg_response_time_ms','is_active'
    ];
    protected $casts = [
        'api_config' => 'encrypted:array', // encrypted at rest
        'is_active'  => 'boolean',
    ];

    public function corridors()            { return $this->hasMany(PartnerCorridor::class); }
    public function transactions()         { return $this->hasMany(Transaction::class); }
    public function disbursementAttempts() { return $this->hasMany(DisbursementAttempt::class); }
    public function account()             { return $this->morphOne(Account::class, 'owner'); }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
