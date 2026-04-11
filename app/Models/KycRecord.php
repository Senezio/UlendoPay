<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class KycRecord extends Model
{
    protected $fillable = [
        'user_id','document_type','document_number','file_path',
        'status','rejection_reason','reviewed_by','reviewed_at'
    ];
    protected $casts = ['reviewed_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
