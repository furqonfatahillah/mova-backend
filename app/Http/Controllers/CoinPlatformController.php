<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CoinTransaction;
use App\Services\CoinService;
use Illuminate\Http\Request;

class CoinPlatformController extends Controller
{
    /**
     * Check if authenticated user is the website owner / platform superadmin.
     */
    protected function authorizeSuperadmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->isSuperadminPlatform()) {
            abort(403, 'Akses Ditolak: Hanya Pemilik Website (Superadmin Platform) yang memiliki izin untuk manajemen koin.');
        }
    }

    /**
     * Platform Overview: Statistics & all businesses coin statuses.
     */
    public function overview(Request $request)
    {
        $this->authorizeSuperadmin($request);

        $businesses = Business::withCount('outlets')
            ->orderByDesc('id')
            ->get();

        $totalCirculating = (float) Business::sum('coin_balance');
        $totalTopupCoins = (float) CoinTransaction::where('type', 'TOPUP')->sum('amount');
        $totalTopupRupiah = (float) CoinTransaction::where('type', 'TOPUP')->sum('payment_amount');
        $totalNotasDeducted = CoinTransaction::where('type', 'USAGE')->count();
        $criticalCount = $businesses->filter(fn($b) => $b->is_coin_low || $b->is_coin_out)->count();

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
     * Update rate (cost per nota/transaction).
     */
    public function updateRate(Request $request)
    {
        $this->authorizeSuperadmin($request);

        $data = $request->validate([
            'business_id'           => 'nullable', // null or 'ALL' or specific id
            'coins_per_transaction' => 'required|numeric|min:0',
        ]);

        $rate = (float) $data['coins_per_transaction'];

        if (!empty($data['business_id']) && $data['business_id'] !== 'ALL') {
            CoinService::updateRate((int)$data['business_id'], $rate, $request->user()->id);
            $business = Business::find($data['business_id']);
            return response()->json([
                'message' => "Tarif koin per nota untuk {$business->name} berhasil diubah menjadi {$rate} koin/nota.",
                'business' => $business,
            ]);
        }

        // Apply to all businesses
        Business::query()->update(['coins_per_transaction' => $rate, 'updated_by' => $request->user()->id]);

        return response()->json([
            'message' => "Tarif koin per nota berhasil diperbarui menjadi {$rate} koin/nota untuk seluruh perusahaan.",
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
