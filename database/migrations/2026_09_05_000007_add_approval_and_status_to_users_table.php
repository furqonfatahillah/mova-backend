<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->after('role'); // pending, active, rejected, suspended
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('outlet_id')->nullable()->after('approved_at')->constrained('outlets')->nullOnDelete();
        });

        // Set all existing users to active
        DB::table('users')->update([
            'status'      => 'active',
            'approved_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['outlet_id']);
            $table->dropColumn(['status', 'approved_by', 'approved_at', 'outlet_id']);
        });
    }
};
