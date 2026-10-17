<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderNumberService
{
    /**
     * Generate the next unique order number atomically and thread-safe.
     * Guaranteed to prevent race conditions and cross-tenant collision.
     *
     * @param int $businessId Business tenant ID
     * @param string $date Operational date (YYYY-MM-DD)
     * @return string e.g. TRX-20261015-0001
     */
    public static function generateNextOrderNumber(int $businessId, string $date): string
    {
        $datePrefix = date('Ymd', strtotime($date));
        $sequenceKey = "TRX-{$datePrefix}";

        $generator = function () use ($businessId, $datePrefix, $sequenceKey) {
            // 1. Acquire pessimistic row lock on sequence record
            $seq = DB::table('order_sequences')
                ->where('business_id', $businessId)
                ->where('sequence_key', $sequenceKey)
                ->lockForUpdate()
                ->first();

            if (!$seq) {
                // If sequence record does not exist yet for today, find existing max sequence from transactions
                // Note: Query is strictly scoped with parameter grouping to prevent SQL OR precedence bug
                $existingOrders = Transaction::withoutGlobalScopes()
                    ->where('business_id', $businessId)
                    ->where(function ($q) use ($datePrefix) {
                        $q->where('order_number', 'like', "TRX-{$datePrefix}-%")
                          ->orWhere('parent_order_number', 'like', "TRX-{$datePrefix}-%");
                    })
                    ->pluck('order_number');

                $maxSeq = 0;
                foreach ($existingOrders as $ord) {
                    if ($ord && preg_match("/TRX-{$datePrefix}-(\d+)/", $ord, $matches)) {
                        $val = (int)$matches[1];
                        if ($val > $maxSeq) {
                            $maxSeq = $val;
                        }
                    }
                }

                $nextSeq = $maxSeq + 1;

                try {
                    DB::table('order_sequences')->insert([
                        'business_id'   => $businessId,
                        'sequence_key'  => $sequenceKey,
                        'last_sequence' => $nextSeq,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                } catch (QueryException $e) {
                    // In case of concurrent insert race on first record today, re-fetch with lock and increment
                    $seq = DB::table('order_sequences')
                        ->where('business_id', $businessId)
                        ->where('sequence_key', $sequenceKey)
                        ->lockForUpdate()
                        ->first();

                    $nextSeq = ($seq ? (int)$seq->last_sequence : $maxSeq) + 1;
                    DB::table('order_sequences')
                        ->where('business_id', $businessId)
                        ->where('sequence_key', $sequenceKey)
                        ->update([
                            'last_sequence' => $nextSeq,
                            'updated_at'    => now(),
                        ]);
                }
            } else {
                $nextSeq = (int)$seq->last_sequence + 1;
                DB::table('order_sequences')
                    ->where('id', $seq->id)
                    ->update([
                        'last_sequence' => $nextSeq,
                        'updated_at'    => now(),
                    ]);
            }

            return sprintf("TRX-%s-%04d", $datePrefix, $nextSeq);
        };

        // If called inside an existing DB transaction, run directly to share the lock context.
        // Otherwise wrap in a standalone transaction to ensure the row-lock is atomic.
        if (DB::transactionLevel() > 0) {
            return $generator();
        }

        return DB::transaction($generator);
    }
}
