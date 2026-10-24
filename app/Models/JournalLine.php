<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'account_code',
        'outlet_id',
        'entry_date',
        'debit',
        'credit',
        'memo',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'entry_date' => 'date:Y-m-d',
        'debit'      => 'float',
        'credit'     => 'float',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
