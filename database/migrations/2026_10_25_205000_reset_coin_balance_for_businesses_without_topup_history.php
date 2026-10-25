<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Business;
use App\Models\CoinTransaction;
use App\Models\CoinTopupRequest;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Bisnis yang tidak memiliki riwayat top up (baik di coin_transactions tipe TOPUP maupun coin_topup_requests status APPROVED)
        // harus memiliki saldo koin 0.00 (menghapus saldo koin dummy bawaan registrasi sebelumnya).
        $businesses = Business::all();

        foreach ($businesses as $business) {
            $hasTopupTx = CoinTransaction::where('business_id', $business->id)
                ->where('type', 'TOPUP')
                ->exists();

            $hasTopupReq = CoinTopupRequest::where('business_id', $business->id)
                ->where('status', 'APPROVED')
                ->exists();

            if (!$hasTopupTx && !$hasTopupReq) {
                $business->update(['coin_balance' => 0.00]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse operation needed
    }
};
