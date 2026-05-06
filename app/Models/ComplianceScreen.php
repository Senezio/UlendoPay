<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceScreen extends Model
{
    protected $fillable = [
        'user_id',
        'screen_type',
        'input_name',
        'match_score',
        'sanctions_entry_id',
        'pep_entry_id',
        'result',
        'action_taken',
        'match_details',
        'triggered_by',
        'reviewed_by',
        'screened_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'match_details' => 'array',
            'screened_at'   => 'datetime',
            'reviewed_at'   => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sanctionsEntry()
    {
        return $this->belongsTo(SanctionsEntry::class);
    }

    public function pepEntry()
    {
        return $this->belongsTo(PepEntry::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function alert()
    {
        return $this->hasOne(ComplianceAlert::class);
    }
}
