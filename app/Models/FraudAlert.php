<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FraudAlert extends Model
{
    protected $fillable = [
        'transaction_id',
        'user_id',
        'rule_triggered',
        'risk_score',
        'context',
        'status',
        'reviewed_by',
        'reviewed_at',
        'resolution_notes',
    ];

    protected $casts = [
        'context'     => 'array',
        'risk_score'  => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function user()        { return $this->belongsTo(User::class); }
    public function transaction()  { return $this->belongsTo(Transaction::class); }
    public function reviewer()     { return $this->belongsTo(User::class, 'reviewed_by'); }
}
