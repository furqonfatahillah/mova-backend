<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add deposit approval columns to shifts
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'deposit_status')) {
                $table->enum('deposit_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')->after('status');
            }
            if (!Schema::hasColumn('shifts', 'deposit_amount')) {
                $table->decimal('deposit_amount', 15, 2)->nullable()->after('deposit_status');
            }
            if (!Schema::hasColumn('shifts', 'deposit_account')) {
                $table->string('deposit_account', 30)->nullable()->after('deposit_amount');
            }
            if (!Schema::hasColumn('shifts', 'deposit_notes')) {
                $table->text('deposit_notes')->nullable()->after('deposit_account');
            }
            if (!Schema::hasColumn('shifts', 'deposit_submitted_at')) {
                $table->dateTime('deposit_submitted_at')->nullable()->after('deposit_notes');
            }
            if (!Schema::hasColumn('shifts', 'deposit_submitted_by')) {
                $table->foreignId('deposit_submitted_by')->nullable()->after('deposit_submitted_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('shifts', 'deposit_approved_at')) {
                $table->dateTime('deposit_approved_at')->nullable()->after('deposit_submitted_by');
            }
            if (!Schema::hasColumn('shifts', 'deposit_approved_by')) {
                $table->foreignId('deposit_approved_by')->nullable()->after('deposit_approved_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('shifts', 'deposit_rejection_reason')) {
                $table->text('deposit_rejection_reason')->nullable()->after('deposit_approved_by');
            }
        });

        // 2. Add approval columns to cash_transactions
        Schema::table('cash_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_transactions', 'status')) {
                $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('APPROVED')->after('category');
            }
            if (!Schema::hasColumn('cash_transactions', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('cash_transactions', 'approved_at')) {
                $table->dateTime('approved_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('cash_transactions', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('approved_at');
            }
        });

        // 3. Populate existing SETORAN_KASIR cash transactions and shift deposit status as APPROVED
        try {
            $depositTxs = DB::table('cash_transactions')
                ->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                ->get();

            foreach ($depositTxs as $tx) {
                DB::table('cash_transactions')
                    ->where('id', $tx->id)
                    ->update([
                        'status'      => 'APPROVED',
                        'approved_by' => $tx->user_id,
                        'approved_at' => $tx->created_at ?? now(),
                    ]);

                $shiftId = $tx->shift_id;
                if (!$shiftId && preg_match('/Shift #(\d+)/i', ($tx->name ?? '') . ' ' . ($tx->notes ?? ''), $m)) {
                    $shiftId = (int)$m[1];
                }

                if ($shiftId) {
                    DB::table('shifts')
                        ->where('id', $shiftId)
                        ->update([
                            'deposit_status'       => 'APPROVED',
                            'deposit_amount'       => $tx->amount,
                            'deposit_account'      => $tx->account,
                            'deposit_notes'        => $tx->notes,
                            'deposit_submitted_at' => $tx->created_at ?? now(),
                            'deposit_submitted_by' => $tx->user_id,
                            'deposit_approved_at'  => $tx->created_at ?? now(),
                            'deposit_approved_by'  => $tx->user_id,
                        ]);
                }
            }
        } catch (\Throwable $e) {
            // Ignore if data migration is partial
        }
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['deposit_submitted_by']);
            $table->dropForeign(['deposit_approved_by']);
            $table->dropColumn([
                'deposit_status',
                'deposit_amount',
                'deposit_account',
                'deposit_notes',
                'deposit_submitted_at',
                'deposit_submitted_by',
                'deposit_approved_at',
                'deposit_approved_by',
                'deposit_rejection_reason',
            ]);
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'status',
                'approved_by',
                'approved_at',
                'rejection_reason',
            ]);
        });
    }
};
