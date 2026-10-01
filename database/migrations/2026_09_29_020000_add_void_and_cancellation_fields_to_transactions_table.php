<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'cancellation_reason')) {
                $table->string('cancellation_reason', 255)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('transactions', 'cancelled_at')) {
                $table->dateTime('cancelled_at')->nullable()->after('cancellation_reason');
            }
            if (!Schema::hasColumn('transactions', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
                $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'cancelled_by')) {
                $table->dropForeign(['cancelled_by']);
                $table->dropColumn('cancelled_by');
            }
            if (Schema::hasColumn('transactions', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            if (Schema::hasColumn('transactions', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
        });
    }
};
