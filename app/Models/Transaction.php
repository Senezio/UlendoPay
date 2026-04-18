<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'reference_number','sender_id','recipient_id','rate_lock_id',
        'partner_id','journal_entry_group_id',
        'send_amount','send_currency','receive_amount','receive_currency',
        'locked_rate','fee_amount','guarantee_contribution','partner_reference',
        'status','failure_reason','disbursement_attempts',
        'last_attempt_at','next_attempt_at','escrowed_at','completed_at','refunded_at',
        'flagged_for_review','risk_score','fraud_context',
    ];
    protected $casts = [
        'send_amount'            => 'decimal:6',
        'receive_amount'         => 'decimal:6',
        'locked_rate'            => 'decimal:8',
        'fee_amount'             => 'decimal:6',
        'guarantee_contribution' => 'decimal:6',
        'last_attempt_at'        => 'datetime',
        'next_attempt_at'        => 'datetime',
        'escrowed_at'            => 'datetime',
        'completed_at'           => 'datetime',
        'refunded_at'            => 'datetime',
        'fraud_context'          => 'array',
    ];

    public function sender()           { return $this->belongsTo(User::class, 'sender_id'); }
    public function recipient()        { return $this->belongsTo(Recipient::class); }
    public function rateLock()         { return $this->belongsTo(RateLock::class); }
    public function partner()          { return $this->belongsTo(Partner::class); }
    public function journalGroup()     { return $this->belongsTo(JournalEntryGroup::class, 'journal_entry_group_id'); }
    public function disbursements()    { return $this->hasMany(DisbursementAttempt::class); }
    public function outboxEvents()     { return $this->hasMany(OutboxEvent::class); }

    public function scopePending($q)   { return $q->whereIn('status', ['escrowed','processing','retrying']); }
    public function scopeCompleted($q) { return $q->where('status', 'completed'); }
}
