<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BusinessController extends Controller
{
    /**
     * Public list of businesses for staff registration dropdown
     */
    public function publicList()
    {
        return response()->json(
            Business::where('status', 'active')
                ->select('id', 'name', 'slug', 'address')
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Superadmin Platform: List all tenant businesses
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            return response()->json(['message' => 'Unauthorized. Hanya Pemilik Platform yang dapat mengakses seluruh penyewa.'], 403);
        }

        $query = Business::query()
            ->withCount(['outlets', 'users', 'menus', 'ingredients'])
            ->with([
                'outlets' => fn($q) => $q->orderByDesc('is_main')->orderBy('name'),
                'outlets.users' => fn($q) => $q->orderBy('name'),
                'users' => fn($q) => $q->orderBy('name'),
                'referredBy.business',
                'referralCode',
            ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('owner_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('referral_code_used', 'like', "%{$s}%")
                  ->orWhereHas('referralCode', function ($rq) use ($s) {
                      $rq->where('code', 'like', "%{$s}%")
                         ->orWhere('recipient_name', 'like', "%{$s}%");
                  })
                  ->orWhereHas('referredBy', function ($rq) use ($s) {
                      $rq->where('name', 'like', "%{$s}%")
                         ->orWhere('email', 'like', "%{$s}%")
                         ->orWhere('referral_code', 'like', "%{$s}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('has_referral')) {
            if ($request->has_referral === 'yes' || $request->has_referral === '1') {
                $query->where(function ($q) {
                    $q->whereNotNull('referred_by_id')
                      ->orWhereNotNull('referral_code_id')
                      ->orWhereNotNull('referral_code_used');
                });
            } elseif ($request->has_referral === 'no' || $request->has_referral === '0') {
                $query->whereNull('referred_by_id')
                      ->whereNull('referral_code_id')
                      ->whereNull('referral_code_used');
            }
        }

        return response()->json($query->orderByDesc('id')->get());
    }

    /**
     * Superadmin Platform: Create a new tenant business
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'owner_name'   => 'required|string|max:255',
            'email'        => 'nullable|email',
            'phone'        => 'nullable|string|max:50',
            'address'      => 'nullable|string|max:500',
            'package_type' => 'required|in:starter,pro,enterprise,unlimited,premium',
            'max_outlets'  => 'required|integer|min:1|max:100',
            'status'       => 'required|in:active,trial,suspended,expired,pending,rejected',
            'expires_at'   => 'nullable|date',
            'password'     => 'nullable|string|min:6',
        ]);

        $passwordInput = $data['password'] ?? null;
        unset($data['password']);

        $slug = Str::slug($data['name']);
        $originalSlug = $slug;
        $count = 1;
        while (Business::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }
        $data['slug'] = $slug;
        $data['created_by'] = $user->id;

        $business = DB::transaction(function () use ($data, $user, $passwordInput) {
            $b = Business::create($data);

            // 1. Buat outlet utama default agar langsung bisa dipakai
            $outlet = Outlet::create([
                'business_id' => $b->id,
                'code'        => 'OUT-001',
                'name'        => "Outlet Utama - {$b->name}",
                'address'     => $b->address,
                'phone'       => $b->phone,
                'pic_name'    => $b->owner_name,
                'is_main'     => true,
                'active'      => true,
                'created_by'  => $user->id,
            ]);

            // 2. Buat akun login Owner Bisnis jika email diisi
            if (!empty($b->email)) {
                $rawPass = !empty($passwordInput) ? $passwordInput : 'password123';
                User::create([
                    'business_id' => $b->id,
                    'outlet_id'   => $outlet->id,
                    'name'        => $b->owner_name,
                    'email'       => $b->email,
                    'password'    => Hash::make($rawPass),
                    'role'        => 'owner_bisnis',
                    'status'      => $b->status === 'pending' ? 'pending' : 'active',
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ]);
            }

            // 3. Inisialisasi Chart of Accounts (COA) untuk modul akuntansi & stok
            \App\Models\Account::seedForBusiness((int)$b->id);

            return $b;
        });

        return response()->json($business, 201);
    }

    /**
     * Superadmin Platform: Show a tenant business details
     */
    public function show(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin() && (int)$user->business_id !== (int)$business->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $business->load([
            'outlets' => fn($q) => $q->orderByDesc('is_main')->orderBy('name'),
            'users'   => fn($q) => $q->orderBy('name'),
        ]);

        return response()->json($business);
    }

    /**
     * Superadmin Platform: Update a tenant business (package, status, limits)
     */
    public function update(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'owner_name'   => 'sometimes|string|max:255',
            'email'        => 'nullable|email',
            'phone'        => 'nullable|string|max:50',
            'address'      => 'nullable|string|max:500',
            'package_type' => 'sometimes|in:starter,pro,enterprise,unlimited,premium',
            'max_outlets'  => 'sometimes|integer|min:1|max:100',
            'status'             => 'sometimes|in:active,trial,suspended,expired,pending,rejected',
            'expires_at'         => 'nullable|date',
            'is_unlimited_coins' => 'nullable|boolean',
        ]);

        $wasUnlimited = (bool)$business->is_unlimited;
        $data['updated_by'] = $user->id;
        $business->update($data);

        // Jika beralih dari unlimited ke premium, kunci seluruh transaksi masa lalu dan pastikan koin awal 0
        if ($wasUnlimited && !$business->fresh()->is_unlimited) {
            \App\Models\Transaction::where('business_id', $business->id)
                ->where('is_unlimited_mode', false)
                ->update(['is_unlimited_mode' => true]);

            // Koin awal harus 0 jika belum pernah ada riwayat top-up
            if (!\App\Services\CoinService::hasTopupHistory($business->id)) {
                $business->coin_balance = 0.00;
                $business->save();
            }
        }

        // Jika bisnis diaktifkan, otomatis aktifkan user owner_bisnis yang pending
        if (isset($data['status']) && $data['status'] === 'active') {
            User::where('business_id', $business->id)
                ->where('status', 'pending')
                ->where('role', 'owner_bisnis')
                ->update([
                    'status'      => 'active',
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ]);
        }

        return response()->json($business);
    }

    /**
     * Superadmin Platform: Verify and activate a newly registered business
     */
    public function verify(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            return response()->json(['message' => 'Unauthorized. Hanya Superadmin Platform yang dapat memverifikasi bisnis.'], 403);
        }

        $business->update([
            'status'     => 'active',
            'expires_at' => $business->expires_at && $business->expires_at->isFuture() ? $business->expires_at : now()->addMonths(1),
            'updated_by' => $user->id,
        ]);

        // Otomatis aktifkan user owner_bisnis terkait
        User::where('business_id', $business->id)
            ->where('status', 'pending')
            ->where('role', 'owner_bisnis')
            ->update([
                'status'      => 'active',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

        return response()->json([
            'message'  => "Bisnis '{$business->name}' berhasil diverifikasi dan diaktifkan!",
            'business' => $business->fresh(['outlets', 'users', 'referredBy']),
        ]);
    }

    /**
     * Superadmin Platform: Reject a registered business
     */
    public function reject(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->isPlatformAdmin()) {
            return response()->json(['message' => 'Unauthorized. Hanya Superadmin Platform yang dapat menolak pendaftaran bisnis.'], 403);
        }

        $business->update([
            'status'     => 'rejected',
            'updated_by' => $user->id,
        ]);

        // Update status user owner bisnis terkait menjadi rejected
        User::where('business_id', $business->id)
            ->where('status', 'pending')
            ->update([
                'status'      => 'rejected',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

        return response()->json([
            'message'  => "Pendaftaran bisnis '{$business->name}' telah ditolak.",
            'business' => $business->fresh(['outlets', 'users', 'referredBy']),
        ]);
    }

    /**
     * Owner Bisnis: Get profile of their own business
     */
    public function myBusiness(Request $request)
    {
        $user = $request->user();
        if (!$user->business_id) {
            return response()->json(['message' => 'User tidak terikat dengan bisnis tertentu.'], 404);
        }

        $business = Business::with([
            'outlets' => fn($q) => $q->orderByDesc('is_main')->orderBy('name'),
        ])->findOrFail($user->business_id);

        return response()->json($business);
    }

    /**
     * Owner Bisnis: Update their own business info
     */
    public function updateMyBusiness(Request $request)
    {
        $user = $request->user();
        if (!$user->isOwnerBisnis() && !$user->isSuperadminPlatform()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $business = Business::findOrFail($user->business_id);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'owner_name' => 'sometimes|string|max:255',
            'phone'      => 'nullable|string|max:50',
            'address'    => 'nullable|string|max:500',
        ]);

        $data['updated_by'] = $user->id;
        $business->update($data);

        return response()->json($business);
    }

    /**
     * Get current business coin balance, rate, remaining transactions and alert status.
     */
    public function myCoins(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        if ($user->isPlatformAdmin()) {
            $explicitBusinessId = $request->header('X-Business-Id') ?? $request->business_id;
            if ($explicitBusinessId && is_numeric($explicitBusinessId)) {
                $businessId = (int)$explicitBusinessId;
            } else {
                // Platform admin without active tenant selected (SaaS Platform Owner has no personal coin balance)
                return response()->json([
                    'is_platform_admin'      => true,
                    'business_id'            => null,
                    'business_name'          => 'Platform Provider (MOVA)',
                    'coin_balance'           => null,
                    'coins_per_transaction'  => null,
                    'remaining_transactions' => null,
                    'low_coin_threshold'     => 0,
                    'is_coin_low'            => false,
                    'is_coin_out'            => false,
                    'is_unlimited_coins'     => false,
                    'recent_mutations'       => [],
                ]);
            }
        }

        if (!$businessId) {
            return response()->json(['message' => 'User tidak terikat dengan bisnis tertentu.'], 404);
        }

        $business = Business::findOrFail($businessId);

        $hasCoinCol = \Illuminate\Support\Facades\Schema::hasColumn('businesses', 'coin_balance');
        $hasCoinTx = \Illuminate\Support\Facades\Schema::hasTable('coin_transactions');

        $recentMutations = $hasCoinTx
            ? \App\Models\CoinTransaction::where('business_id', $businessId)
                ->with(['outlet:id,name', 'creator:id,name'])
                ->orderByDesc('id')
                ->limit(500)
                ->get()
            : [];

        $isUnlimited = (bool)$business->is_unlimited;

        return response()->json([
            'is_platform_admin'      => false,
            'business_id'            => $business->id,
            'business_name'          => $business->name,
            'package_type'           => $isUnlimited ? 'unlimited' : 'premium',
            'is_demo_plan'           => false,
            'is_unlimited_plan'      => $isUnlimited,
            'is_premium_plan'        => !$isUnlimited,
            'expires_at'             => $business->expires_at,
            'coin_balance'           => $hasCoinCol ? $business->coin_balance : 0,
            'coins_per_transaction'  => $hasCoinCol ? $business->coins_per_transaction : 1,
            'is_unlimited_coins'     => $isUnlimited,
            'remaining_transactions' => $hasCoinCol ? $business->remaining_transactions : 0,
            'low_coin_threshold'     => $hasCoinCol ? ($business->low_coin_threshold ?: 2000) : 2000,
            'is_coin_low'            => $hasCoinCol ? $business->is_coin_low : false,
            'is_coin_out'            => $hasCoinCol ? $business->is_coin_out : false,
            'recent_mutations'       => $recentMutations,
        ]);
    }
}
