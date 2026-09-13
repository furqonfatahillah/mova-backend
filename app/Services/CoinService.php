<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CoinTransaction;
use Illuminate\Support\Facades\DB;

class CoinService
{
    /**
     * Check if a business has sufficient coins to complete a new order/nota.
     */
    public static function canTransact(int $businessId): array
    {
        $business = Business::find($businessId);
        if (!$business) {
            return [
                'allowed'   => false,
                'balance'   => 0.0,
                'cost'      => 1.0,
                'remaining' => 0,
                'message'   => 'Perusahaan tidak ditemukan.',
            ];
        }

        $cost = (float)($business->coins_per_transaction ?: 1.00);
        $balance = (float)($business->coin_balance ?: 0.00);
        $remaining = $cost > 0 ? (int) floor($balance / $cost) : 999999;

        if ($balance < $cost) {
            return [
                'allowed'   => false,
                'balance'   => $balance,
                'cost'      => $cost,
                'remaining' => 0,
                'message'   => "Transaksi tidak dapat diproses: Saldo koin perusahaan Anda habis (Sisa: {$balance} koin, Dibutuhkan: {$cost} koin per nota). Silakan hubungi Pemilik Website untuk top-up koin.",
            ];
        }

        return [
            'allowed'   => true,
            'balance'   => $balance,
            'cost'      => $cost,
            'remaining' => $remaining,
            'message'   => null,
        ];
    }

    /**
     * Deduct coins when an order nota is completed (PAID) using atomic row lock.
     */
    public static function deductForOrder(int $businessId, string $orderNumber, ?int $outletId = null, ?int $userId = null): ?CoinTransaction
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('businesses', 'coin_balance')) {
            return null;
        }

        return DB::transaction(function () use ($businessId, $orderNumber, $outletId, $userId) {
            $business = Business::where('id', $businessId)->lockForUpdate()->firstOrFail();

            $cost = (float)($business->coins_per_transaction ?: 1.00);
            $before = (float)($business->coin_balance ?: 0.00);

            if ($before < $cost) {
                throw new \Exception("Saldo koin transaksi perusahaan tidak mencukupi untuk nota {$orderNumber} (Sisa: {$before}, Butuh: {$cost}).");
            }

            $after = $before - $cost;
            $business->coin_balance = $after;
            $business->save();

            return CoinTransaction::create([
                'business_id'       => $business->id,
                'type'              => 'USAGE',
                'amount'            => -$cost,
                'balance_before'    => $before,
                'balance_after'     => $after,
                'payment_amount'    => null,
                'payment_reference' => null,
                'order_number'      => $orderNumber,
                'outlet_id'         => $outletId,
                'notes'             => "Pemotongan {$cost} koin untuk Nota #{$orderNumber}",
                'created_by'        => $userId,
            ]);
        });
    }

    /**
     * Top-up coins for a tenant business performed by the Website Owner (Superadmin Platform).
     */
    public static function topUp(
        int $businessId,
        float $coins,
        ?float $paymentAmount = null,
        ?string $paymentReference = null,
        ?string $notes = null,
        ?int $adminId = null
    ): CoinTransaction {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('businesses', 'coin_balance')) {
            throw new \Exception("Tabel koin belum dibuat di database server produksi. Silakan jalankan 'php artisan migrate --force' di terminal VPS server produksi Anda.");
        }

        if ($coins <= 0) {
            throw new \InvalidArgumentException('Jumlah koin top-up harus lebih besar dari 0.');
        }

        return DB::transaction(function () use ($businessId, $coins, $paymentAmount, $paymentReference, $notes, $adminId) {
            $business = Business::where('id', $businessId)->lockForUpdate()->firstOrFail();

            $before = (float)($business->coin_balance ?: 0.00);
            $after = $before + $coins;

            $business->coin_balance = $after;
            $business->save();

            return CoinTransaction::create([
                'business_id'       => $business->id,
                'type'              => 'TOPUP',
                'amount'            => $coins,
                'balance_before'    => $before,
                'balance_after'     => $after,
                'payment_amount'    => $paymentAmount,
                'payment_reference' => $paymentReference,
                'order_number'      => null,
                'outlet_id'         => null,
                'notes'             => $notes ?: "Top up {$coins} koin oleh Pemilik Website",
                'created_by'        => $adminId,
            ]);
        });
    }

    /**
     * Update the cost (coins per transaction) for a business.
     */
    public static function updateRate(int $businessId, float $coinsPerTx, ?int $adminId = null): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('businesses', 'coins_per_transaction')) {
            throw new \Exception("Tabel koin belum dibuat di database server produksi. Silakan jalankan 'php artisan migrate --force' di terminal VPS server produksi Anda.");
        }

        if ($coinsPerTx < 0) {
            throw new \InvalidArgumentException('Tarif koin per nota tidak boleh negatif.');
        }

        $business = Business::findOrFail($businessId);
        $business->coins_per_transaction = $coinsPerTx;
        $business->updated_by = $adminId;
        $business->save();
    }
}
