<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PepEntry extends Model
{
    protected $fillable = [
        'source',
        'name',
        'normalized_name',
        'aliases',
        'country_code',
        'position',
        'risk_level',
        'date_of_birth',
        'metadata',
        'active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'aliases'        => 'array',
            'metadata'       => 'array',
            'active'         => 'boolean',
            'date_of_birth'  => 'date',
            'last_synced_at' => 'datetime',
        ];
    }

    public function complianceScreens()
    {
        return $this->hasMany(ComplianceScreen::class);
    }
}
