<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PendingClaim extends Model
{
    protected $fillable = [
        'transaction_id','recipient_phone_hash','recipient_phone_masked',
        'amount','currency_code','status','claimed_by',
        'claimed_at','expires_at','refunded_at'
    ];
    protected $casts = [
        'amount'      => 'decimal:6',
        'claimed_at'  => 'datetime',
        'expires_at'  => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function transaction() { return $this->belongsTo(Transaction::class); }
    public function claimedBy()   { return $this->belongsTo(User::class, 'claimed_by'); }

    public function scopePending($q)  { return $q->where('status', 'pending'); }
    public function scopeExpired($q)  { return $q->where('status', 'pending')->where('expires_at', '<', now()); }
}
