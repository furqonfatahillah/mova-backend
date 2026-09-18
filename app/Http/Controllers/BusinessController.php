<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            ->with(['referredBy.business']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('owner_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('referral_code_used', 'like', "%{$s}%")
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
                $query->whereNotNull('referred_by_id');
            } elseif ($request->has_referral === 'no' || $request->has_referral === '0') {
                $query->whereNull('referred_by_id');
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
            'package_type' => 'required|in:starter,pro,enterprise',
            'max_outlets'  => 'required|integer|min:1|max:100',
            'status'       => 'required|in:active,trial,suspended,expired',
            'expires_at'   => 'nullable|date',
        ]);

        $slug = Str::slug($data['name']);
        $originalSlug = $slug;
        $count = 1;
        while (Business::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }
        $data['slug'] = $slug;
        $data['created_by'] = $user->id;

        $business = Business::create($data);

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
            'package_type' => 'sometimes|in:starter,pro,enterprise',
            'max_outlets'  => 'sometimes|integer|min:1|max:100',
            'status'       => 'sometimes|in:active,trial,suspended,expired',
            'expires_at'   => 'nullable|date',
        ]);

        $data['updated_by'] = $user->id;
        $business->update($data);

        return response()->json($business);
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
                ->limit(100)
                ->get()
            : [];

        return response()->json([
            'is_platform_admin'      => false,
            'business_id'            => $business->id,
            'business_name'          => $business->name,
            'coin_balance'           => $hasCoinCol ? $business->coin_balance : 0,
            'coins_per_transaction'  => $hasCoinCol ? $business->coins_per_transaction : 1,
            'remaining_transactions' => $hasCoinCol ? $business->remaining_transactions : 0,
            'low_coin_threshold'     => $hasCoinCol ? ($business->low_coin_threshold ?: 2000) : 2000,
            'is_coin_low'            => $hasCoinCol ? $business->is_coin_low : false,
            'is_coin_out'            => $hasCoinCol ? $business->is_coin_out : false,
            'recent_mutations'       => $recentMutations,
        ]);
    }
}
