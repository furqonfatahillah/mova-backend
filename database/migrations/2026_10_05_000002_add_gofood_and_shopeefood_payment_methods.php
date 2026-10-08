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
                ['code' => 'GOFOOD'],
                [
                    'name'       => 'GoFood / Gojek',
                    'type'       => 'OTHER',
                    'active'     => true,
                    'sort_order' => 7,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('payment_methods')->updateOrInsert(
                ['code' => 'SHOPEEFOOD'],
                [
                    'name'       => 'ShopeeFood',
                    'type'       => 'OTHER',
                    'active'     => true,
                    'sort_order' => 8,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_methods')) {
            DB::table('payment_methods')->whereIn('code', ['GOFOOD', 'SHOPEEFOOD'])->delete();
        }
    }
};
