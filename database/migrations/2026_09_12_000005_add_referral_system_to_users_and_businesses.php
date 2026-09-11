<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add referral columns to users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 50)->nullable()->unique()->after('business_id');
            }
            if (!Schema::hasColumn('users', 'referred_by_id')) {
                $table->foreignId('referred_by_id')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
            }
        });

        // 2. Add referral columns to businesses table
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'referred_by_id')) {
                $table->foreignId('referred_by_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('businesses', 'referral_code_used')) {
                $table->string('referral_code_used', 50)->nullable()->after('referred_by_id');
            }
        });

        // 3. Generate unique referral codes for all existing users
        $users = DB::table('users')->whereNull('referral_code')->get();
        foreach ($users as $user) {
            $code = $this->generateUniqueCode();
            DB::table('users')->where('id', $user->id)->update(['referral_code' => $code]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'referred_by_id')) {
                $table->dropForeign(['referred_by_id']);
                $table->dropColumn('referred_by_id');
            }
            if (Schema::hasColumn('businesses', 'referral_code_used')) {
                $table->dropColumn('referral_code_used');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referred_by_id')) {
                $table->dropForeign(['referred_by_id']);
                $table->dropColumn('referred_by_id');
            }
            if (Schema::hasColumn('users', 'referral_code')) {
                $table->dropColumn('referral_code');
            }
        });
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'REF-' . strtoupper(Str::random(6));
        } while (DB::table('users')->where('referral_code', $code)->exists());

        return $code;
    }
};
