<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\CoinTopupRequest;
use App\Models\ReferralCode;
use App\Services\CoinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CoinTopupRequestController extends Controller
{
    const BANK_NAME = 'Bank Mandiri';
    const BANK_ACCOUNT_NAME = 'MUH AL RIADIN ANGGA';
    const BANK_ACCOUNT_NUMBER = '1740007745029';
    const ADMIN_WA_NUMBER = '6281244295923';

    /**
     * Get the active conversion rate (Default Rp 100 per 1 Koin).
     */
    public static function getCurrentConversionRate(): float
    {
        if (Cache::has('platform_coin_conversion_rate')) {
            return (float) Cache::get('platform_coin_conversion_rate');
        }

        $filePath = storage_path('app/platform_coin_rate.json');
        if (file_exists($filePath)) {
            $data = json_decode(file_get_contents($filePath), true);
            if (isset($data['rate_per_coin']) && (float) $data['rate_per_coin'] > 0) {
                $rate = (float) $data['rate_per_coin'];
                Cache::forever('platform_coin_conversion_rate', $rate);
                return $rate;
            }
        }

        return 100.00;
    }

    /**
     * Set the conversion rate (Superadmin only).
     */
    public static function setConversionRate(float $rate): void
    {
        $val = max(1.0, $rate);
        Cache::forever('platform_coin_conversion_rate', $val);

        try {
            $dir = storage_path('app');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(storage_path('app/platform_coin_rate.json'), json_encode([
                'rate_per_coin' => $val,
                'updated_at'    => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            // ignore file write failure
        }
    }

    /**
     * Check and auto-approve any pending requests older than 1 minute.
     * This ensures tenant operations never stall even when admin is away.
     */
    public static function processAutoApprovals(): int
    {
        $oneMinuteAgo = now()->subMinutes(1);
        
        $pending = CoinTopupRequest::where('status', 'pending')
            ->where('created_at', '<=', $oneMinuteAgo)
            ->get();

        $processed = 0;
        foreach ($pending as $req) {
            try {
                self::executeApproval(
                    $req,
                    null,
                    'auto_approved',
                    'Sistem Auto-Approve (> 1 Menit tanpa respon admin untuk kelancaran operasional)'
                );
                $processed++;
            } catch (\Throwable $e) {
                // Log and continue
                \Log::warning("Auto-approve failed for request #{$req->request_no}: " . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Execute approval transaction for a topup request.
     */
    public static function executeApproval(
        CoinTopupRequest $req,
        ?int $approverId = null,
        string $status = 'approved',
        ?string $customNotes = null
    ): CoinTopupRequest {
        return DB::transaction(function () use ($req, $approverId, $status, $customNotes) {
            // Re-fetch with row lock to avoid double approval
            $lockedReq = CoinTopupRequest::where('id', $req->id)->lockForUpdate()->firstOrFail();
            if ($lockedReq->status !== 'pending') {
                return $lockedReq;
            }

            // 1. Top up coins to tenant business
            CoinService::topUp(
                (int) $lockedReq->business_id,
                (float) $lockedReq->amount_coins,
                (float) $lockedReq->amount_rupiah,
                $lockedReq->request_no,
                "Top-up {$lockedReq->amount_coins} koin (" . rupiah_fmt($lockedReq->amount_rupiah) . ") via " . self::BANK_NAME . " - {$lockedReq->request_no}" . ($customNotes ? " ({$customNotes})" : ""),
                $approverId
            );

            // 1b. Peralihan dari mode unlimited ke mode koin setelah top-up disetujui (aktifkan mode koin & buka resep)
            $targetBiz = \App\Models\Business::find($lockedReq->business_id);
            if ($targetBiz && ($targetBiz->is_unlimited || $targetBiz->package_type === 'unlimited')) {
                $targetBiz->package_type = 'premium';
                $targetBiz->is_unlimited_coins = false;
                if (!$targetBiz->coins_per_transaction || (float)$targetBiz->coins_per_transaction <= 0) {
                    $targetBiz->coins_per_transaction = 1.0;
                }
                $targetBiz->save();
            }

            // 2. Calculate & record referral commission for si A (if business is linked to referral code)
            $commPercent = 0.00;
            $commEarned = 0.00;
            if ($lockedReq->referral_code_id) {
                $refCode = ReferralCode::find($lockedReq->referral_code_id);
                if ($refCode) {
                    $commPercent = (float) $refCode->commission_percent;
                    if ($commPercent > 0) {
                        $commEarned = round(($lockedReq->amount_rupiah * $commPercent) / 100.0, 2);
                        $refCode->increment('total_rupiah_topup', $lockedReq->amount_rupiah);
                        $refCode->increment('total_commission_earned', $commEarned);
                    }
                }
            }

            // 3. Update request status
            $lockedReq->status = $status;
            $lockedReq->approved_by = $approverId;
            $lockedReq->approved_at = now();
            $lockedReq->commission_percent_applied = $commPercent;
            $lockedReq->commission_rupiah_earned = $commEarned;
            if ($customNotes) {
                $lockedReq->notes = ($lockedReq->notes ? $lockedReq->notes . ' | ' : '') . $customNotes;
            }
            $lockedReq->save();

            return $lockedReq;
        });
    }

    /**
     * Tenant/Owner Bisnis: Submit a new top up request with transfer proof
     */
    public function submitRequest(Request $request)
    {
        self::processAutoApprovals();

        $user = $request->user();
        if (!$user->business_id) {
            return response()->json(['message' => 'Akun Anda tidak terhubung ke unit bisnis manapun.'], 422);
        }

        $business = Business::findOrFail($user->business_id);
        $rate = ($business->coin_conversion_rate && (float)$business->coin_conversion_rate > 0)
            ? (float)$business->coin_conversion_rate
            : self::getCurrentConversionRate();

        $data = $request->validate([
            'amount_rupiah'  => 'required|numeric|min:10000',
            'transfer_proof' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:5120', // max 5MB
            'notes'          => 'nullable|string|max:500',
        ], [
            'amount_rupiah.required'  => 'Masukkan nominal rupiah yang akan diajukan untuk top up.',
            'amount_rupiah.min'       => 'Nominal top up minimal Rp 10.000.',
            'transfer_proof.required' => 'Wajib melampirkan foto/struk bukti transfer ke rekening tujuan.',
            'transfer_proof.image'    => 'Bukti transfer harus berupa file gambar (JPG, PNG, atau WEBP).',
        ]);

        $amountRupiah = (float) $data['amount_rupiah'];
        $amountCoins = floor($amountRupiah / $rate);

        // Upload bukti transfer
        $proofPath = null;
        if ($request->hasFile('transfer_proof')) {
            $proofPath = $request->file('transfer_proof')->store('transfer_proofs', 'public');
        }

        $requestNo = 'TOPUP-' . date('YmdHis') . '-' . rand(100, 999);

        $topupReq = CoinTopupRequest::create([
            'request_no'          => $requestNo,
            'business_id'         => $business->id,
            'user_id'             => $user->id,
            'referral_code_id'    => $business->referral_code_id,
            'amount_rupiah'       => $amountRupiah,
            'rate_per_coin'       => $rate,
            'amount_coins'        => $amountCoins,
            'bank_name'           => self::BANK_NAME,
            'bank_account_name'   => self::BANK_ACCOUNT_NAME,
            'bank_account_number' => self::BANK_ACCOUNT_NUMBER,
            'transfer_proof_url'  => $proofPath,
            'status'              => 'pending',
            'notes'               => $data['notes'] ?? null,
        ]);

        // Generate WA message link for confirmation
        $nominalStr = 'Rp ' . number_format($amountRupiah, 0, ',', '.');
        $koinStr = number_format($amountCoins, 0, ',', '.') . ' Koin';
        $waMessage = "Halo Admin MOVA POS,\n\nSaya telah mengajukan *Top Up Koin* dengan rincian sbb:\n" .
            "• *No. Request*: {$requestNo}\n" .
            "• *Bisnis*: {$business->name}\n" .
            "• *Pemilik*: {$user->name}\n" .
            "• *Nominal*: {$nominalStr}\n" .
            "• *Koin*: {$koinStr}\n" .
            "• *Transfer Ke*: " . self::BANK_NAME . " (" . self::BANK_ACCOUNT_NUMBER . " a.n. " . self::BANK_ACCOUNT_NAME . ")\n\n" .
            "Bukti transfer telah saya upload di aplikasi. Mohon bantuannya untuk verifikasi/approval. Terima kasih!";

        $whatsappUrl = "https://wa.me/" . self::ADMIN_WA_NUMBER . "?text=" . urlencode($waMessage);

        return response()->json([
            'message'      => "Pengajuan top up {$nominalStr} ({$koinStr}) berhasil disubmit! Silakan konfirmasi bukti transfer ke WhatsApp Admin.",
            'data'         => $topupReq,
            'whatsapp_url' => $whatsappUrl,
        ], 201);
    }

    /**
     * Tenant/Owner Bisnis: View their top up request history
     */
    public function myRequests(Request $request)
    {
        self::processAutoApprovals();

        $user = $request->user();
        if (!$user->business_id) {
            return response()->json(['data' => []]);
        }

        $requests = CoinTopupRequest::where('business_id', $user->business_id)
            ->latest('id')
            ->paginate(20);

        return response()->json($requests);
    }

    /**
     * Superadmin Platform: View all top up requests across all businesses
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            abort(403, 'Akses terbatas untuk Superadmin Platform.');
        }

        self::processAutoApprovals();

        $query = CoinTopupRequest::with([
            'business:id,name,owner_name,email,phone',
            'user:id,name,email',
            'referralCode:id,code,recipient_name,commission_percent,total_rupiah_topup,total_commission_earned',
            'approver:id,name'
        ])->latest('id');

        if ($request->filled('status') && $request->status !== 'ALL') {
            $query->where('status', strtolower($request->status));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('request_no', 'like', "%{$search}%")
                  ->orWhereHas('business', function ($bq) use ($search) {
                      $bq->where('name', 'like', "%{$search}%")
                         ->orWhere('owner_name', 'like', "%{$search}%");
                  });
            });
        }

        $requests = $query->paginate($request->query('per_page', 25));

        // Metric summaries
        $all = CoinTopupRequest::all();
        $totalPending = $all->where('status', 'pending')->count();
        $totalApprovedRupiah = (float) $all->whereIn('status', ['approved', 'auto_approved'])->sum('amount_rupiah');
        $totalCommissionEarned = (float) $all->whereIn('status', ['approved', 'auto_approved'])->sum('commission_rupiah_earned');
        $totalCoinsGiven = (float) $all->whereIn('status', ['approved', 'auto_approved'])->sum('amount_coins');

        return response()->json([
            'data'    => $requests->items(),
            'meta'    => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'per_page'     => $requests->perPage(),
                'total'        => $requests->total(),
            ],
            'summary' => [
                'total_pending'           => $totalPending,
                'total_approved_rupiah'   => $totalApprovedRupiah,
                'total_commission_earned' => $totalCommissionEarned,
                'total_coins_given'       => $totalCoinsGiven,
                'rate_per_coin'           => self::getCurrentConversionRate(),
            ],
            'bank_info' => [
                'bank_name'           => self::BANK_NAME,
                'bank_account_name'   => self::BANK_ACCOUNT_NAME,
                'bank_account_number' => self::BANK_ACCOUNT_NUMBER,
                'admin_wa'            => self::ADMIN_WA_NUMBER,
            ]
        ]);
    }

    /**
     * Superadmin Platform: Approve topup request manually
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            abort(403, 'Akses terbatas untuk Superadmin Platform.');
        }

        $topupReq = CoinTopupRequest::findOrFail($id);
        if ($topupReq->status !== 'pending') {
            return response()->json([
                'message' => "Pengajuan ini tidak dapat disetujui karena statusnya sudah '{$topupReq->status}'.",
            ], 422);
        }

        $approved = self::executeApproval($topupReq, $user->id, 'approved', $request->input('notes'));

        return response()->json([
            'message' => "Pengajuan #{$approved->request_no} berhasil disetujui! Koin sebesar {$approved->amount_coins} telah ditambahkan ke bisnis.",
            'data'    => $approved->load(['business', 'referralCode']),
        ]);
    }

    /**
     * Superadmin Platform: Reject topup request
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            abort(403, 'Akses terbatas untuk Superadmin Platform.');
        }

        $topupReq = CoinTopupRequest::findOrFail($id);
        if ($topupReq->status !== 'pending') {
            return response()->json([
                'message' => "Pengajuan ini tidak dapat ditolak karena statusnya sudah '{$topupReq->status}'.",
            ], 422);
        }

        $topupReq->status = 'rejected';
        $topupReq->approved_by = $user->id;
        $topupReq->approved_at = now();
        $topupReq->notes = $request->input('notes') ?? 'Ditolak oleh Superadmin Platform.';
        $topupReq->save();

        return response()->json([
            'message' => "Pengajuan #{$topupReq->request_no} telah ditolak.",
            'data'    => $topupReq,
        ]);
    }

    /**
     * Public/Tenant: Get current conversion rate and bank account info
     */
    public function getBankAndRateInfo(Request $request)
    {
        $rate = self::getCurrentConversionRate();
        $isCustom = false;
        $user = $request->user() ?: auth('sanctum')->user();
        if ($user && $user->business_id) {
            $biz = Business::find($user->business_id);
            if ($biz && $biz->coin_conversion_rate && (float)$biz->coin_conversion_rate > 0) {
                $rate = (float)$biz->coin_conversion_rate;
                $isCustom = true;
            }
        }

        return response()->json([
            'rate_per_coin'          => $rate,
            'is_custom_business_rate'=> $isCustom,
            'bank_name'              => self::BANK_NAME,
            'bank_account_name'      => self::BANK_ACCOUNT_NAME,
            'bank_account_number'    => self::BANK_ACCOUNT_NUMBER,
            'admin_wa'               => self::ADMIN_WA_NUMBER,
        ]);
    }

    /**
     * Superadmin: Update conversion rate
     */
    public function updateConversionRate(Request $request)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            abort(403, 'Akses terbatas untuk Superadmin Platform.');
        }

        $data = $request->validate([
            'rate_per_coin' => 'required|numeric|min:1',
        ]);

        $rate = (float) $data['rate_per_coin'];
        self::setConversionRate($rate);

        return response()->json([
            'success'       => true,
            'message'       => "Tarif konversi koin berhasil diubah menjadi Rp " . number_format($rate, 0, ',', '.') . " per 1 koin (Rumus pengajuan: Nominal Rupiah / " . (int)$rate . ").",
            'rate_per_coin' => $rate,
        ]);
    }

    public static function formatRupiah($val): string
    {
        return 'Rp ' . number_format((float) $val, 0, ',', '.');
    }
}

if (!function_exists('rupiah_fmt')) {
    function rupiah_fmt($val): string
    {
        return 'Rp ' . number_format((float) $val, 0, ',', '.');
    }
}

