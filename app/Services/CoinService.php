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
                'allowed'        => false,
                'balance'        => 0.0,
                'cost'           => 1.0,
                'remaining'      => 0,
                'is_unlimited'   => false,
                'message'        => 'Perusahaan tidak ditemukan.',
            ];
        }

        // Cek apakah masa aktif langganan / paket unlimited perusahaan sudah kadaluarsa
        if ($business->status === 'expired' || ($business->expires_at && $business->expires_at->isPast())) {
            $expStr = $business->expires_at ? $business->expires_at->format('d/m/Y') : '-';
            return [
                'allowed'        => false,
                'balance'        => (float)($business->coin_balance ?: 0.00),
                'cost'           => (float)($business->coins_per_transaction ?: 0.00),
                'remaining'      => 0,
                'is_unlimited'   => (bool)$business->is_unlimited,
                'is_expired'     => true,
                'message'        => "Transaksi tidak dapat diproses: Masa aktif langganan perusahaan Anda telah berakhir ({$expStr}). Silakan hubungi Pemilik Website untuk perpanjangan masa aktif.",
            ];
        }

        $isUnlimited = (bool)($business->is_unlimited_coins || (isset($business->coins_per_transaction) && (float)$business->coins_per_transaction <= 0));
        $cost = $isUnlimited ? 0.0 : (isset($business->coins_per_transaction) ? (float)$business->coins_per_transaction : 1.00);
        $balance = (float)($business->coin_balance ?: 0.00);
        $remaining = $isUnlimited ? 999999 : ($cost > 0 ? (int) floor($balance / $cost) : 999999);

        if (!$isUnlimited && $balance < $cost) {
            return [
                'allowed'        => false,
                'balance'        => $balance,
                'cost'           => $cost,
                'remaining'      => 0,
                'is_unlimited'   => false,
                'message'        => "Transaksi tidak dapat diproses: Saldo koin perusahaan Anda habis (Sisa: {$balance} koin, Dibutuhkan: {$cost} koin per nota). Silakan hubungi Pemilik Website untuk top-up koin.",
            ];
        }

        return [
            'allowed'        => true,
            'balance'        => $balance,
            'cost'           => $cost,
            'remaining'      => $remaining,
            'is_unlimited'   => $isUnlimited,
            'message'        => null,
        ];
    }

    /**
     * Deduct coins when an order nota is completed (PAID) or pre-bill printed using atomic row lock.
     */
    public static function deductForOrder(int $businessId, string $orderNumber, ?int $outletId = null, ?int $userId = null, ?string $customNotes = null): ?CoinTransaction
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('businesses', 'coin_balance')) {
            return null;
        }

        // Avoid duplicate deduction if already deducted for this exact order_number
        $alreadyDeducted = CoinTransaction::where('business_id', $businessId)
            ->where('order_number', $orderNumber)
            ->where('type', 'USAGE')
            ->exists();

        if ($alreadyDeducted) {
            return null;
        }

        return DB::transaction(function () use ($businessId, $orderNumber, $outletId, $userId, $customNotes) {
            $business = Business::where('id', $businessId)->lockForUpdate()->firstOrFail();

            $isUnlimited = (bool)($business->is_unlimited_coins || (isset($business->coins_per_transaction) && (float)$business->coins_per_transaction <= 0));
            if ($isUnlimited) {
                // Mode Unlimited: Transaksi tidak memotong koin sama sekali
                return null;
            }

            $cost = isset($business->coins_per_transaction) ? (float)$business->coins_per_transaction : 1.00;
            if ($cost <= 0) {
                return null;
            }

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
                'notes'             => $customNotes ?: "Pemotongan {$cost} koin untuk Nota #{$orderNumber}",
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
     * Update the cost (coins per transaction) or toggle unlimited status for a business.
     */
    public static function updateRate(int $businessId, float $coinsPerTx, ?bool $isUnlimited = null, ?int $adminId = null, ?string $expiresAt = null): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('businesses', 'coins_per_transaction')) {
            throw new \Exception("Tabel koin belum dibuat di database server produksi. Silakan jalankan 'php artisan migrate --force' di terminal VPS server produksi Anda.");
        }

        if ($coinsPerTx < 0) {
            throw new \InvalidArgumentException('Tarif koin per nota tidak boleh negatif.');
        }

        $business = Business::findOrFail($businessId);
        $business->coins_per_transaction = $coinsPerTx;
        if ($isUnlimited !== null) {
            $business->is_unlimited_coins = $isUnlimited;
            $business->package_type = $isUnlimited ? 'unlimited' : 'premium';
        } elseif ($coinsPerTx == 0.0) {
            $business->is_unlimited_coins = true;
            $business->package_type = 'unlimited';
        } else {
            $business->is_unlimited_coins = false;
            $business->package_type = 'premium';
        }

        if ($expiresAt !== null) {
            $parsed = !empty($expiresAt) ? \Carbon\Carbon::parse($expiresAt)->endOfDay() : null;
            $business->expires_at = $parsed;
            if ($parsed && $parsed->isFuture() && $business->status === 'expired') {
                $business->status = 'active';
            }
        }

        $business->updated_by = $adminId;
        $business->save();
    }
}
