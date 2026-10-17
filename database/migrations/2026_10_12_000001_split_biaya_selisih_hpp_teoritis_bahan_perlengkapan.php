<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use App\Models\Business;
use App\Models\Account;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Memisahkan akun 5-51007 (Biaya Selisih HPP Teoritis) menjadi dua akun terpisah:
     * - 5-51007: Biaya Selisih HPP Teoritis BAHAN (bahan baku)
     * - 5-51008: Biaya Selisih HPP Teoritis PERLENGKAPAN (kemasan / perlengkapan)
     */
    public function up(): void
    {
        if (Schema::hasTable('accounts')) {
            $businesses = Business::all();
            foreach ($businesses as $biz) {
                // Seed akan otomatis:
                // 1. Update nama 5-51007 -> BIAYA SELISIH HPP TEORITIS BAHAN
                // 2. Tambah akun baru 5-51008 -> BIAYA SELISIH HPP TEORITIS PERLENGKAPAN
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
            // Hapus akun 5-51008 dan sub-akunnya
            \Illuminate\Support\Facades\DB::table('accounts')
                ->where('code', '5-51008')
                ->orWhere('code', 'like', '5-51008.%')
                ->delete();

            // Kembalikan nama 5-51007 ke nama asal (gabungan)
            \Illuminate\Support\Facades\DB::table('accounts')
                ->where('code', '5-51007')
                ->update([
                    'name'        => 'BIAYA SELISIH HPP TEORITIS',
                    'description' => 'Biaya selisih HPP teoritis untuk pemakaian bahan/produk saat stok minus/defisit di kartu stok',
                    'updated_at'  => now(),
                ]);
        }
    }
};
