<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('transfers', 'returned_at')) {
                $table->timestamp('returned_at')->nullable()->after('received_notes');
            }
            if (!Schema::hasColumn('transfers', 'returned_by')) {
                $table->foreignId('returned_by')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('transfers', 'return_reason')) {
                $table->string('return_reason', 255)->nullable()->after('returned_by');
            }
            if (!Schema::hasColumn('transfers', 'return_disposition')) {
                $table->string('return_disposition', 50)->nullable()->after('return_reason')->comment('RECORD_AS_WASTE, RETURN_TO_SOURCE, PARTIAL');
            }
            if (!Schema::hasColumn('transfers', 'return_notes')) {
                $table->text('return_notes')->nullable()->after('return_disposition');
            }
        });

        Schema::table('transfer_items', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_items', 'returned_qty')) {
                $table->decimal('returned_qty', 15, 4)->default(0)->after('input_unit');
            }
            if (!Schema::hasColumn('transfer_items', 'received_qty')) {
                $table->decimal('received_qty', 15, 4)->nullable()->after('returned_qty');
            }
            if (!Schema::hasColumn('transfer_items', 'return_reason')) {
                $table->string('return_reason', 255)->nullable()->after('received_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'returned_by')) {
                $table->dropForeign(['returned_by']);
            }
            $table->dropColumn(['returned_at', 'returned_by', 'return_reason', 'return_disposition', 'return_notes']);
        });

        Schema::table('transfer_items', function (Blueprint $table) {
            $table->dropColumn(['returned_qty', 'received_qty', 'return_reason']);
        });
    }
};
