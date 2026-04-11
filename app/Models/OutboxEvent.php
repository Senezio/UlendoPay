<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_type','transaction_id','payload','status',
        'attempts','max_attempts','failure_reason',
        'next_attempt_at','processed_at'
    ];
    protected $casts = [
        'payload'         => 'array',
        'next_attempt_at' => 'datetime',
        'processed_at'    => 'datetime',
    ];

    public function transaction() { return $this->belongsTo(Transaction::class); }

    public function scopePending($q)
    {
        return $q->where('status', 'pending')
                 ->where('next_attempt_at', '<=', now())
                 ->where('attempts', '<', \DB::raw('max_attempts'));
    }
}
