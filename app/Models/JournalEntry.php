<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'group_id','account_id','entry_type',
        'amount','currency_code','description','posted_at'
    ];
    protected $casts = [
        'amount'    => 'decimal:6',
        'posted_at' => 'datetime',
    ];

    public function group()   { return $this->belongsTo(JournalEntryGroup::class, 'group_id'); }
    public function account() { return $this->belongsTo(Account::class); }
}
