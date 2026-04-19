<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Recipient extends Model
{
    protected $fillable = [
        'user_id','full_name','phone','country_code','payment_method',
        'mobile_network','mobile_number','bank_name',
        'bank_account_number','bank_branch_code','is_verified','is_active'
    ];
    protected $hidden = [
        'full_name_encrypted',
        'bank_account_encrypted',
        'phone_encrypted',
        'phone_hash',
        'deleted_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function user()         { return $this->belongsTo(User::class); }
    public function transactions() { return $this->hasMany(Transaction::class); }
}
