<?php

namespace App\Http\Controllers;

use App\Models\PlatformPromo;
use App\Models\PlatformPromoBusiness;
use App\Models\PlatformPromoPayment;
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
                DB::raw('SUM(amount_payable) as total_payable'),
                DB::raw('SUM(amount_paid) as total_paid'),
                DB::raw('COUNT(DISTINCT business_id) as business_count'),
                DB::raw("COUNT(CASE WHEN approval_status = 'PENDING' THEN 1 END) as pending_approvals"),
                DB::raw("COUNT(CASE WHEN approval_status = 'ACCEPTED' THEN 1 END) as accepted_count"),
                DB::raw("COUNT(CASE WHEN approval_status = 'REJECTED' THEN 1 END) as rejected_count")
            )
            ->groupBy('platform_promo_id')
            ->get()
            ->keyBy('platform_promo_id');

        $result = $promos->map(function ($p) use ($aggregates) {
            $agg = $aggregates->get($p->id);
            $p->total_used         = (int)($agg?->total_used ?? 0);
            $p->total_quota        = (int)($agg?->total_quota ?? 0);
            $p->total_payable      = (float)($agg?->total_payable ?? 0);
            $p->total_paid         = (float)($agg?->total_paid ?? 0);
            $p->remaining_payment  = max(0, $p->total_payable - $p->total_paid);
            $p->business_count     = (int)($agg?->business_count ?? 0);
            $p->pending_approvals  = (int)($agg?->pending_approvals ?? 0);
            $p->accepted_count     = (int)($agg?->accepted_count ?? 0);
            $p->rejected_count     = (int)($agg?->rejected_count ?? 0);
            return $p;
        });

        // Platform-wide overview stats
        $totalActivePromos            = PlatformPromo::where('is_active', true)->count();
        $totalDistributionsCount      = PlatformPromoBusiness::count();
        $totalRedemptionsCount        = PlatformPromoBusiness::sum('used_count');
        $totalUniqueBusinessesReached = PlatformPromoBusiness::distinct('business_id')->count('business_id');
        $totalAmountPayablePlatform   = (float)PlatformPromoBusiness::sum('amount_payable');
        $totalAmountPaidPlatform      = (float)PlatformPromoBusiness::sum('amount_paid');
        $totalRemainingPaymentPlatform= max(0, $totalAmountPayablePlatform - $totalAmountPaidPlatform);
        $totalPendingApprovals        = PlatformPromoBusiness::where('approval_status', 'PENDING')->count();

        return response()->json([
            'promos' => $result,
            'summary' => [
                'total_promos'              => $promos->count(),
                'active_promos'             => $totalActivePromos,
                'total_distributions'       => $totalDistributionsCount,
                'total_redemptions'         => (int)$totalRedemptionsCount,
                'unique_businesses_reached' => $totalUniqueBusinessesReached,
                'total_amount_payable'      => $totalAmountPayablePlatform,
                'total_amount_paid'         => $totalAmountPaidPlatform,
                'total_remaining_payment'   => $totalRemainingPaymentPlatform,
                'total_pending_approvals'   => $totalPendingApprovals,
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
            'title'                  => 'required|string|max:150',
            'code'                   => 'required|string|max:50|unique:platform_promos,code',
            'description'            => 'nullable|string',
            'type'                   => 'required|string|in:PERCENTAGE,FIXED',
            'value'                  => 'required|numeric|min:0.01',
            'min_order_amount'       => 'nullable|numeric|min:0',
            'max_discount_amount'    => 'nullable|numeric|min:0',
            'default_usage_limit'    => 'required|integer|min:1',
            'default_subsidy_amount' => 'nullable|numeric|min:0',
            'start_date'             => 'nullable|date',
            'end_date'               => 'nullable|date|after_or_equal:start_date',
            'banner_color'           => 'nullable|string|max:30',
            'badge_text'             => 'nullable|string|max:50',
            'is_active'              => 'nullable|boolean',
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;
        $validated['banner_color'] = $validated['banner_color'] ?: '#4F46E5';
        $validated['min_order_amount'] = (float)($validated['min_order_amount'] ?? 0);
        $validated['max_discount_amount'] = isset($validated['max_discount_amount']) && $validated['max_discount_amount'] !== '' ? (float)$validated['max_discount_amount'] : null;
        $validated['default_subsidy_amount'] = (float)($validated['default_subsidy_amount'] ?? 0);

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
            'distributions.approver',
            'distributions.rejector',
            'distributions.payments.creator',
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
            'title'                  => 'required|string|max:150',
            'code'                   => [
                'required', 'string', 'max:50',
                Rule::unique('platform_promos', 'code')->ignore($platformPromo->id)
            ],
            'description'            => 'nullable|string',
            'type'                   => 'required|string|in:PERCENTAGE,FIXED',
            'value'                  => 'required|numeric|min:0.01',
            'min_order_amount'       => 'nullable|numeric|min:0',
            'max_discount_amount'    => 'nullable|numeric|min:0',
            'default_usage_limit'    => 'required|integer|min:1',
            'default_subsidy_amount' => 'nullable|numeric|min:0',
            'start_date'             => 'nullable|date',
            'end_date'               => 'nullable|date|after_or_equal:start_date',
            'banner_color'           => 'nullable|string|max:30',
            'badge_text'             => 'nullable|string|max:50',
            'is_active'              => 'nullable|boolean',
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['updated_by'] = $request->user()->id;
        $validated['min_order_amount'] = (float)($validated['min_order_amount'] ?? 0);
        $validated['max_discount_amount'] = isset($validated['max_discount_amount']) && $validated['max_discount_amount'] !== '' ? (float)$validated['max_discount_amount'] : null;
        $validated['default_subsidy_amount'] = (float)($validated['default_subsidy_amount'] ?? 0);

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

        // If inactive, disable all child discounts
        if (!$platformPromo->is_active) {
            Discount::where('platform_promo_id', $platformPromo->id)->update([
                'active' => false,
            ]);
        } else {
            // If active, enable only discounts that were ACCEPTED by the owner
            $acceptedBizIds = PlatformPromoBusiness::where('platform_promo_id', $platformPromo->id)
                ->where('approval_status', 'ACCEPTED')
                ->where('status', 'ACTIVE')
                ->pluck('business_id');

            Discount::where('platform_promo_id', $platformPromo->id)
                ->whereIn('business_id', $acceptedBizIds)
                ->update(['active' => true]);
        }

        return response()->json([
            'message'   => $platformPromo->is_active ? 'Promo diaktifkan.' : 'Promo dinonaktifkan.',
            'is_active' => $platformPromo->is_active,
        ]);
    }

    /**
     * Distribute promo to target businesses
     * Allows setting per-business quota and compensation amount (uang pengganti promo)
     * Distribution initiates in 'PENDING' approval status awaiting Business Owner's accept/reject
     */
    public function distribute(Request $request, PlatformPromo $platformPromo)
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'business_ids'   => 'nullable|array',
            'business_ids.*' => 'exists:businesses,id',
            'target_all'     => 'nullable|boolean',
            'usage_limit'    => 'nullable|integer|min:1',
            'amount_payable' => 'nullable|numeric|min:0',
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

        $usageLimit    = (int)($validated['usage_limit'] ?? $platformPromo->default_usage_limit ?? 50);
        $amountPayable = isset($validated['amount_payable']) && $validated['amount_payable'] !== ''
            ? (float)$validated['amount_payable']
            : (float)(($platformPromo->default_subsidy_amount ?? 0) * $usageLimit);

        $startDate  = $validated['start_date'] ?? $platformPromo->start_date?->format('Y-m-d') ?? now()->toDateString();
        $endDate    = $validated['end_date'] ?? $platformPromo->end_date?->format('Y-m-d') ?? now()->addMonths(1)->toDateString();
        $notes      = $validated['notes'] ?? null;
        $userId     = $request->user()->id;

        $distributedCount = 0;
        $updatedCount     = 0;

        DB::transaction(function () use (
            $platformPromo,
            $businessIds,
            $usageLimit,
            $amountPayable,
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

                // 1. Create or fetch the synced Discount in the tenant business
                // Note: Initially active = false until approved by Business Owner!
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
                        'active'              => false, // Pending Owner Approval
                        'notes'               => "Promo Platform Superadmin (Menunggu Persetujuan Owner): " . ($notes ?: $platformPromo->description),
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
                        'approval_status'   => 'PENDING', // Awaiting Owner Bisnis Approval
                        'amount_payable'    => $amountPayable,
                        'amount_paid'       => 0,
                        'payment_status'    => 'UNPAID',
                        'notes'             => $notes,
                        'distributed_at'    => now(),
                        'distributed_by'    => $userId,
                    ]);
                    $distributedCount++;
                } else {
                    $promoBiz->update([
                        'discount_id'    => $discount->id,
                        'usage_limit'    => $usageLimit,
                        'amount_payable' => $amountPayable,
                        'start_date'     => $startDate,
                        'end_date'       => $endDate,
                        'status'         => 'ACTIVE',
                        'approval_status'=> $promoBiz->approval_status === 'REJECTED' ? 'PENDING' : $promoBiz->approval_status,
                        'notes'          => $notes ?: $promoBiz->notes,
                        'distributed_at' => now(),
                        'distributed_by' => $userId,
                    ]);
                    $updatedCount++;
                }
            }
        });

        return response()->json([
            'message' => "Promo '{$platformPromo->title}' berhasil disebar ke {$distributedCount} bisnis baru (dan diperbarui pada {$updatedCount} bisnis) dengan status menunggu persetujuan owner bisnis.",
            'stats'   => [
                'distributed_new' => $distributedCount,
                'updated'         => $updatedCount,
                'total_targeted'  => count($businessIds),
                'amount_payable'  => $amountPayable,
            ],
        ]);
    }

    /**
     * Update distribution parameters for a single business (quota, compensation amount, expired date, status)
     */
    public function updateBusinessDistribution(Request $request, PlatformPromo $platformPromo, Business $business)
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'usage_limit'     => 'required|integer|min:1',
            'amount_payable'  => 'required|numeric|min:0',
            'amount_paid'     => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date',
            'status'          => 'required|string|in:ACTIVE,REVOKED,EXPIRED,QUOTA_DEPLETED',
            'approval_status' => 'nullable|string|in:PENDING,ACCEPTED,REJECTED',
            'notes'           => 'nullable|string|max:500',
        ]);

        $promoBiz = PlatformPromoBusiness::where('platform_promo_id', $platformPromo->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $amountPayable = (float)$validated['amount_payable'];
        $amountPaid    = isset($validated['amount_paid']) ? (float)$validated['amount_paid'] : (float)$promoBiz->amount_paid;

        $paymentStatus = 'UNPAID';
        if ($amountPaid >= $amountPayable && $amountPayable > 0) {
            $paymentStatus = 'PAID';
        } elseif ($amountPaid > 0) {
            $paymentStatus = 'PARTIALLY_PAID';
        }

        $updateData = [
            'usage_limit'    => $validated['usage_limit'],
            'amount_payable' => $amountPayable,
            'amount_paid'    => $amountPaid,
            'payment_status' => $paymentStatus,
            'start_date'     => $validated['start_date'] ?? $promoBiz->start_date,
            'end_date'       => $validated['end_date'] ?? $promoBiz->end_date,
            'status'         => $validated['status'],
            'notes'          => $validated['notes'] ?? $promoBiz->notes,
        ];

        if (!empty($validated['approval_status'])) {
            $updateData['approval_status'] = $validated['approval_status'];
            if ($validated['approval_status'] === 'ACCEPTED' && !$promoBiz->approved_at) {
                $updateData['approved_at'] = now();
                $updateData['approved_by'] = $request->user()->id;
            }
        }

        $promoBiz->update($updateData);

        // Sync to tenant Discount
        // Active ONLY IF approval_status is ACCEPTED and distribution is ACTIVE
        $isDiscountActive = ($promoBiz->approval_status === 'ACCEPTED') && ($promoBiz->status === 'ACTIVE') && $platformPromo->is_active;

        if ($promoBiz->discount_id) {
            $discount = Discount::withoutGlobalScopes()->find($promoBiz->discount_id);
            if ($discount) {
                $discount->update([
                    'usage_limit' => $validated['usage_limit'],
                    'start_date'  => $validated['start_date'] ?? $discount->start_date,
                    'end_date'    => $validated['end_date'] ?? $discount->end_date,
                    'active'      => $isDiscountActive,
                ]);
            }
        }

        return response()->json([
            'message'      => "Distribusi promo untuk bisnis '{$business->name}' berhasil diperbarui.",
            'distribution' => $promoBiz->fresh(['business', 'discount', 'approver', 'rejector', 'payments.creator']),
        ]);
    }

    /**
     * Record a payment/reimbursement from Platform to Business for promo replacement
     */
    public function storeBusinessPayment(Request $request, PlatformPromo $platformPromo, Business $business)
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_date'   => 'required|date',
            'payment_method' => 'nullable|string|max:50',
            'reference_no'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:500',
        ]);

        $promoBiz = PlatformPromoBusiness::where('platform_promo_id', $platformPromo->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $payment = PlatformPromoPayment::create([
            'platform_promo_business_id' => $promoBiz->id,
            'amount'                     => (float)$validated['amount'],
            'payment_date'               => $validated['payment_date'],
            'payment_method'             => $validated['payment_method'] ?: 'Transfer Bank',
            'reference_no'               => $validated['reference_no'] ?? null,
            'notes'                      => $validated['notes'] ?? null,
            'created_by'                 => $request->user()->id,
        ]);

        // Recalculate total paid
        $totalPaid = (float)PlatformPromoPayment::where('platform_promo_business_id', $promoBiz->id)->sum('amount');
        $paymentStatus = 'UNPAID';
        if ($totalPaid >= (float)$promoBiz->amount_payable && (float)$promoBiz->amount_payable > 0) {
            $paymentStatus = 'PAID';
        } elseif ($totalPaid > 0) {
            $paymentStatus = 'PARTIALLY_PAID';
        }

        $promoBiz->update([
            'amount_paid'       => $totalPaid,
            'payment_status'    => $paymentStatus,
            'last_payment_date' => $validated['payment_date'],
        ]);

        return response()->json([
            'message'      => "Pembayaran uang pengganti promo sebesar Rp " . number_format($payment->amount, 0, ',', '.') . " berhasil dicatat.",
            'payment'      => $payment->load('creator'),
            'distribution' => $promoBiz->fresh(['business', 'discount', 'payments.creator']),
        ]);
    }

    /**
     * List payment history for a business distribution
     */
    public function listBusinessPayments(Request $request, PlatformPromo $platformPromo, Business $business)
    {
        $this->authorizePlatformAdmin($request);

        $promoBiz = PlatformPromoBusiness::where('platform_promo_id', $platformPromo->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $payments = PlatformPromoPayment::where('platform_promo_business_id', $promoBiz->id)
            ->with('creator')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'distribution' => $promoBiz,
            'payments'     => $payments,
        ]);
    }

    /**
     * Delete a payment record
     */
    public function destroyBusinessPayment(Request $request, PlatformPromo $platformPromo, Business $business, $paymentId)
    {
        $this->authorizePlatformAdmin($request);

        $promoBiz = PlatformPromoBusiness::where('platform_promo_id', $platformPromo->id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $payment = PlatformPromoPayment::where('platform_promo_business_id', $promoBiz->id)
            ->where('id', $paymentId)
            ->firstOrFail();

        $payment->delete();

        // Recalculate total paid
        $totalPaid = (float)PlatformPromoPayment::where('platform_promo_business_id', $promoBiz->id)->sum('amount');
        $paymentStatus = 'UNPAID';
        if ($totalPaid >= (float)$promoBiz->amount_payable && (float)$promoBiz->amount_payable > 0) {
            $paymentStatus = 'PAID';
        } elseif ($totalPaid > 0) {
            $paymentStatus = 'PARTIALLY_PAID';
        }

        $promoBiz->update([
            'amount_paid'    => $totalPaid,
            'payment_status' => $paymentStatus,
        ]);

        return response()->json([
            'message'      => 'Catatan pembayaran kompensasi promo berhasil dihapus.',
            'distribution' => $promoBiz->fresh(['business', 'discount', 'payments.creator']),
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
            ->with(['platformPromo', 'approver', 'rejector', 'payments'])
            ->orderByDesc('id')
            ->get();

        return response()->json($distributions);
    }

    /**
     * Owner Bisnis: Accept / Approve Promo from Superadmin
     * Activates the promo in this business so POS cashiers can immediately use it!
     */
    public function acceptPromo(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        if (!$businessId) {
            return response()->json(['message' => 'Akun Anda tidak terhubung dengan bisnis.'], 403);
        }

        $promoBiz = PlatformPromoBusiness::where('id', $id)
            ->where('business_id', $businessId)
            ->with('platformPromo')
            ->firstOrFail();

        if ($promoBiz->status === 'REVOKED') {
            return response()->json(['message' => 'Promo ini telah dicabut oleh Platform Admin.'], 422);
        }

        DB::transaction(function () use ($promoBiz, $user, $businessId) {
            $promoBiz->update([
                'approval_status'  => 'ACCEPTED',
                'approved_at'      => now(),
                'approved_by'      => $user->id,
                'rejected_at'      => null,
                'rejected_by'      => null,
                'rejection_reason' => null,
                'status'           => 'ACTIVE',
            ]);

            $platformPromo = $promoBiz->platformPromo;
            $discount = null;

            if ($promoBiz->discount_id) {
                $discount = Discount::withoutGlobalScopes()->find($promoBiz->discount_id);
            }
            if (!$discount && $platformPromo) {
                $discount = Discount::withoutGlobalScopes()
                    ->where('business_id', $businessId)
                    ->where('platform_promo_id', $platformPromo->id)
                    ->first();
            }

            $today = now()->toDateString();
            $startDate = $promoBiz->start_date ? min($promoBiz->start_date, $today) : $today;
            $endDate = $promoBiz->end_date ?? ($platformPromo ? $platformPromo->end_date?->format('Y-m-d') : null) ?? now()->addMonths(1)->toDateString();

            if (!$discount && $platformPromo) {
                $discount = Discount::withoutGlobalScopes()->create([
                    'business_id'         => $businessId,
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
                    'usage_limit'         => $promoBiz->usage_limit ?? $platformPromo->default_usage_limit ?? 50,
                    'used_count'          => $promoBiz->used_count ?? 0,
                    'is_auto_apply'       => false,
                    'active'              => true,
                    'notes'               => "Promo Platform Superadmin (Disetujui oleh Owner: {$user->name})",
                    'created_by'          => $user->id,
                    'updated_by'          => $user->id,
                ]);
            } elseif ($discount) {
                $discount->update([
                    'active'     => true,
                    'start_date' => $discount->start_date && $discount->start_date <= $today ? $discount->start_date : $today,
                    'end_date'   => $endDate,
                    'notes'      => "Promo Platform Superadmin (Disetujui oleh Owner: {$user->name})",
                ]);
            }

            if ($discount && $promoBiz->discount_id !== $discount->id) {
                $promoBiz->update(['discount_id' => $discount->id]);
            }
        });

        return response()->json([
            'message'      => 'Promo berhasil diterima! Promo ini sekarang aktif di kasir POS outlet Anda.',
            'distribution' => $promoBiz->fresh(['platformPromo', 'approver', 'discount']),
        ]);
    }

    /**
     * Owner Bisnis: Reject Promo from Superadmin
     * Keeps discount disabled in POS
     */
    public function rejectPromo(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        if (!$businessId) {
            return response()->json(['message' => 'Akun Anda tidak terhubung dengan bisnis.'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        $promoBiz = PlatformPromoBusiness::where('id', $id)
            ->where('business_id', $businessId)
            ->firstOrFail();

        DB::transaction(function () use ($promoBiz, $user, $validated) {
            $promoBiz->update([
                'approval_status'  => 'REJECTED',
                'rejected_at'      => now(),
                'rejected_by'      => $user->id,
                'rejection_reason' => $validated['rejection_reason'] ?? 'Ditolak oleh Owner Bisnis.',
            ]);

            // Disable discount in POS
            if ($promoBiz->discount_id) {
                Discount::withoutGlobalScopes()
                    ->where('id', $promoBiz->discount_id)
                    ->update([
                        'active' => false,
                        'notes'  => "Promo Platform Superadmin (Ditolak oleh Owner: {$user->name})",
                    ]);
            }
        });

        return response()->json([
            'message'      => 'Promo telah ditolak. Promo ini tidak akan diaktifkan di kasir POS.',
            'distribution' => $promoBiz->fresh(['platformPromo', 'rejector', 'discount']),
        ]);
    }
}
