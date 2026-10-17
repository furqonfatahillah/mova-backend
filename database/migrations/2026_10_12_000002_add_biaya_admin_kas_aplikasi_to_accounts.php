<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Business;
use App\Models\Account;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('accounts')) {
            $businesses = Business::all();
            foreach ($businesses as $biz) {
                Account::seedForBusiness($biz->id);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('accounts')) {
            DB::table('accounts')
                ->where('code', '6-61010')
                ->orWhere('code', 'like', '6-61010.%')
                ->delete();
        }
    }
};
