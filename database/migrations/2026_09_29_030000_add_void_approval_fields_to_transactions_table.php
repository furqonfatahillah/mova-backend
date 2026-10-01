<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'void_requested_by')) {
                $table->unsignedBigInteger('void_requested_by')->nullable()->after('cancelled_by');
                $table->foreign('void_requested_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('transactions', 'void_requested_at')) {
                $table->dateTime('void_requested_at')->nullable()->after('void_requested_by');
            }
            if (!Schema::hasColumn('transactions', 'void_approved_by')) {
                $table->unsignedBigInteger('void_approved_by')->nullable()->after('void_requested_at');
                $table->foreign('void_approved_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('transactions', 'void_approved_at')) {
                $table->dateTime('void_approved_at')->nullable()->after('void_approved_by');
            }
            if (!Schema::hasColumn('transactions', 'void_rejected_by')) {
                $table->unsignedBigInteger('void_rejected_by')->nullable()->after('void_approved_at');
                $table->foreign('void_rejected_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('transactions', 'void_rejected_at')) {
                $table->dateTime('void_rejected_at')->nullable()->after('void_rejected_by');
            }
            if (!Schema::hasColumn('transactions', 'void_reject_reason')) {
                $table->string('void_reject_reason', 255)->nullable()->after('void_rejected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'void_rejected_by')) {
                $table->dropForeign(['void_rejected_by']);
                $table->dropColumn('void_rejected_by');
            }
            if (Schema::hasColumn('transactions', 'void_approved_by')) {
                $table->dropForeign(['void_approved_by']);
                $table->dropColumn('void_approved_by');
            }
            if (Schema::hasColumn('transactions', 'void_requested_by')) {
                $table->dropForeign(['void_requested_by']);
                $table->dropColumn('void_requested_by');
            }
            if (Schema::hasColumn('transactions', 'void_reject_reason')) {
                $table->dropColumn('void_reject_reason');
            }
            if (Schema::hasColumn('transactions', 'void_rejected_at')) {
                $table->dropColumn('void_rejected_at');
            }
            if (Schema::hasColumn('transactions', 'void_approved_at')) {
                $table->dropColumn('void_approved_at');
            }
            if (Schema::hasColumn('transactions', 'void_requested_at')) {
                $table->dropColumn('void_requested_at');
            }
        });
    }
};
