<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urgent_notes', function (Blueprint $table) {
            if (!Schema::hasColumn('urgent_notes', 'approval_requested_by')) {
                $table->unsignedBigInteger('approval_requested_by')->nullable()->after('notes');
                $table->foreign('approval_requested_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('urgent_notes', 'approval_requested_at')) {
                $table->dateTime('approval_requested_at')->nullable()->after('approval_requested_by');
            }
            if (!Schema::hasColumn('urgent_notes', 'requested_notes')) {
                $table->text('requested_notes')->nullable()->after('approval_requested_at');
            }
            if (!Schema::hasColumn('urgent_notes', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('requested_notes');
                $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('urgent_notes', 'approved_at')) {
                $table->dateTime('approved_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('urgent_notes', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable()->after('approved_at');
                $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('urgent_notes', 'rejected_at')) {
                $table->dateTime('rejected_at')->nullable()->after('rejected_by');
            }
            if (!Schema::hasColumn('urgent_notes', 'reject_reason')) {
                $table->text('reject_reason')->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('urgent_notes', function (Blueprint $table) {
            if (Schema::hasColumn('urgent_notes', 'rejected_by')) {
                $table->dropForeign(['rejected_by']);
                $table->dropColumn('rejected_by');
            }
            if (Schema::hasColumn('urgent_notes', 'approved_by')) {
                $table->dropForeign(['approved_by']);
                $table->dropColumn('approved_by');
            }
            if (Schema::hasColumn('urgent_notes', 'approval_requested_by')) {
                $table->dropForeign(['approval_requested_by']);
                $table->dropColumn('approval_requested_by');
            }
            if (Schema::hasColumn('urgent_notes', 'approval_requested_at')) {
                $table->dropColumn('approval_requested_at');
            }
            if (Schema::hasColumn('urgent_notes', 'requested_notes')) {
                $table->dropColumn('requested_notes');
            }
            if (Schema::hasColumn('urgent_notes', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
            if (Schema::hasColumn('urgent_notes', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }
            if (Schema::hasColumn('urgent_notes', 'reject_reason')) {
                $table->dropColumn('reject_reason');
            }
        });
    }
};
