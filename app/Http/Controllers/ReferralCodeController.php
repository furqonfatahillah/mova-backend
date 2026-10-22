<?php

namespace App\Http\Controllers;

use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReferralCodeController extends Controller
{
    private function checkSuperadmin(Request $request)
    {
        $user = $request->user();
        if (!$user || (!$user->isSuperadminPlatform() && !$user->isOwnerWebsite())) {
            abort(403, 'Akses terbatas untuk Superadmin Platform / Owner Website.');
        }
    }

    /**
     * List all referral codes with usages and summary stats (Superadmin only)
     */
    public function index(Request $request)
    {
        $this->checkSuperadmin($request);

        $query = ReferralCode::with([
            'creator:id,name,email',
            'usages' => function ($q) {
                $q->with([
                    'business:id,name,owner_name,email,phone,status,created_at',
                    'user:id,name,email'
                ])->latest('registered_at');
            },
            'topupRequests' => function ($q) {
                $q->with('business:id,name')->latest('id');
            },
            'payouts' => function ($q) {
                $q->with('payer:id,name')->latest('payment_date')->latest('id');
            }
        ])->latest('id');

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('recipient_name', 'like', "%{$search}%")
                  ->orWhere('bank_account_name', 'like', "%{$search}%")
                  ->orWhere('bank_account_number', 'like', "%{$search}%")
                  ->orWhere('whatsapp_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Status Filter
        $status = $request->query('status', 'ALL');
        if ($status === 'ACTIVE') {
            $query->where('is_active', true)
                  ->where(function ($q) {
                      $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                  })
                  ->where(function ($q) {
                      $q->whereNull('max_uses')->orWhereRaw('times_used < max_uses');
                  });
        } elseif ($status === 'INACTIVE') {
            $query->where('is_active', false);
        } elseif ($status === 'EXHAUSTED') {
            $query->whereNotNull('max_uses')->whereRaw('times_used >= max_uses');
        } elseif ($status === 'EXPIRED') {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
        }

        $codes = $query->paginate($request->query('per_page', 25));

        // Calculate Overview Summary
        $all = ReferralCode::all();
        $totalCodes = $all->count();
        $totalActive = $all->where('is_valid', true)->count();
        $totalUsages = (int) $all->sum('times_used');
        $exclusiveSingleUse = $all->where('max_uses', 1)->count();
        $totalRupiahTopup = (float) $all->sum('total_rupiah_topup');
        $totalCommissionEarned = (float) $all->sum('total_commission_earned');
        $totalCommissionPaid = (float) $all->sum('total_commission_paid');
        $totalCommissionOutstanding = max(0.0, round($totalCommissionEarned - $totalCommissionPaid, 2));

        return response()->json([
            'data'    => $codes->items(),
            'meta'    => [
                'current_page' => $codes->currentPage(),
                'last_page'    => $codes->lastPage(),
                'per_page'     => $codes->perPage(),
                'total'        => $codes->total(),
            ],
            'summary' => [
                'total_codes'                  => $totalCodes,
                'total_active'                 => $totalActive,
                'total_usages'                 => $totalUsages,
                'exclusive_single_use'         => $exclusiveSingleUse,
                'total_rupiah_topup'           => $totalRupiahTopup,
                'total_commission_earned'      => $totalCommissionEarned,
                'total_commission_paid'        => $totalCommissionPaid,
                'total_commission_outstanding' => $totalCommissionOutstanding,
            ]
        ]);
    }

    /**
     * Create a new referral code (Superadmin only)
     */
    public function store(Request $request)
    {
        $this->checkSuperadmin($request);

        $data = $request->validate([
            'code'                => 'nullable|string|min:3|max:50|unique:referral_codes,code',
            'recipient_name'      => 'nullable|string|max:255',
            'bank_name'           => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_account_name'   => 'nullable|string|max:150',
            'whatsapp_number'     => 'nullable|string|max:50',
            'commission_percent'  => 'nullable|numeric|min:0|max:100',
            'description'         => 'nullable|string|max:1000',
            'max_uses'            => 'nullable|integer|min:1',
            'is_active'           => 'nullable|boolean',
            'expires_at'          => 'nullable|date|after:now',
        ], [
            'code.unique'         => 'Kode referral ini sudah digunakan. Silakan gunakan kode lain.',
            'expires_at.after'    => 'Tanggal kedaluwarsa harus di masa depan.',
            'max_uses.min'        => 'Batas pemakaian minimal 1 kali.',
        ]);

        // Auto-generate unique code if not provided
        $code = !empty($data['code'])
            ? strtoupper(trim($data['code']))
            : $this->generateUniqueRandomCode();

        $referralCode = ReferralCode::create([
            'code'                => $code,
            'recipient_name'      => $data['recipient_name'] ?? null,
            'bank_name'           => $data['bank_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'bank_account_name'   => $data['bank_account_name'] ?? null,
            'whatsapp_number'     => $data['whatsapp_number'] ?? null,
            'commission_percent'  => isset($data['commission_percent']) ? (float) $data['commission_percent'] : 0.00,
            'total_rupiah_topup'  => 0.00,
            'total_commission_earned' => 0.00,
            'description'         => $data['description'] ?? null,
            'max_uses'            => isset($data['max_uses']) ? (int) $data['max_uses'] : 1, // Default 1x pakai eksklusif jika tidak ditentukan
            'times_used'          => 0,
            'is_active'           => $data['is_active'] ?? true,
            'expires_at'          => $data['expires_at'] ?? null,
            'created_by'          => $request->user()->id,
        ]);

        return response()->json([
            'message' => "Kode referral '{$referralCode->code}' berhasil dibuat!",
            'data'    => $referralCode->load(['creator:id,name,email']),
        ], 201);
    }

    /**
     * Show single referral code with detailed usage tracking
     */
    public function show(Request $request, $id)
    {
        $this->checkSuperadmin($request);

        $referralCode = ReferralCode::with([
            'creator:id,name,email',
            'usages' => function ($q) {
                $q->with([
                    'business:id,name,owner_name,email,phone,address,status,package_type,created_at',
                    'user:id,name,email,status,created_at'
                ])->latest('registered_at');
            },
            'topupRequests' => function ($q) {
                $q->with(['business:id,name'])->latest('id');
            }
        ])->findOrFail($id);

        return response()->json([
            'data' => $referralCode,
        ]);
    }

    /**
     * Update referral code details
     */
    public function update(Request $request, $id)
    {
        $this->checkSuperadmin($request);

        $referralCode = ReferralCode::findOrFail($id);

        $data = $request->validate([
            'code'                => ['required', 'string', 'min:3', 'max:50', Rule::unique('referral_codes', 'code')->ignore($referralCode->id)],
            'recipient_name'      => 'nullable|string|max:255',
            'bank_name'           => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_account_name'   => 'nullable|string|max:150',
            'whatsapp_number'     => 'nullable|string|max:50',
            'commission_percent'  => 'nullable|numeric|min:0|max:100',
            'description'         => 'nullable|string|max:1000',
            'max_uses'            => 'nullable|integer|min:1',
            'is_active'           => 'required|boolean',
            'expires_at'          => 'nullable|date',
        ]);

        $referralCode->update([
            'code'                => strtoupper(trim($data['code'])),
            'recipient_name'      => $data['recipient_name'] ?? null,
            'bank_name'           => $data['bank_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'bank_account_name'   => $data['bank_account_name'] ?? null,
            'whatsapp_number'     => $data['whatsapp_number'] ?? null,
            'commission_percent'  => isset($data['commission_percent']) ? (float) $data['commission_percent'] : $referralCode->commission_percent,
            'description'         => $data['description'] ?? null,
            'max_uses'            => $data['max_uses'] ?? null,
            'is_active'           => $data['is_active'],
            'expires_at'          => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Kode referral berhasil diperbarui!',
            'data'    => $referralCode->fresh(['creator:id,name,email']),
        ]);
    }

    /**
     * Toggle active/inactive status
     */
    public function toggleActive(Request $request, $id)
    {
        $this->checkSuperadmin($request);

        $referralCode = ReferralCode::findOrFail($id);
        $referralCode->is_active = !$referralCode->is_active;
        $referralCode->save();

        $statusStr = $referralCode->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'message' => "Kode referral '{$referralCode->code}' berhasil {$statusStr}!",
            'data'    => $referralCode,
        ]);
    }

    /**
     * Delete referral code permanently
     */
    public function destroy(Request $request, $id)
    {
        $this->checkSuperadmin($request);

        $referralCode = ReferralCode::findOrFail($id);
        $codeStr = $referralCode->code;

        // Clean up linked relations before deletion
        \App\Models\Business::where('referral_code_id', $referralCode->id)->update(['referral_code_id' => null]);
        \App\Models\CoinTopupRequest::where('referral_code_id', $referralCode->id)->update(['referral_code_id' => null]);
        
        $referralCode->usages()->delete();
        $referralCode->payouts()->delete();
        $referralCode->delete();

        return response()->json([
            'status'  => 'success',
            'message' => "Kode referral '{$codeStr}' berhasil dihapus permanen dari sistem!",
        ]);
    }

    /**
     * Public endpoint to validate referral code for register form
     */
    public function validatePublicCode($code)
    {
        $cleanCode = strtoupper(trim($code));
        $ref = ReferralCode::where('code', $cleanCode)->first();

        if (!$ref) {
            return response()->json([
                'valid'   => false,
                'message' => "Kode referral '{$cleanCode}' tidak ditemukan atau tidak valid.",
            ], 404);
        }

        if (!$ref->is_active) {
            return response()->json([
                'valid'   => false,
                'message' => "Kode referral '{$cleanCode}' saat ini tidak aktif.",
            ], 422);
        }

        if ($ref->expires_at && $ref->expires_at->isPast()) {
            return response()->json([
                'valid'   => false,
                'message' => "Kode referral '{$cleanCode}' telah kedaluwarsa pada " . $ref->expires_at->format('d/m/Y') . ".",
            ], 422);
        }

        if ($ref->max_uses !== null && $ref->times_used >= $ref->max_uses) {
            return response()->json([
                'valid'   => false,
                'message' => "Kuota penggunaan kode referral '{$cleanCode}' telah habis.",
            ], 422);
        }

        return response()->json([
            'valid'          => true,
            'code'           => $ref->code,
            'recipient_name' => $ref->recipient_name,
            'message'        => $ref->recipient_name
                ? "Kode referral terverifikasi khusus untuk: {$ref->recipient_name}"
                : "Kode referral valid dan siap digunakan.",
        ]);
    }

    /**
     * Get payout history for a specific referral code
     */
    public function payouts(Request $request, $id)
    {
        $this->checkSuperadmin($request);
        $referralCode = ReferralCode::with(['payouts.payer:id,name'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'referral_code'               => $referralCode,
                'total_commission_earned'      => (float)$referralCode->total_commission_earned,
                'total_commission_paid'        => (float)$referralCode->total_commission_paid,
                'remaining_commission'         => (float)$referralCode->remaining_commission,
                'payment_status'              => $referralCode->payment_status,
                'payouts'                     => $referralCode->payouts,
            ]
        ]);
    }

    /**
     * Record a new payout for a referral code
     */
    public function storePayout(Request $request, $id)
    {
        $this->checkSuperadmin($request);
        $referralCode = ReferralCode::findOrFail($id);

        $validated = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_date'   => 'required|date',
            'payment_method' => 'required|string|in:BANK_TRANSFER,CASH,E_WALLET,OTHER',
            'bank_name'      => 'nullable|string|max:50',
            'account_number' => 'nullable|string|max:50',
            'account_name'   => 'nullable|string|max:100',
            'reference_no'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:500',
        ]);

        $amount = (float)$validated['amount'];
        $remaining = (float)$referralCode->remaining_commission;

        if ($amount > $remaining) {
            return response()->json([
                'message' => "Nominal pembayaran (Rp " . number_format($amount, 0, ',', '.') . ") melebihi sisa komisi yang belum dibayar (Rp " . number_format($remaining, 0, ',', '.') . ")."
            ], 422);
        }

        $payoutNo = 'PAY-' . strtoupper(Str::random(4)) . '-' . date('YmdHis');

        $payout = \App\Models\ReferralCommissionPayout::create([
            'referral_code_id' => $referralCode->id,
            'payout_no'        => $payoutNo,
            'amount'           => $amount,
            'payment_method'   => $validated['payment_method'],
            'bank_name'        => $validated['bank_name'] ?? null,
            'account_number'   => $validated['account_number'] ?? null,
            'account_name'     => $validated['account_name'] ?? null,
            'payment_date'     => $validated['payment_date'],
            'reference_no'     => $validated['reference_no'] ?? null,
            'notes'            => $validated['notes'] ?? null,
            'paid_by'          => $request->user()->id,
        ]);

        $referralCode->increment('total_commission_paid', $amount);
        
        // Auto-update referral code bank details if not yet set
        if (!empty($validated['account_number'])) {
            $updateFields = [];
            if (empty($referralCode->bank_account_number)) {
                $updateFields['bank_account_number'] = $validated['account_number'];
            }
            if (empty($referralCode->bank_name) && !empty($validated['bank_name'])) {
                $updateFields['bank_name'] = $validated['bank_name'];
            }
            if (empty($referralCode->bank_account_name) && !empty($validated['account_name'])) {
                $updateFields['bank_account_name'] = $validated['account_name'];
            }
            if (!empty($updateFields)) {
                $referralCode->update($updateFields);
            }
        }

        $referralCode->refresh();

        return response()->json([
            'status'  => 'success',
            'message' => "Berhasil mencatat pembayaran komisi sebesar Rp " . number_format($amount, 0, ',', '.') . " kepada mitra '{$referralCode->recipient_name}'.",
            'data'    => [
                'payout'        => $payout->load('payer:id,name'),
                'referral_code' => $referralCode,
            ]
        ], 201);
    }

    /**
     * Delete / cancel a payout record
     */
    public function destroyPayout(Request $request, $payoutId)
    {
        $this->checkSuperadmin($request);
        $payout = \App\Models\ReferralCommissionPayout::findOrFail($payoutId);
        $referralCode = $payout->referralCode;

        $amount = (float)$payout->amount;
        $payout->delete();

        if ($referralCode) {
            $referralCode->decrement('total_commission_paid', min($amount, (float)$referralCode->total_commission_paid));
            $referralCode->refresh();
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Catatan pembayaran komisi berhasil dibatalkan/dihapus.',
            'data'    => $referralCode,
        ]);
    }

    /**
     * Helper to generate a unique random code
     */
    private function generateUniqueRandomCode(): string
    {
        do {
            $code = 'REF-' . strtoupper(Str::random(6));
        } while (ReferralCode::where('code', $code)->exists());

        return $code;
    }
}
