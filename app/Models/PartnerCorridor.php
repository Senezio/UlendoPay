<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PartnerCorridor extends Model
{
    protected $fillable = [
        'partner_id','from_currency','to_currency',
        'min_amount','max_amount','priority','fee_percent','fee_flat','is_active'
    ];
    protected $casts = ['is_active' => 'boolean', 'fee_percent' => 'decimal:4', 'fee_flat' => 'decimal:6'];

    public function partner() { return $this->belongsTo(Partner::class); }
}
