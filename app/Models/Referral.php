<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $fillable = [
        'referrer_id', 'referred_id', 'status',
        'referrer_discount_percent', 'referred_discount_percent',
        'qualified_at', 'rewarded_at',
    ];

    protected $casts = [
        'referrer_discount_percent' => 'decimal:2',
        'referred_discount_percent' => 'decimal:2',
        'qualified_at'              => 'datetime',
        'rewarded_at'               => 'datetime',
    ];

    public function referrer() { return $this->belongsTo(User::class, 'referrer_id'); }
    public function referred() { return $this->belongsTo(User::class, 'referred_id'); }
}
