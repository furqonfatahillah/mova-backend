<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $fillable = [
        'business_id',
        'outlet_id',
        'entry_no',
        'entry_date',
        'entry_type',
        'reference_type',
        'reference_id',
        'description',
        'total_debit',
        'total_credit',
        'is_balanced',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'entry_date'   => 'date:Y-m-d',
        'total_debit'  => 'float',
        'total_credit' => 'float',
        'is_balanced'  => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate sequential Journal Entry Number.
     * Format: JE-YYYYMM-0001
     */
    public static function generateEntryNo(int $businessId, ?string $date = null): string
    {
        $d = $date ? strtotime($date) : time();
        $prefix = 'JE-' . date('Ym', $d) . '-';

        $last = static::where('business_id', $businessId)
            ->where('entry_no', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();

        $num = 1;
        if ($last && preg_match('/' . preg_quote($prefix, '/') . '(\d+)/', $last->entry_no, $m)) {
            $num = (int)$m[1] + 1;
        }

        while (static::where('business_id', $businessId)->where('entry_no', $prefix . str_pad((string)$num, 4, '0', STR_PAD_LEFT))->exists()) {
            $num++;
        }

        return $prefix . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
    }
}
