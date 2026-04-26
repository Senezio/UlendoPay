<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class KycRecord extends Model
{
    protected $fillable = [
        'user_id','document_type','document_number','file_path',
        'status','requested_tier','rejection_reason','reviewed_by','reviewed_at'
    ];
    protected $casts = ['reviewed_at' => 'datetime'];


    // Encrypt document number on save — sensitive PII
    public function setDocumentNumberAttribute(?string $value): void
    {
        $this->attributes['document_number'] = $value ? encrypt($value) : null;
    }

    // Decrypt document number on read
    public function getDocumentNumberAttribute(): ?string
    {
        try {
            return $this->attributes['document_number']
                ? decrypt($this->attributes['document_number'])
                : null;
        } catch (\Throwable) {
            // Legacy plaintext fallback
            return $this->attributes['document_number'] ?? null;
        }
    }

    public function user() { return $this->belongsTo(User::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
