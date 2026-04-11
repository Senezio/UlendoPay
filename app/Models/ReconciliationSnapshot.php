<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ReconciliationSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'account_id','snapshot_date','computed_balance',
        'expected_balance','variance','status','notes',
        'resolved_by','resolved_at'
    ];
    protected $casts = [
        'snapshot_date'    => 'date',
        'computed_balance' => 'decimal:6',
        'expected_balance' => 'decimal:6',
        'variance'         => 'decimal:6',
        'resolved_at'      => 'datetime',
    ];

    public function account()  { return $this->belongsTo(Account::class); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }

    public function scopeMismatched($q) { return $q->where('status', 'mismatch'); }
}
