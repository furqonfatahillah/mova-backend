<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    /**
     * Get isolated business ID for current user.
     */
    protected function getBusinessId(Request $request): int
    {
        $user = $request->user();
        if ($user && ($user->isPlatformAdmin() || $user->isSuperadminPlatform()) && $request->filled('business_id')) {
            return (int)$request->business_id;
        }

        return (int)($user?->business_id ?: 1);
    }

    /**
     * List Master Accounts (Bagan Akun) scoped by business.
     */
    public function index(Request $request)
    {
        $businessId = $this->getBusinessId($request);

        // Auto-seed default standard system accounts for business if not yet seeded
        Account::seedForBusiness($businessId);

        $query = Account::where('business_id', $businessId);

        // Filter search (code, name, description)
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                  ->orWhere('name', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%")
                  ->orWhere('category', 'like', "%{$s}%");
            });
        }

        // Filter type
        if ($request->filled('type') && $request->type !== 'ALL') {
            $query->where('type', strtoupper(trim($request->type)));
        }

        // Filter category
        if ($request->filled('category') && $request->category !== 'ALL') {
            $query->where('category', strtoupper(trim($request->category)));
        }

        // Filter status (active, inactive, all)
        if ($request->filled('status')) {
            $status = strtolower(trim($request->status));
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // Filter is_system
        if ($request->filled('is_system') && $request->is_system !== 'ALL') {
            $query->where('is_system', filter_var($request->is_system, FILTER_VALIDATE_BOOLEAN));
        }

        $accounts = $query->orderBy('code', 'asc')->get();

        // Calculate metadata statistics for business
        $allBizAccounts = Account::where('business_id', $businessId)->get();
        $totalCount = $allBizAccounts->count();
        $activeCount = $allBizAccounts->where('is_active', true)->count();
        $inactiveCount = $allBizAccounts->where('is_active', false)->count();
        $systemCount = $allBizAccounts->where('is_system', true)->count();
        $customCount = $allBizAccounts->where('is_system', false)->count();

        return response()->json([
            'business_id' => $businessId,
            'summary' => [
                'total'    => $totalCount,
                'active'   => $activeCount,
                'inactive' => $inactiveCount,
                'system'   => $systemCount,
                'custom'   => $customCount,
            ],
            'accounts' => $accounts,
        ]);
    }

    /**
     * Store a newly created Master Account for the business.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $isAuthorized = $user && ($user->isOwnerBisnis() || $user->isPlatformAdmin() || $user->isOwnerWebsite());

        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Akses ditolak: Hanya Owner Bisnis yang berhak menambahkan master akun pembukuan.'
            ], 403);
        }

        $businessId = $this->getBusinessId($request);

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('accounts', 'code')->where(function ($query) use ($businessId) {
                    return $query->where('business_id', $businessId);
                }),
            ],
            'name'           => 'required|string|max:120',
            'type'           => 'required|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE',
            'category'       => 'nullable|string|max:50',
            'normal_balance' => 'required|in:DEBIT,CREDIT',
            'description'    => 'nullable|string|max:2000',
            'is_active'      => 'nullable|boolean',
        ], [
            'code.unique' => 'Kode akun ' . ($request->code ?? '') . ' sudah digunakan pada bisnis Anda. Harap gunakan kode akun yang unik.',
            'code.required' => 'Nomor / Kode akun wajib diisi.',
            'name.required' => 'Nama akun wajib diisi.',
            'type.required' => 'Tipe akun (ASET/KEWAJIBAN/EKUITAS/PENDAPATAN/BIAYA) wajib dipilih.',
            'normal_balance.required' => 'Saldo normal akun (DEBIT/CREDIT) wajib dipilih.',
        ]);

        $code = trim($data['code']);
        $parentCode = null;
        $outletId = null;
        if (str_contains($code, '.')) {
            $parts = explode('.', $code, 2);
            $parentCode = $parts[0];
            $sIdx = (int)$parts[1];
            if ($sIdx > 0) {
                $outlets = \App\Models\Outlet::where('business_id', $businessId)->orderBy('id', 'asc')->get();
                if (isset($outlets[$sIdx - 1])) {
                    $outletId = $outlets[$sIdx - 1]->id;
                }
            }
        }

        $account = Account::create([
            'business_id'    => $businessId,
            'parent_code'    => $parentCode,
            'outlet_id'      => $outletId,
            'code'           => $code,
            'name'           => strtoupper(trim($data['name'])),
            'type'           => strtoupper(trim($data['type'])),
            'category'       => !empty($data['category']) ? strtoupper(trim($data['category'])) : null,
            'normal_balance' => strtoupper(trim($data['normal_balance'])),
            'description'    => !empty($data['description']) ? trim($data['description']) : null,
            'is_system'      => false,
            'is_active'      => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => "Master akun '{$account->code} - {$account->name}' berhasil ditambahkan ke bisnis Anda.",
            'account' => $account,
        ], 201);
    }

    /**
     * Show single account detail.
     */
    public function show(Request $request, int $id)
    {
        $businessId = $this->getBusinessId($request);
        $account = Account::where('id', $id)->firstOrFail();

        $user = $request->user();
        if (!$user->isPlatformAdmin() && (int)$account->business_id !== $businessId) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses untuk melihat akun dari bisnis lain.'], 403);
        }

        return response()->json($account);
    }

    /**
     * Update an existing Master Account.
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $isAuthorized = $user && ($user->isOwnerBisnis() || $user->isPlatformAdmin() || $user->isOwnerWebsite());

        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Akses ditolak: Hanya Owner Bisnis yang berhak mengubah master akun pembukuan.'
            ], 403);
        }

        $businessId = $this->getBusinessId($request);
        $account = Account::where('id', $id)->firstOrFail();

        if (!$user->isPlatformAdmin() && (int)$account->business_id !== $businessId) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses untuk mengubah akun dari bisnis lain.'], 403);
        }

        $data = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('accounts', 'code')
                    ->where(function ($query) use ($account) {
                        return $query->where('business_id', $account->business_id);
                    })
                    ->ignore($account->id),
            ],
            'name'           => 'sometimes|required|string|max:120',
            'type'           => 'sometimes|required|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE',
            'category'       => 'nullable|string|max:50',
            'normal_balance' => 'sometimes|required|in:DEBIT,CREDIT',
            'description'    => 'nullable|string|max:2000',
            'is_active'      => 'nullable|boolean',
        ], [
            'code.unique' => 'Kode akun ' . ($request->code ?? '') . ' sudah digunakan pada akun lain.',
        ]);

        // If system account and code is modified, prevent changing system core code to maintain automation integrity
        $newCode = isset($data['code']) ? trim($data['code']) : $account->code;
        if ($account->is_system && $account->code !== $newCode) {
            return response()->json([
                'message' => "Kode akun bawaan sistem ({$account->code}) tidak dapat diubah agar tidak mengganggu posting jurnal otomatis. Anda dapat mengubah nama, kategori, atau deskripsinya.",
            ], 422);
        }

        $parentCode = $account->parent_code;
        $outletId = $account->outlet_id;
        if (isset($data['code']) && str_contains($newCode, '.')) {
            $parts = explode('.', $newCode, 2);
            $parentCode = $parts[0];
            $sIdx = (int)$parts[1];
            if ($sIdx > 0) {
                $outlets = \App\Models\Outlet::where('business_id', $businessId)->orderBy('id', 'asc')->get();
                if (isset($outlets[$sIdx - 1])) {
                    $outletId = $outlets[$sIdx - 1]->id;
                }
            }
        }

        $account->update([
            'parent_code'    => $parentCode,
            'outlet_id'      => $outletId,
            'code'           => $newCode,
            'name'           => isset($data['name']) ? strtoupper(trim($data['name'])) : $account->name,
            'type'           => isset($data['type']) ? strtoupper(trim($data['type'])) : $account->type,
            'category'       => array_key_exists('category', $data) ? (!empty($data['category']) ? strtoupper(trim($data['category'])) : null) : $account->category,
            'normal_balance' => isset($data['normal_balance']) ? strtoupper(trim($data['normal_balance'])) : $account->normal_balance,
            'description'    => array_key_exists('description', $data) ? (!empty($data['description']) ? trim($data['description']) : null) : $account->description,
            'is_active'      => $request->has('is_active') ? $request->boolean('is_active') : $account->is_active,
        ]);

        return response()->json([
            'message' => "Master akun '{$account->code} - {$account->name}' berhasil diperbarui.",
            'account' => $account,
        ]);
    }

    /**
     * Toggle Active / Inactive status of a Master Account.
     */
    public function toggleActive(Request $request, int $id)
    {
        $user = $request->user();
        $isAuthorized = $user && ($user->isOwnerBisnis() || $user->isPlatformAdmin() || $user->isOwnerWebsite());

        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Akses ditolak: Hanya Owner Bisnis yang berhak mengaktifkan/menonaktifkan master akun.'
            ], 403);
        }

        $businessId = $this->getBusinessId($request);
        $account = Account::where('id', $id)->firstOrFail();

        if (!$user->isPlatformAdmin() && (int)$account->business_id !== $businessId) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses untuk mengelola akun bisnis lain.'], 403);
        }

        $account->is_active = !$account->is_active;
        $account->save();

        $statusLabel = $account->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'message'   => "Akun '{$account->code} - {$account->name}' berhasil {$statusLabel}.",
            'is_active' => $account->is_active,
            'account'   => $account,
        ]);
    }

    /**
     * Delete a custom Master Account (if no transactions exist).
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $isAuthorized = $user && ($user->isOwnerBisnis() || $user->isPlatformAdmin() || $user->isOwnerWebsite());

        if (!$isAuthorized) {
            return response()->json([
                'message' => 'Akses ditolak: Hanya Owner Bisnis yang berhak menghapus master akun.'
            ], 403);
        }

        $businessId = $this->getBusinessId($request);
        $account = Account::where('id', $id)->firstOrFail();

        if (!$user->isPlatformAdmin() && (int)$account->business_id !== $businessId) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses untuk menghapus akun bisnis lain.'], 403);
        }

        if ($account->is_system) {
            return response()->json([
                'message' => "Akun bawaan sistem ({$account->code}) tidak dapat dihapus. Anda dapat menonaktifkannya agar tidak muncul dalam daftar pilihan transaksi.",
            ], 422);
        }

        // Check if journal lines exist for this account in this business
        $hasTransactions = JournalLine::where('account_code', $account->code)
            ->whereHas('journalEntry', fn($q) => $q->where('business_id', $account->business_id))
            ->exists();

        if ($hasTransactions) {
            return response()->json([
                'message' => "Akun '{$account->code}' sudah memiliki riwayat transaksi/mutasi jurnal pembukuan sehingga tidak dapat dihapus permanen. Silakan nonaktifkan akun ini.",
            ], 422);
        }

        $code = $account->code;
        $name = $account->name;
        $account->delete();

        return response()->json([
            'message' => "Master akun '{$code} - {$name}' berhasil dihapus.",
        ]);
    }
}
