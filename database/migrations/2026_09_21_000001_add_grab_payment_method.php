<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_methods')) {
            DB::table('payment_methods')->updateOrInsert(
                ['code' => 'GRAB'],
                [
                    'name'       => 'Grab / GrabFood',
                    'type'       => 'OTHER',
                    'active'     => true,
                    'sort_order' => 6,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_methods')) {
            DB::table('payment_methods')->where('code', 'GRAB')->delete();
        }
    }
};
