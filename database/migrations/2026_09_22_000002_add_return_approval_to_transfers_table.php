<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('transfers', 'return_status')) {
                $table->string('return_status', 30)->default('NONE')->after('return_disposition')->comment('NONE, PENDING, APPROVED, REJECTED');
            }
            if (!Schema::hasColumn('transfers', 'return_approved_at')) {
                $table->timestamp('return_approved_at')->nullable()->after('returned_by');
            }
            if (!Schema::hasColumn('transfers', 'return_approved_by')) {
                $table->foreignId('return_approved_by')->nullable()->after('return_approved_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('transfers', 'return_approval_notes')) {
                $table->text('return_approval_notes')->nullable()->after('return_approved_by');
            }
            if (!Schema::hasColumn('transfers', 'return_rejected_at')) {
                $table->timestamp('return_rejected_at')->nullable()->after('return_approval_notes');
            }
            if (!Schema::hasColumn('transfers', 'return_rejected_by')) {
                $table->foreignId('return_rejected_by')->nullable()->after('return_rejected_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('transfers', 'return_rejected_reason')) {
                $table->text('return_rejected_reason')->nullable()->after('return_rejected_by');
            }
        });

        Schema::table('transfer_items', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_items', 'return_approved_qty')) {
                $table->decimal('return_approved_qty', 15, 4)->default(0)->after('returned_qty');
            }
            if (!Schema::hasColumn('transfer_items', 'return_rejected_qty')) {
                $table->decimal('return_rejected_qty', 15, 4)->default(0)->after('return_approved_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'return_approved_by')) {
                $table->dropForeign(['return_approved_by']);
            }
            if (Schema::hasColumn('transfers', 'return_rejected_by')) {
                $table->dropForeign(['return_rejected_by']);
            }
            $table->dropColumn([
                'return_status',
                'return_approved_at',
                'return_approved_by',
                'return_approval_notes',
                'return_rejected_at',
                'return_rejected_by',
                'return_rejected_reason'
            ]);
        });

        Schema::table('transfer_items', function (Blueprint $table) {
            $table->dropColumn(['return_approved_qty', 'return_rejected_qty']);
        });
    }
};
