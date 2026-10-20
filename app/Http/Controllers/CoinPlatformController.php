<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CoinTransaction;
use App\Services\CoinService;
use Illuminate\Http\Request;

class CoinPlatformController extends Controller
{
    /**
     * Check if authenticated user is the platform superadmin / website owner.
     */
    protected function authorizeSuperadmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->isPlatformAdmin()) {
            abort(403, 'Akses Ditolak: Hanya Pemilik Platform (Superadmin / Owner Website) yang memiliki izin untuk manajemen koin platform.');
        }
    }

    /**
     * Platform Overview: Statistics & all businesses coin statuses.
     */
    public function overview(Request $request)
    {
        $this->authorizeSuperadmin($request);

        $businesses = Business::withCount('outlets')
            ->with(['referredBy.business'])
            ->orderByDesc('id')
            ->get();

        $hasCoinCol = \Illuminate\Support\Facades\Schema::hasColumn('businesses', 'coin_balance');
        $hasCoinTx = \Illuminate\Support\Facades\Schema::hasTable('coin_transactions');

        $totalCirculating = $hasCoinCol ? (float) Business::sum('coin_balance') : 0.0;
        $totalTopupCoins = $hasCoinTx ? (float) CoinTransaction::where('type', 'TOPUP')->sum('amount') : 0.0;
        $totalTopupRupiah = $hasCoinTx ? (float) CoinTransaction::where('type', 'TOPUP')->sum('payment_amount') : 0.0;
        $totalNotasDeducted = $hasCoinTx ? CoinTransaction::where('type', 'USAGE')->count() : 0;
        $criticalCount = $hasCoinCol ? $businesses->filter(fn($b) => $b->is_coin_low || $b->is_coin_out)->count() : 0;

        return response()->json([
            'metrics' => [
                'total_circulating_coins' => $totalCirculating,
                'total_topup_coins'       => $totalTopupCoins,
                'total_topup_rupiah'      => $totalTopupRupiah,
                'total_notas_deducted'    => $totalNotasDeducted,
                'critical_businesses'     => $criticalCount,
            ],
            'businesses' => $businesses,
        ]);
    }

    /**
     * Top-up coins to a business.
     */
    public function topUp(Request $request)
    {
        $this->authorizeSuperadmin($request);

        $coinsVal = $request->input('amount_coins') ?? $request->input('coins');
        $request->merge([
            'amount_coins' => $coinsVal,
            'coins'        => $coinsVal,
        ]);

        $data = $request->validate([
            'business_id'       => 'required|exists:businesses,id',
            'amount_coins'      => 'required|numeric|min:1',
            'payment_amount'    => 'nullable|numeric|min:0',
            'payment_reference' => 'nullable|string|max:255',
            'notes'             => 'nullable|string|max:500',
        ]);

        $tx = CoinService::topUp(
            (int) $data['business_id'],
            (float) $data['amount_coins'],
            isset($data['payment_amount']) ? (float) $data['payment_amount'] : null,
            $data['payment_reference'] ?? null,
            $data['notes'] ?? null,
            $request->user()->id
        );

        $business = Business::find($data['business_id']);

        return response()->json([
            'message'              => "Berhasil menambahkan {$data['amount_coins']} koin ke {$business->name}.",
            'transaction'          => $tx,
            'current_coin_balance' => $business->coin_balance,
            'remaining_transactions' => $business->remaining_transactions,
        ], 201);
    }

    /**
     * Update rate (cost per nota/transaction) or toggle unlimited mode.
     */
    public function updateRate(Request $request)
    {
        $this->authorizeSuperadmin($request);

        $data = $request->validate([
            'business_id'           => 'nullable', // null or 'ALL' or specific id
            'coins_per_transaction' => 'nullable|numeric|min:0',
            'is_unlimited_coins'    => 'nullable|boolean',
            'expires_at'            => 'nullable|date',
        ]);

        $isUnlimited = isset($data['is_unlimited_coins']) ? (bool)$data['is_unlimited_coins'] : null;
        $rate = isset($data['coins_per_transaction']) ? (float)$data['coins_per_transaction'] : ($isUnlimited ? 0.0 : 1.0);

        if ($isUnlimited === true || $rate == 0.0) {
            $isUnlimited = true;
            $rate = 0.0;
        }

        if (!empty($data['business_id']) && $data['business_id'] !== 'ALL') {
            if ($isUnlimited && empty($data['expires_at'])) {
                return response()->json([
                    'message' => 'Untuk mode Unlimited Koin, tanggal berakhir masa aktif (expired) wajib diinputkan.',
                ], 422);
            }

            $expiresAt = !empty($data['expires_at']) ? $data['expires_at'] : null;
            CoinService::updateRate((int)$data['business_id'], $rate, $isUnlimited, $request->user()->id, $expiresAt);
            $business = Business::find($data['business_id']);
            $statusText = $business->is_unlimited ? 'Unlimited (Bebas Koin)' : "{$rate} koin/nota";
            if ($business->expires_at) {
                $statusText .= " s/d " . $business->expires_at->format('d/m/Y');
            }
            return response()->json([
                'message' => "Pengaturan koin untuk {$business->name} berhasil diubah menjadi {$statusText}.",
                'business' => $business,
            ]);
        }

        // Apply to all businesses
        $updateData = ['coins_per_transaction' => $rate, 'updated_by' => $request->user()->id];
        if ($isUnlimited !== null) {
            $updateData['is_unlimited_coins'] = $isUnlimited;
        }
        if (!empty($data['expires_at'])) {
            $updateData['expires_at'] = \Carbon\Carbon::parse($data['expires_at'])->endOfDay();
        }
        Business::query()->update($updateData);

        $statusText = $isUnlimited ? 'Unlimited (Bebas Koin)' : "{$rate} koin/nota";
        return response()->json([
            'message' => "Pengaturan koin berhasil diperbarui menjadi {$statusText} untuk seluruh perusahaan.",
        ]);
    }

    /**
     * Global audit trail of coin mutations across the platform.
     */
    public function history(Request $request)
    {
        $this->authorizeSuperadmin($request);

        $query = CoinTransaction::with(['business:id,name', 'outlet:id,name', 'creator:id,name'])
            ->orderByDesc('id');

        if ($request->filled('business_id')) {
            $query->where('business_id', (int)$request->business_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return response()->json($query->paginate(50));
    }
}
