<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PointRedemption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers with KPI metrics.
     */
    public function index(Request $request)
    {
        $businessId = $request->user()->business_id;

        // Auto-assign code to legacy customer records if any missing
        $missingCodes = Customer::where('business_id', $businessId)
            ->where(function ($q) {
                $q->whereNull('code')->orWhere('code', '');
            })
            ->get();

        foreach ($missingCodes as $mc) {
            $mc->update(['code' => Customer::generateCode($businessId)]);
        }

        $query = Customer::with(['creator', 'updater'])
            ->orderByDesc('id');

        if ($request->has('active') && $request->active !== '' && $request->active !== 'ALL') {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $rawS = trim($request->search);
            $s = '%' . $rawS . '%';
            $numericS = preg_replace('/[^0-9]/', '', $rawS);

            $query->where(function ($q) use ($s, $numericS, $rawS) {
                $q->where('phone', 'like', $s)
                  ->orWhere('name', 'like', $s)
                  ->orWhere('code', 'like', $s)
                  ->orWhere('email', 'like', $s)
                  ->orWhere('notes', 'like', $s);

                if (!empty($numericS) && strlen($numericS) >= 2) {
                    $q->orWhere(DB::raw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '')"), 'like', '%' . $numericS . '%');
                }
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'id');
        $sortDir = strtolower($request->get('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['id', 'name', 'total_points', 'total_visits', 'total_spent', 'joined_at', 'created_at'])) {
            $query->reorder($sortBy, $sortDir);
        }

        $customers = $query->get();

        // Calculate KPI summaries
        $totalMembers  = Customer::count();
        $activeMembers = Customer::where('active', true)->count();
        $totalPoints   = (int)Customer::sum('total_points');
        $totalSpent    = (float)Customer::sum('total_spent');
        $totalVisits   = (int)Customer::sum('total_visits');
        $avgVisits     = $totalMembers > 0 ? round($totalVisits / $totalMembers, 1) : 0;

        return response()->json([
            'customers' => $customers,
            'summary'   => [
                'total_members'  => $totalMembers,
                'active_members' => $activeMembers,
                'total_points'   => $totalPoints,
                'total_spent'    => $totalSpent,
                'total_visits'   => $totalVisits,
                'avg_visits'     => $avgVisits,
            ],
        ]);
    }

    /**
     * Store a newly created customer / member.
     */
    public function store(Request $request)
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'phone'      => 'required|string|max:30',
            'email'      => 'nullable|email|max:100',
            'address'    => 'nullable|string|max:500',
            'birth_date' => 'nullable|date',
            'notes'      => 'nullable|string|max:1000',
            'active'     => 'nullable|boolean',
        ]);

        $phone = trim($validated['phone']);
        $phoneExists = Customer::where('business_id', $businessId)
            ->where('phone', $phone)
            ->exists();

        if ($phoneExists) {
            return response()->json([
                'message' => "Nomor HP '{$phone}' sudah terdaftar pada member lain."
            ], 422);
        }

        // Kode member 100% otomatis digenerate oleh sistem
        $code = Customer::generateCode($businessId);

        $customer = Customer::create([
            'business_id'  => $businessId,
            'code'         => $code,
            'name'         => trim($validated['name']),
            'phone'        => $phone,
            'email'        => !empty($validated['email']) ? trim($validated['email']) : null,
            'address'      => $validated['address'] ?? null,
            'birth_date'   => $validated['birth_date'] ?? null,
            'notes'        => $validated['notes'] ?? null,
            'total_points' => 0,
            'total_visits' => 0,
            'total_spent'  => 0,
            'joined_at'    => now()->toDateString(),
            'active'       => $validated['active'] ?? true,
            'created_by'   => $request->user()->id,
        ]);

        $customer->load(['creator']);

        return response()->json([
            'message'  => "Member '{$customer->name}' ({$customer->code}) berhasil didaftarkan.",
            'customer' => $customer,
        ], 201);
    }

    /**
     * Display the specified customer with transaction & point history.
     */
    public function show(Customer $customer)
    {
        $customer->load([
            'creator',
            'updater',
            'pointRedemptions.discount',
            'pointRedemptions.creator',
        ]);

        // Fetch recent transactions of this customer
        $transactions = DB::table('transactions')
            ->where('customer_id', $customer->id)
            ->where('status', 'PAID')
            ->select(
                'order_number',
                'date',
                'payment_method',
                'outlet_id',
                'discount_name',
                DB::raw('SUM(total_price) as total_amount'),
                DB::raw('SUM(discount_amount) as total_discount'),
                DB::raw('COUNT(id) as items_count'),
                DB::raw('MAX(created_at) as created_at')
            )
            ->groupBy('order_number', 'date', 'payment_method', 'outlet_id', 'discount_name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'customer'          => $customer,
            'recent_orders'     => $transactions,
            'point_redemptions' => $customer->pointRedemptions,
        ]);
    }

    /**
     * Update customer details.
     */
    public function update(Request $request, Customer $customer)
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'phone'        => 'required|string|max:30',
            'email'        => 'nullable|email|max:100',
            'address'      => 'nullable|string|max:500',
            'birth_date'   => 'nullable|date',
            'notes'        => 'nullable|string|max:1000',
            'active'       => 'nullable|boolean',
            'total_points' => 'nullable|integer|min:0',
        ]);

        $phone = trim($validated['phone']);
        $phoneExists = Customer::where('business_id', $businessId)
            ->where('phone', $phone)
            ->where('id', '!=', $customer->id)
            ->exists();

        if ($phoneExists) {
            return response()->json([
                'message' => "Nomor HP '{$phone}' sudah digunakan pada member lain."
            ], 422);
        }

        if (empty($customer->code)) {
            $customer->code = Customer::generateCode($businessId);
        }

        $customer->name = trim($validated['name']);
        $customer->phone = $phone;
        $customer->email = !empty($validated['email']) ? trim($validated['email']) : null;
        $customer->address = $validated['address'] ?? null;
        $customer->birth_date = $validated['birth_date'] ?? null;
        $customer->notes = $validated['notes'] ?? null;
        if (isset($validated['active'])) {
            $customer->active = (bool)$validated['active'];
        }
        if (isset($validated['total_points']) && $request->user()->isOwnerBisnis()) {
            $customer->total_points = (int)$validated['total_points'];
        }
        $customer->updated_by = $request->user()->id;
        $customer->save();

        $customer->load(['creator', 'updater']);

        return response()->json([
            'message'  => "Data member '{$customer->name}' berhasil diperbarui.",
            'customer' => $customer,
        ]);
    }

    /**
     * Remove or deactivate a customer.
     */
    public function destroy(Customer $customer)
    {
        $trxCount = $customer->transactions()->count();
        if ($trxCount > 0) {
            $customer->update(['active' => false]);
            return response()->json([
                'message' => "Member '{$customer->name}' memiliki {$trxCount} riwayat transaksi. Status diubah menjadi Nonaktif."
            ]);
        }

        $customer->delete();
        return response()->json([
            'message' => "Member '{$customer->name}' berhasil dihapus."
        ]);
    }

    /**
     * Bulk delete customers for the current business.
     */
    public function bulkDelete(Request $request)
    {
        $businessId = $request->user()->business_id ?? null;
        $ids = $request->input('ids', []);

        if (empty($ids) || !is_array($ids)) {
            return response()->json(['message' => 'Tidak ada member yang dipilih.'], 400);
        }

        $query = Customer::whereIn('id', $ids);
        if ($businessId) {
            $query->where('business_id', $businessId);
        }
        $customers = $query->get();

        if ($customers->isEmpty()) {
            return response()->json(['message' => 'Data member tidak ditemukan atau tidak memiliki hak akses.'], 404);
        }

        $deletedCount = 0;
        $deactivatedCount = 0;

        DB::transaction(function () use ($customers, &$deletedCount, &$deactivatedCount) {
            foreach ($customers as $customer) {
                $trxCount = $customer->transactions()->count();
                if ($trxCount > 0) {
                    $customer->update(['active' => false]);
                    $deactivatedCount++;
                } else {
                    $customer->delete();
                    $deletedCount++;
                }
            }
        });

        $msgParts = [];
        if ($deletedCount > 0) $msgParts[] = "{$deletedCount} member dihapus";
        if ($deactivatedCount > 0) $msgParts[] = "{$deactivatedCount} member dinonaktifkan (karena memiliki riwayat transaksi)";

        return response()->json([
            'message' => 'Berhasil memproses: ' . implode(', ', $msgParts) . '.',
            'deleted_count' => $deletedCount,
            'deactivated_count' => $deactivatedCount
        ]);
    }

    /**
     * Autocomplete search for POS cashier (Prioritizes Phone Number Search).
     */
    public function searchForPos(Request $request)
    {
        $rawQ = trim($request->get('q', $request->get('search', '')));
        if (empty($rawQ)) {
            // Return top recent active members
            $customers = Customer::where('active', true)
                ->orderByDesc('updated_at')
                ->limit(15)
                ->get(['id', 'code', 'name', 'phone', 'email', 'total_points', 'total_visits', 'total_spent', 'joined_at']);
            return response()->json($customers);
        }

        // Clean numeric query for phone search
        $numericQ = preg_replace('/[^0-9]/', '', $rawQ);
        $phoneVariants = [];
        if (!empty($numericQ)) {
            $phoneVariants[] = $numericQ;
            if (str_starts_with($numericQ, '62')) {
                $phoneVariants[] = '0' . substr($numericQ, 2);
                $phoneVariants[] = substr($numericQ, 2);
            } elseif (str_starts_with($numericQ, '0')) {
                $phoneVariants[] = '62' . substr($numericQ, 1);
                $phoneVariants[] = substr($numericQ, 1);
            }
        }

        $s = '%' . $rawQ . '%';
        $query = Customer::where('active', true);

        if (!empty($numericQ) && strlen($numericQ) >= 2) {
            // PRIORITY 1: Phone search (including cleaned/unformatted digits)
            $query->where(function ($sq) use ($s, $phoneVariants, $numericQ) {
                foreach ($phoneVariants as $pv) {
                    $sq->orWhere('phone', 'like', '%' . $pv . '%');
                    $sq->orWhere(DB::raw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '')"), 'like', '%' . $pv . '%');
                }
                // Fallback matching
                $sq->orWhere('code', 'like', $s);
                $sq->orWhere('name', 'like', $s);
            });

            // Order: Exact phone match first, then starts-with phone, then contains phone
            $topVariant = $phoneVariants[0] ?? $numericQ;
            $query->orderByRaw("
                CASE 
                    WHEN phone = ? THEN 1
                    WHEN REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '') = ? THEN 2
                    WHEN phone LIKE ? THEN 3
                    WHEN REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ? THEN 4
                    WHEN phone LIKE ? THEN 5
                    ELSE 6
                END ASC, name ASC",
                [$rawQ, $topVariant, $topVariant . '%', $topVariant . '%', '%' . $topVariant . '%']
            );
        } else {
            // Text search (name / code / phone)
            $query->where(function ($sq) use ($s) {
                $sq->where('phone', 'like', $s)
                   ->orWhere('name', 'like', $s)
                   ->orWhere('code', 'like', $s);
            })->orderBy('name', 'asc');
        }

        $customers = $query->limit(20)
            ->get(['id', 'code', 'name', 'phone', 'email', 'total_points', 'total_visits', 'total_spent', 'joined_at']);

        return response()->json($customers);
    }
}
