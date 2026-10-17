<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Services\AccountingService;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('transactions', 'is_open_bill')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->boolean('is_open_bill')->default(false)->after('order_type')->index();
            });
        }

        // Backfill is_open_bill for existing open bill transactions
        try {
            // 1. Transactions currently HOLD or SPLIT_CLOSED
            DB::table('transactions')
                ->whereIn('status', ['HOLD', 'SPLIT_CLOSED'])
                ->update(['is_open_bill' => 1]);

            // 2. Transactions with notes mentioning open bill
            DB::table('transactions')
                ->where('notes', 'like', '%open bill%')
                ->update(['is_open_bill' => 1]);

            // 3. Transactions linked to journal entries with 'Open Bill' description
            $openBillTrxIds = DB::table('journal_entries')
                ->where('reference_type', 'TRANSACTION')
                ->where('description', 'like', '%Open Bill%')
                ->pluck('reference_id')
                ->filter()
                ->unique()
                ->all();

            if (!empty($openBillTrxIds)) {
                DB::table('transactions')
                    ->whereIn('id', $openBillTrxIds)
                    ->update(['is_open_bill' => 1]);
            }

            // 4. Also mark all transactions with the same order_number as any known open bill
            $openBillOrderNumbers = DB::table('transactions')
                ->where('is_open_bill', 1)
                ->pluck('order_number')
                ->filter()
                ->unique()
                ->all();

            if (!empty($openBillOrderNumbers)) {
                DB::table('transactions')
                    ->whereIn('order_number', $openBillOrderNumbers)
                    ->update(['is_open_bill' => 1]);
            }
        } catch (\Throwable $e) {
            // Non-blocking log
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'is_open_bill')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex(['is_open_bill']);
                $table->dropColumn('is_open_bill');
            });
        }
    }
};
