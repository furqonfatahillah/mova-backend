<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Buat tabel businesses
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('owner_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('package_type')->default('pro'); // starter, pro, enterprise
            $table->unsignedInteger('max_outlets')->default(5);
            $table->string('status')->default('active'); // active, trial, suspended, expired
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // 2. Tambahkan kolom business_id ke entitas terkait
        $tables = [
            'users',
            'outlets',
            'ingredients',
            'menus',
            'shifts',
            'transactions',
            'stock_movements',
            'transfers',
            'opnames',
        ];

        foreach ($tables as $tbl) {
            if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl, 'business_id')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->nullOnDelete();
                });
            }
        }

        // 3. Masukkan Bisnis Default untuk data yang sudah ada
        $now = now();
        $defaultBusinessId = DB::table('businesses')->insertGetId([
            'name'         => 'Maroa F&B Group',
            'slug'         => 'maroa-fb-group',
            'owner_name'   => 'Owner Maroa',
            'email'        => 'owner@mova.id',
            'phone'        => '0812-3456-7890',
            'address'      => 'Jl. Boulevard No. 1, Panakkukang, Makassar',
            'package_type' => 'enterprise',
            'max_outlets'  => 10,
            'status'       => 'active',
            'expires_at'   => $now->copy()->addYears(5),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // 4. Update data existing agar terikat ke bisnis default
        foreach ($tables as $tbl) {
            if (Schema::hasTable($tbl)) {
                DB::table($tbl)->whereNull('business_id')->update(['business_id' => $defaultBusinessId]);
            }
        }

        // 5. Update user owner@mova.id menjadi role owner_bisnis
        DB::table('users')->where('email', 'owner@mova.id')->update([
            'role'        => 'owner_bisnis',
            'business_id' => $defaultBusinessId,
            'status'      => 'active',
        ]);
    }

    public function down(): void
    {
        $tables = [
            'opnames',
            'transfers',
            'stock_movements',
            'transactions',
            'shifts',
            'menus',
            'ingredients',
            'outlets',
            'users',
        ];

        foreach ($tables as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'business_id')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('business_id');
                });
            }
        }

        Schema::dropIfExists('businesses');
    }
};
