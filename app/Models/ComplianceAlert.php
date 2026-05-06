<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceAlert extends Model
{
    protected $fillable = [
        'user_id',
        'compliance_screen_id',
        'alert_type',
        'severity',
        'match_score',
        'matched_name',
        'status',
        'triggered_by',
        'reviewed_by',
        'resolution_notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function screen()
    {
        return $this->belongsTo(ComplianceScreen::class, 'compliance_screen_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
