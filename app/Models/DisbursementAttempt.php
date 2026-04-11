<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class DisbursementAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'transaction_id','partner_id','attempt_number',
        'request_payload','response_payload','status',
        'response_time_ms','failure_reason','attempted_at','responded_at'
    ];
    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'attempted_at'     => 'datetime',
        'responded_at'     => 'datetime',
    ];

    public function transaction() { return $this->belongsTo(Transaction::class); }
    public function partner()     { return $this->belongsTo(Partner::class); }
}
