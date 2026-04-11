<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class JournalEntryGroup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid','currency_code','total_amount','type','reference',
        'status','reversal_of_group_id','description','is_balanced','posted_at'
    ];
    protected $casts = [
        'is_balanced' => 'boolean',
        'posted_at'   => 'datetime',
        'total_amount'=> 'decimal:6',
    ];

    public function entries()    { return $this->hasMany(JournalEntry::class, 'group_id'); }
    public function reversalOf() { return $this->belongsTo(JournalEntryGroup::class, 'reversal_of_group_id'); }
    public function reversals()  { return $this->hasMany(JournalEntryGroup::class, 'reversal_of_group_id'); }
    public function transaction() { return $this->hasOne(Transaction::class, 'journal_entry_group_id'); }
}
