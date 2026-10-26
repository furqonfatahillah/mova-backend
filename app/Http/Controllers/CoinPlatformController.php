<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CoinTransaction;
use App\Models\Transaction;
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
     * Adjust / Koreksi saldo koin jika superadmin salah input nominal
     */
    public function adjust(Request $request)
    {
        $this->authorizeSuperadmin($request);

        $data = $request->validate([
            'business_id'    => 'required|exists:businesses,id',
            'target_balance' => 'required|numeric|min:0',
            'reason'         => 'required|string|max:500',
        ], [
            'target_balance.required' => 'Saldo koin baru yang benar wajib diisi.',
            'target_balance.min'      => 'Saldo koin tidak boleh bernilai negatif.',
            'reason.required'         => 'Alasan penyesuaian/koreksi saldo wajib dicantumkan.',
        ]);

        $tx = CoinService::adjust(
            (int) $data['business_id'],
            (float) $data['target_balance'],
            trim($data['reason']),
            $request->user()->id
        );

        $business = Business::find($data['business_id']);

        return response()->json([
            'message'              => "Saldo koin {$business->name} berhasil dikoreksi menjadi " . number_format($business->coin_balance, 0, ',', '.') . " koin.",
            'transaction'          => $tx,
            'current_coin_balance' => $business->coin_balance,
            'remaining_transactions' => $business->remaining_transactions,
        ]);
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
            'package_type'          => 'nullable|string|in:unlimited,premium',
            'expires_at'            => 'nullable|date',
            'coin_conversion_rate'  => 'nullable|numeric|min:1',
        ]);

        $pkg = $data['package_type'] ?? null;
        $isUnlimited = isset($data['is_unlimited_coins']) ? (bool)$data['is_unlimited_coins'] : ($pkg === 'unlimited');
        $rate = isset($data['coins_per_transaction']) ? (float)$data['coins_per_transaction'] : ($isUnlimited ? 0.0 : 1.0);

        if ($isUnlimited === true || $rate == 0.0 || $pkg === 'unlimited') {
            $isUnlimited = true;
            $rate = 0.0;
            $pkg = 'unlimited';
        } else {
            $isUnlimited = false;
            $pkg = 'premium';
        }

        if (!empty($data['business_id']) && $data['business_id'] !== 'ALL') {
            if ($isUnlimited && empty($data['expires_at'])) {
                return response()->json([
                    'message' => 'Untuk mode Unlimited, tanggal berakhir masa aktif (expired) wajib diinputkan.',
                ], 422);
            }

            $expiresAt = !empty($data['expires_at']) ? $data['expires_at'] : null;
            $conversionRateParam = $request->has('coin_conversion_rate') ? $request->input('coin_conversion_rate') : 'NO_CHANGE';

            CoinService::updateRate(
                (int)$data['business_id'],
                $rate,
                $isUnlimited,
                $request->user()->id,
                $expiresAt,
                $pkg,
                $conversionRateParam
            );

            $business = Business::find($data['business_id']);
            $statusText = $business->is_unlimited ? 'Unlimited (Bebas Koin)' : "{$rate} koin/nota";
            if ($business->expires_at) {
                $statusText .= " s/d " . $business->expires_at->format('d/m/Y');
            }
            if ($business->coin_conversion_rate) {
                $statusText .= " (Kurs Top Up Khusus: /{$business->coin_conversion_rate})";
            }
            return response()->json([
                'message' => "Pengaturan koin untuk {$business->name} berhasil diubah menjadi {$statusText}.",
                'business' => $business,
            ]);
        }

        // Apply to all businesses
        $expiresAt = !empty($data['expires_at']) ? $data['expires_at'] : null;
        $conversionRateParam = $request->has('coin_conversion_rate') ? $request->input('coin_conversion_rate') : 'NO_CHANGE';

        $allBusinesses = Business::all();
        foreach ($allBusinesses as $b) {
            CoinService::updateRate(
                (int)$b->id,
                $rate,
                $isUnlimited,
                $request->user()->id,
                $expiresAt,
                $pkg,
                $conversionRateParam
            );
        }

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

        $query = CoinTransaction::with(['business:id,name,package_type,coin_balance', 'outlet:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('business_id')) {
            $query->where('business_id', (int)$request->business_id);
        }

        if ($request->filled('type') && $request->type !== 'ALL') {
            $query->where('type', $request->type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                    ->orWhere('payment_reference', 'like', "%{$s}%")
                    ->orWhere('notes', 'like', "%{$s}%")
                    ->orWhereHas('business', fn($b) => $b->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('outlet', fn($o) => $o->where('name', 'like', "%{$s}%"));
            });
        }

        $perPage = $request->boolean('all') ? 2000 : min((int)$request->input('per_page', 500), 2000);
        $paginated = $query->paginate($perPage);

        // Fetch transaction sales totals for USAGE order_numbers to track actual POS sales
        $orderNumbers = collect($paginated->items())
            ->filter(fn($tx) => !empty($tx->order_number))
            ->pluck('order_number')
            ->unique()
            ->values();

        $orderSalesMap = [];
        if ($orderNumbers->isNotEmpty()) {
            $orderSalesMap = Transaction::whereIn('order_number', $orderNumbers)
                ->selectRaw('order_number, SUM(total_price) as total_sales, COUNT(id) as item_count')
                ->groupBy('order_number')
                ->pluck('total_sales', 'order_number')
                ->toArray();
        }

        $totalSalesRupiah = 0;
        foreach ($paginated->items() as $item) {
            if (!empty($item->order_number) && isset($orderSalesMap[$item->order_number])) {
                $val = (float)$orderSalesMap[$item->order_number];
                $item->order_sales_amount = $val;
                $totalSalesRupiah += $val;
            } else {
                $item->order_sales_amount = null;
            }
        }

        // Summary metrics for tracking
        $statsBase = CoinTransaction::query();
        if ($request->filled('business_id')) {
            $statsBase->where('business_id', (int)$request->business_id);
        }
        if ($request->filled('date_from')) {
            $statsBase->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $statsBase->whereDate('created_at', '<=', $request->date_to);
        }

        $salesNotesCount = (clone $statsBase)->where('type', 'USAGE')->count();
        $coinsDeducted = abs((float)(clone $statsBase)->where('type', 'USAGE')->sum('amount'));
        $topupCoins = (float)(clone $statsBase)->where('type', 'TOPUP')->sum('amount');
        $topupRupiah = (float)(clone $statsBase)->where('type', 'TOPUP')->sum('payment_amount');
        $activeBusinessesCount = (clone $statsBase)->distinct('business_id')->count('business_id');

        return response()->json([
            'data'         => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'summary'      => [
                'total_sales_notes'       => $salesNotesCount,
                'total_sales_rupiah'      => $totalSalesRupiah,
                'total_coins_deducted'    => $coinsDeducted,
                'total_topup_coins'       => $topupCoins,
                'total_topup_rupiah'      => $topupRupiah,
                'active_businesses_count' => $activeBusinessesCount,
            ],
        ]);
    }
}
