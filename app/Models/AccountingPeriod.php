<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AccountingPeriod extends Model
{
    protected $fillable = [
        'name',
        'starts_on',
        'ends_on',
        'status',
        'opened_by',
        'closed_by',
        'locked_by',
        'opened_at',
        'closed_at',
        'locked_at',
        'trial_balance_snapshot',
        'balance_sheet_snapshot',
        'profit_loss_snapshot',
        'cash_flow_snapshot',
        'locked_trial_balance',
        'locked_balance_sheet',
        'locked_profit_loss',
        'locked_cash_flow',
        'total_assets',
        'total_liabilities',
        'total_equity',
        'net_profit',
        'net_cash_change',
        'notes',
    ];

    protected $casts = [
        'starts_on'               => 'date',
        'ends_on'                 => 'date',
        'opened_at'               => 'datetime',
        'closed_at'               => 'datetime',
        'locked_at'               => 'datetime',
        'trial_balance_snapshot'  => 'array',
        'balance_sheet_snapshot'  => 'array',
        'profit_loss_snapshot'    => 'array',
        'cash_flow_snapshot'      => 'array',
        'locked_trial_balance'    => 'array',
        'locked_balance_sheet'    => 'array',
        'locked_profit_loss'      => 'array',
        'locked_cash_flow'        => 'array',
        'total_assets'            => 'decimal:6',
        'total_liabilities'       => 'decimal:6',
        'total_equity'            => 'decimal:6',
        'net_profit'              => 'decimal:6',
        'net_cash_change'         => 'decimal:6',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function openedBy()  { return $this->belongsTo(User::class, 'opened_by'); }
    public function closedBy()  { return $this->belongsTo(User::class, 'closed_by'); }
    public function lockedBy()  { return $this->belongsTo(User::class, 'locked_by'); }

    // ── State checks ─────────────────────────────────────────────────────────

    public function isOpen(): bool   { return $this->status === 'open'; }
    public function isClosed(): bool { return $this->status === 'closed'; }
    public function isLocked(): bool { return $this->status === 'locked'; }

    /**
     * Returns true if the given date falls within this period.
     */
    public function containsDate(Carbon $date): bool
    {
        return $date->between(
            $this->starts_on->startOfDay(),
            $this->ends_on->endOfDay()
        );
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeOpen($q)   { return $q->where('status', 'open'); }
    public function scopeClosed($q) { return $q->where('status', 'closed'); }
    public function scopeLocked($q) { return $q->where('status', 'locked'); }

    /**
     * Find the period that contains a given date, if any.
     */
    public static function forDate(Carbon $date): ?self
    {
        return static::where('starts_on', '<=', $date->toDateString())
            ->where('ends_on', '>=', $date->toDateString())
            ->first();
    }

    /**
     * Find the currently open period, if any.
     */
    public static function current(): ?self
    {
        return static::open()->first();
    }
}
