<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source',
        'direction',
        'provider_reference',
        'status',
        'outcome',
        'signature_valid',
        'payload',
        'headers',
        'error',
        'ip_address',
        'received_at',
    ];

    protected $casts = [
        'payload'        => 'array',
        'headers'        => 'array',
        'signature_valid'=> 'boolean',
        'received_at'    => 'datetime',
    ];

    // Webhook logs are immutable
    public function save(array $options = [])
    {
        if (!$this->exists) return parent::save($options);
        throw new \RuntimeException('Webhook logs are immutable and cannot be updated.');
    }
}
