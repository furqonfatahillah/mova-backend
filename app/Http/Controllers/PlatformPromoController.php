<?php

namespace App\Http\Controllers;

use App\Models\PlatformPromo;
use App\Models\PlatformPromoBusiness;
use App\Models\Business;
use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlatformPromoController extends Controller
{
    /**
     * Check if user is platform admin / superadmin
     */
    protected function authorizePlatformAdmin(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isPlatformAdmin()) {
            abort(403, 'Akses ditolak. Fitur ini khusus untuk Superadmin / Pemilik Platform.');
        }
    }

    /**
     * List all platform promos (for Superadmin)
     */
    public function index(Request $request)
    {
        $this->authorizePlatformAdmin($request);

        $query = PlatformPromo::with(['creator', 'updater'])
            ->withCount(['distributions as total_distributed'])
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                  ->orWhere('code', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        if ($request->has('is_active') && $request->is_active !== '' && $request->is_active !== 'ALL') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('type') && in_array($request->type, ['PERCENTAGE', 'FIXED'])) {
            $query->where('type', $request->type);
        }

        $promos = $query->get();

        // Calculate aggregate metrics across all distributions
        $promoIds = $promos->pluck('id')->toArray();
        $aggregates = PlatformPromoBusiness::whereIn('platform_promo_id', $promoIds)
            ->select(
                'platform_promo_id',
                DB::raw('SUM(used_count) as total_used'),
                DB::raw('SUM(usage_limit) as total_quota'),
                DB::raw('COUNT(DISTINCT business_id) as business_count')
            )
            ->groupBy('platform_promo_id')
            ->get()
            ->keyBy('platform_promo_id');

        $result = $promos->map(function ($p) use ($aggregates) {
            $agg = $aggregates->get($p->id);
            $p->total_used = (int)($agg?->total_used ?? 0);
            $p->total_quota = (int)($agg?->total_quota ?? 0);
            $p->business_count = (int)($agg?->business_count ?? 0);
            return $p;
        });

        // Platform-wide overview stats
        $totalActivePromos = PlatformPromo::where('is_active', true)->count();
        $totalDistributionsCount = PlatformPromoBusiness::count();
        $totalRedemptionsCount = PlatformPromoBusiness::sum('used_count');
        $totalUniqueBusinessesReached = PlatformPromoBusiness::distinct('business_id')->count('business_id');

        return response()->json([
            'promos' => $result,
            'summary' => [
                'total_promos'              => $promos->count(),
                'active_promos'             => $totalActivePromos,
                'total_distributions'       => $totalDistributionsCount,
                'total_redemptions'         => (int)$totalRedemptionsCount,
                'unique_businesses_reached' => $totalUniqueBusinessesReached,
            ],
        ]);
    }

    /**
     * Create a new Platform Promo
     */
    public function store(Request $request)
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'title'               => 'required|string|max:150',
            'code'                => 'required|string|max:50|unique:platform_promos,code',
            'description'         => 'nullable|string',
            'type'                => 'required|string|in:PERCENTAGE,FIXED',
            'value'               => 'required|numeric|min:0.01',
            'min_order_amount'    => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'default_usage_limit' => 'required|integer|min:1',
            'start_date'          => 'nullable|date',
            'end_date'            => 'nullable|date|after_or_equal:start_date',
            'banner_color'        => 'nullable|string|max:30',
            'badge_text'          => 'nullable|string|max:50',
            'is_active'           => 'nullable|boolean',
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;
        $validated['banner_color'] = $validated['banner_color'] ?: '#4F46E5';
        $validated['min_order_amount'] = (float)($validated['min_order_amount'] ?? 0);
        $validated['max_discount_amount'] = isset($validated['max_discount_amount']) && $validated['max_discount_amount'] !== '' ? (float)$validated['max_discount_amount'] : null;

        $promo = PlatformPromo::create($validated);
        $promo->load(['creator', 'updater']);

        return response()->json([
            'message' => 'Promo Platform berhasil dibuat!',
            'promo'   => $promo,
        ], 201);
    }

    /**
     * Show detail of a single promo with its business distributions
     */
    public function show(Request $request, PlatformPromo $platformPromo)
    {
        $this->authorizePlatformAdmin($request);

        $platformPromo->load([
            'creator',
            'updater',
            'distributions.business' => fn($q) => $q->withCount('outlets'),
            'distributions.discount',
            'distributions.distributor',
        ]);

        return response()->json($platformPromo);
    }

    /**
     * Update an existing promo
     */
    public function update(Request $request, PlatformPromo $platformPromo)
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'title'               => 'required|string|max:150',
            'code'                => [
                'required', 'string', 'max:50',
                Rule::unique('platform_promos', 'code')->ignore($platformPromo->id)
            ],
            'description'         => 'nullable|string',
            'type'                => 'required|string|in:PERCENTAGE,FIXED',
            'value'               => 'required|numeric|min:0.01',
            'min_order_amount'    => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'default_usage_limit' => 'required|integer|min:1',
            'start_date'          => 'nullable|date',
            'end_date'            => 'nullable|date|after_or_equal:start_date',
            'banner_color'        => 'nullable|string|max:30',
            'badge_text'          => 'nullable|string|max:50',
            'is_active'           => 'nullable|boolean',
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['updated_by'] = $request->user()->id;
        $validated['min_order_amount'] = (float)($validated['min_order_amount'] ?? 0);
        $validated['max_discount_amount'] = isset($validated['max_discount_amount']) && $validated['max_discount_amount'] !== '' ? (float)$validated['max_discount_amount'] : null;

        $platformPromo->update($validated);

        // Sync changes to all active discounts distributed from this promo
        Discount::where('platform_promo_id', $platformPromo->id)
            ->update([
                'name'                => $platformPromo->title,
                'code'                => $platformPromo->code,
                'type'                => $platformPromo->type,
                'value'               => $platformPromo->value,
                'min_order_amount'    => $platformPromo->min_order_amount,
                'max_discount_amount' => $platformPromo->max_discount_amount,
                'active'              => $platformPromo->is_active,
            ]);

        $platformPromo->load(['creator', 'updater']);

        return response()->json([
            'message' => 'Promo Platform berhasil diperbarui.',
            'promo'   => $platformPromo,
        ]);
    }

    /**
     * Delete a platform promo
     */
    public function destroy(Request $request, PlatformPromo $platformPromo)
    {
        $this->authorizePlatformAdmin($request);

        // Deactivate & unbind all child discounts
        Discount::where('platform_promo_id', $platformPromo->id)->update([
            'active' => false,
        ]);

        $platformPromo->delete();

        return response()->json(['message' => 'Promo Platform berhasil dihapus.']);
    }

    /**
     * Toggle active state
     */
    public function toggleActive(Request $request, PlatformPromo $platformPromo)
    {
        $this->authorizePlatformAdmin($request);

        $platformPromo->is_active = !$platformPromo->is_active;
        $platformPromo->updated_by = $request->user()->id;
        $platformPromo->save();

        // Sync to discounts
        Discount::where('platform_promo_id', $platformPromo->id)->update([
            'active' => $platformPromo->is_active,
        ]);

        return response()->json([
            'message'   => $platformPromo->is_active ? 'Promo diaktifkan.' : 'Promo dinonaktifkan.',
            'is_active' => $platformPromo->is_active,
        ]);
    }

    /**
     * Distribute promo to target businesses (The core requirement: "disebar ke owner bisnis")
     */
    public function distribute(Request $request, PlatformPromo $platformPromo)
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'business_ids'   => 'nullable|array',
            'business_ids.*' => 'exists:businesses,id',
            'target_all'     => 'nullable|boolean',
            'usage_limit'    => 'nullable|integer|min:1',
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date',
            'notes'          => 'nullable|string|max:500',
        ]);

        $targetAll = !empty($validated['target_all']);
        $businessIds = $validated['business_ids'] ?? [];

        if ($targetAll) {
            $businessIds = Business::where('status', 'active')->pluck('id')->toArray();
        }

        if (empty($businessIds)) {
            return response()->json(['message' => 'Pilih minimal satu bisnis untuk menerima promo.'], 422);
        }

        $usageLimit = (int)($validated['usage_limit'] ?? $platformPromo->default_usage_limit ?? 50);
        $startDate  = $validated['start_date'] ?? $platformPromo->start_date?->format('Y-m-d') ?? now()->toDateString();
        $endDate    = $validated['end_date'] ?? $platformPromo->end_date?->format('Y-m-d') ?? now()->addMonths(1)->toDateString();
        $notes      = $validated['notes'] ?? null;
        $userId     = $request->user()->id;

        $distributedCount = 0;
        $updatedCount = 0;

        DB::transaction(function () use (
            $platformPromo,
            $businessIds,
            $usageLimit,
            $startDate,
            $endDate,
            $notes,
            $userId,
            &$distributedCount,
            &$updatedCount
        ) {
            foreach ($businessIds as $bizId) {
                $biz = Business::find($bizId);
                if (!$biz) continue;

                // 1. Create or update the synced Discount in the tenant business
                // This allows the tenant POS to seamlessly recognize and apply the promo
                $discount = Discount::withoutGlobalScopes()
                    ->where('business_id', $bizId)
                    ->where('platform_promo_id', $platformPromo->id)
                    ->first();

                if (!$discount) {
                    $discount = Discount::withoutGlobalScopes()->create([
                        'business_id'         => $bizId,
                        'platform_promo_id'   => $platformPromo->id,
                        'is_platform_promo'   => true,
                        'name'                => $platformPromo->title,
                        'code'                => $platformPromo->code,
                        'type'                => $platformPromo->type,
                        'value'               => $platformPromo->value,
                        'min_order_amount'    => $platformPromo->min_order_amount,
                        'max_discount_amount' => $platformPromo->max_discount_amount,
                        'start_date'          => $startDate,
                        'end_date'            => $endDate,
                        'usage_limit'         => $usageLimit,
                        'used_count'          => 0,
                        'is_auto_apply'       => false,
                        'active'              => $platformPromo->is_active,
                        'notes'               => "Promo Platform Superadmin: " . ($notes ?: $platformPromo->description),
                        'created_by'          => $userId,
                        'updated_by'          => $userId,
                    ]);
                } else {
                    $discount->update([
                        'name'                => $platformPromo->title,
                        'code'                => $platformPromo->code,
                        'type'                => $platformPromo->type,
                        'value'               => $platformPromo->value,
                        'min_order_amount'    => $platformPromo->min_order_amount,
                        'max_discount_amount' => $platformPromo->max_discount_amount,
                        'start_date'          => $startDate,
                        'end_date'            => $endDate,
                        'usage_limit'         => $usageLimit,
                        'active'              => $platformPromo->is_active,
                        'notes'               => "Promo Platform Superadmin: " . ($notes ?: $platformPromo->description),
                        'updated_by'          => $userId,
                    ]);
                }

                // 2. Create or update the PlatformPromoBusiness record
                $promoBiz = PlatformPromoBusiness::where('platform_promo_id', $platformPromo->id)
                    ->where('business_id', $bizId)
                    ->first();

                if (!$promoBiz) {
                    PlatformPromoBusiness::create([
                        'platform_promo_id' => $platformPromo->id,
                        'business_id'       => $bizId,
                        'discount_id'       => $discount->id,
                        'usage_limit'       => $usageLimit,
                        'used_count'        => 0,
                        'start_date'        => $startDate,
                        'end_date'          => $endDate,
                        'status'            => 'ACTIVE',
                        'notes'             => $notes,
                        'distributed_at'    => now(),
                        'distributed_by'    => $userId,
                    ]);
                    $distributedCount++;
                } else {
                    $promoBiz->update([
                        'discount_id'    => $discount->id,
                        'usage_limit'    => $usageLimit,
                        'start_date'     => $startDate,
                        'end_date'       => $endDate,
                        'status'         => 'ACTIVE',
                        'notes'          => $notes ?: $promoBiz->notes,
                        'distributed_at' => now(),
                        'distributed_by' => $userId,
                    ]);
                    $updatedCount++;
                }
            }
        });

        return response()->json([
            'message' => "Promo '{$platformPromo->title}' berhasil disebar ke {$distributedCount} bisnis baru (dan diperbarui pada {$updatedCount} bisnis).",
            'stats'   => [
                'distributed_new' => $distributedCount,
                'updated'         => $updatedCount,
                'total_targeted'  => count($businessIds),
            ],
        ]);
    }

    /**
     * Update distribution parameters for a single business (quota, expired date, status)
     */
    public function updateBusinessDistribution(Request $request, PlatformPromo $platformPromo, Business $business)
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'usage_limit' => 'required|integer|min:1',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
            'status'      => 'required|string|in:ACTIVE,REVOKED,EXPIRED,QUOTA_DEPLETED',
            'notes'       => 'nullable|string|max:500',
        ]);

        $promoBiz = PlatformPromoBusiness::where('platform_promo_id', $platformPromo->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $promoBiz->update($validated);

        // Sync to tenant Discount
        if ($promoBiz->discount_id) {
            $discount = Discount::withoutGlobalScopes()->find($promoBiz->discount_id);
            if ($discount) {
                $discount->update([
                    'usage_limit' => $validated['usage_limit'],
                    'start_date'  => $validated['start_date'] ?? $discount->start_date,
                    'end_date'    => $validated['end_date'] ?? $discount->end_date,
                    'active'      => $validated['status'] === 'ACTIVE',
                ]);
            }
        }

        return response()->json([
            'message'      => "Distribusi promo untuk bisnis '{$business->name}' berhasil diperbarui.",
            'distribution' => $promoBiz->fresh(['business', 'discount']),
        ]);
    }

    /**
     * Revoke promo from a specific business
     */
    public function revokeBusiness(Request $request, PlatformPromo $platformPromo, Business $business)
    {
        $this->authorizePlatformAdmin($request);

        $promoBiz = PlatformPromoBusiness::where('platform_promo_id', $platformPromo->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $promoBiz->update(['status' => 'REVOKED']);

        if ($promoBiz->discount_id) {
            Discount::withoutGlobalScopes()
                ->where('id', $promoBiz->discount_id)
                ->update(['active' => false]);
        }

        return response()->json([
            'message' => "Promo '{$platformPromo->title}' berhasil dicabut dari bisnis '{$business->name}'.",
        ]);
    }

    /**
     * List businesses for selector dropdown/modal in Superadmin
     */
    public function targetBusinesses(Request $request)
    {
        $this->authorizePlatformAdmin($request);

        $promoId = $request->query('promo_id');

        $query = Business::query()
            ->select('id', 'name', 'owner_name', 'email', 'phone', 'package_type', 'status', 'created_at')
            ->withCount('outlets')
            ->orderBy('name');

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('owner_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $businesses = $query->get();

        // If promo_id is provided, attach existing distribution details
        if ($promoId) {
            $existing = PlatformPromoBusiness::where('platform_promo_id', $promoId)
                ->get()
                ->keyBy('business_id');

            $businesses->transform(function ($biz) use ($existing) {
                $dist = $existing->get($biz->id);
                $biz->is_already_distributed = (bool)$dist;
                $biz->current_distribution = $dist;
                return $biz;
            });
        }

        return response()->json($businesses);
    }

    /**
     * Owner Bisnis: View Platform Promos allocated to my business
     */
    public function myPromos(Request $request)
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        if (!$businessId) {
            return response()->json([]);
        }

        $distributions = PlatformPromoBusiness::where('business_id', $businessId)
            ->with(['platformPromo'])
            ->orderByDesc('id')
            ->get();

        return response()->json($distributions);
    }
}
