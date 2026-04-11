<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'key','request_hash','user_id','endpoint',
        'response_body','response_status','status','locked_until','expires_at'
    ];
    protected $casts = [
        'response_body' => 'array',
        'locked_until'  => 'datetime',
        'expires_at'    => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
