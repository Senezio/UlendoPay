<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id','action','entity_type','entity_id',
        'old_values','new_values','ip_address','user_agent','notes'
    ];
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }

    // Audit logs are immutable — block all updates
    public function save(array $options = [])
    {
        if (! $this->exists) return parent::save($options);
        throw new \RuntimeException('Audit logs are immutable and cannot be updated.');
    }
}
