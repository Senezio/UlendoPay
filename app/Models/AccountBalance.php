<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AccountBalance extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'account_id','balance','currency_code',
        'last_journal_entry_id','last_updated_at'
    ];
    protected $casts = [
        'balance'         => 'decimal:6',
        'last_updated_at' => 'datetime'
    ];

    public function account() { return $this->belongsTo(Account::class); }
}
