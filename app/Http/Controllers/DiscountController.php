<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiscountController extends Controller
{
    public function index(Request $request)
    {
        $query = Discount::with(['outlet', 'rewardMenu', 'creator', 'updater'])
            ->orderByDesc('id');

        if ($request->has('active') && $request->active !== '' && $request->active !== 'ALL') {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where(function ($q) use ($request) {
                $q->whereNull('outlet_id')->orWhere('outlet_id', $request->outlet_id);
            });
        }

        if ($request->filled('search')) {
            $s = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                  ->orWhere('code', 'like', $s)
                  ->orWhere('notes', 'like', $s);
            });
        }

        $discounts = $query->get();

        // Calculate statistics for each discount
        $discountIds = $discounts->pluck('id')->toArray();
        $totals = DB::table('transactions')
            ->whereIn('discount_id', $discountIds)
            ->where('status', 'PAID')
            ->select('discount_id', DB::raw('SUM(discount_amount) as total_savings'), DB::raw('COUNT(DISTINCT order_number) as orders_count'))
            ->groupBy('discount_id')
            ->get()
            ->keyBy('discount_id');

        $result = $discounts->map(function ($d) use ($totals) {
            $stat = $totals->get($d->id);
            $d->total_savings = (float)($stat?->total_savings ?? 0);
            $d->orders_count  = (int)($stat?->orders_count ?? 0);
            return $d;
        });

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'name'                => 'required|string|max:100',
            'code'                => 'nullable|string|max:50',
            'type'                => 'required|string|in:PERCENTAGE,FIXED',
            'value'               => 'required|numeric|min:0',
            'requires_points'     => 'nullable|integer|min:0',
            'reward_type'         => 'nullable|string|in:DISCOUNT,FREE_MENU',
            'reward_menu_id'      => 'nullable|exists:menus,id',
            'scope'               => 'nullable|string|in:TRANSACTION,CATEGORY,MENU_ITEM',
            'scope_target_id'     => 'nullable|integer',
            'min_order_amount'    => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'start_date'          => 'nullable|date',
            'end_date'            => 'nullable|date|after_or_equal:start_date',
            'usage_limit'         => 'nullable|integer|min:1',
            'outlet_id'           => 'nullable|exists:outlets,id',
            'is_auto_apply'       => 'nullable|boolean',
            'active'              => 'nullable|boolean',
            'notes'               => 'nullable|string|max:1000',
        ]);

        if (!empty($validated['code'])) {
            $validated['code'] = strtoupper(trim($validated['code']));
            $exists = Discount::where('business_id', $businessId)
                ->where('code', $validated['code'])
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => "Kode promo '{$validated['code']}' sudah digunakan. Gunakan kode lain."
                ], 422);
            }
        }

        $validated['business_id'] = $businessId;
        $validated['created_by']  = $request->user()->id;
        $validated['used_count']  = 0;

        $discount = Discount::create($validated);
        $discount->load(['outlet', 'rewardMenu', 'creator']);

        return response()->json($discount, 201);
    }

    public function show(Discount $discount)
    {
        $discount->load(['outlet', 'rewardMenu', 'creator', 'updater']);
        return response()->json($discount);
    }

    public function update(Request $request, Discount $discount)
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'name'                => 'required|string|max:100',
            'code'                => 'nullable|string|max:50',
            'type'                => 'required|string|in:PERCENTAGE,FIXED',
            'value'               => 'required|numeric|min:0',
            'requires_points'     => 'nullable|integer|min:0',
            'reward_type'         => 'nullable|string|in:DISCOUNT,FREE_MENU',
            'reward_menu_id'      => 'nullable|exists:menus,id',
            'scope'               => 'nullable|string|in:TRANSACTION,CATEGORY,MENU_ITEM',
            'scope_target_id'     => 'nullable|integer',
            'min_order_amount'    => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'start_date'          => 'nullable|date',
            'end_date'            => 'nullable|date|after_or_equal:start_date',
            'usage_limit'         => 'nullable|integer|min:1',
            'outlet_id'           => 'nullable|exists:outlets,id',
            'is_auto_apply'       => 'nullable|boolean',
            'active'              => 'nullable|boolean',
            'notes'               => 'nullable|string|max:1000',
        ]);

        if (!empty($validated['code'])) {
            $validated['code'] = strtoupper(trim($validated['code']));
            $exists = Discount::where('business_id', $businessId)
                ->where('code', $validated['code'])
                ->where('id', '!=', $discount->id)
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => "Kode promo '{$validated['code']}' sudah digunakan pada promo lain."
                ], 422);
            }
        }

        $validated['updated_by'] = $request->user()->id;
        $discount->update($validated);
        $discount->load(['outlet', 'rewardMenu', 'creator', 'updater']);

        return response()->json($discount);
    }

    public function destroy(Discount $discount)
    {
        // If discount already used in transactions, keep for historical records and deactivate
        $usageCount = $discount->transactions()->count();
        if ($usageCount > 0) {
            $discount->update(['active' => false]);
            return response()->json([
                'message' => "Promo '{$discount->name}' telah digunakan pada {$usageCount} transaksi. Status diubah menjadi Nonaktif."
            ]);
        }

        $discount->delete();
        return response()->json(['message' => 'Promo berhasil dihapus.']);
    }

    public function toggleActive(Discount $discount)
    {
        $discount->active = !$discount->active;
        $discount->updated_by = auth()->id();
        $discount->save();

        return response()->json([
            'message' => "Status promo '{$discount->name}' berhasil diubah menjadi " . ($discount->active ? 'Aktif' : 'Nonaktif') . ".",
            'active'  => $discount->active,
        ]);
    }

    /**
     * List all available discounts for POS cashier (active, within date, quota not full).
     */
    public function availableForPos(Request $request)
    {
        $outletId = $request->outlet_id ?? $request->user()->outlet_id;
        $date = $request->date ?? date('Y-m-d');

        $discounts = Discount::with('rewardMenu')
            ->validNow($date)
            ->where(function ($q) use ($outletId) {
                if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
                    $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
                }
            })
            ->orderBy('is_auto_apply', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json($discounts);
    }

    /**
     * Validate a promo voucher code entered in POS and calculate discount amount.
     */
    public function validateCode(Request $request)
    {
        $data = $request->validate([
            'code'        => 'required|string',
            'subtotal'    => 'required|numeric|min:0',
            'outlet_id'   => 'nullable|integer',
            'date'        => 'nullable|date',
            'customer_id' => 'nullable|integer',
        ]);

        $code = strtoupper(trim($data['code']));
        $subtotal = (float)$data['subtotal'];
        $outletId = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : $request->user()->outlet_id;
        $date = $data['date'] ?? date('Y-m-d');

        $discount = Discount::with('rewardMenu')
            ->where('code', $code)
            ->where('business_id', $request->user()->business_id)
            ->first();

        if (!$discount) {
            return response()->json([
                'valid'   => false,
                'message' => "Kode promo '{$code}' tidak ditemukan atau tidak berlaku.",
            ], 422);
        }

        $customer = !empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;
        $check = $discount->validateForOrder($subtotal, $outletId, $date, $customer);
        if (!$check['valid']) {
            return response()->json([
                'valid'   => false,
                'message' => $check['message'],
            ], 422);
        }

        $discountAmount = $discount->calculateDiscountAmount($subtotal);
        $netTotal = max(0, $subtotal - $discountAmount);

        return response()->json([
            'valid'           => true,
            'discount'        => $discount,
            'subtotal'        => $subtotal,
            'discount_amount' => $discountAmount,
            'net_total'       => $netTotal,
            'message'         => "Promo '{$discount->name}' berhasil diterapkan! Hemat Rp " . number_format($discountAmount, 0, ',', '.') . ".",
        ]);
    }
}
