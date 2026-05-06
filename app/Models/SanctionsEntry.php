<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SanctionsEntry extends Model
{
    protected $fillable = [
        'source',
        'entity_type',
        'name',
        'normalized_name',
        'aliases',
        'country_codes',
        'date_of_birth',
        'list_reference',
        'metadata',
        'active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'aliases'        => 'array',
            'country_codes'  => 'array',
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
