<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Obsolete: SHIFT_CLOSING entries are now properly reconciled with Kas Kecil Kasir (1-11002),
        // Pendapatan Lain-Lain (4-41002), and Biaya Lain-Lain (6-61008) in double-entry accounting.
        // No entries should be forcibly deleted here.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse needed
    }
};
