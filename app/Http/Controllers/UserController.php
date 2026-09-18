<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        if ($currentUser->isPegawai()) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses manajemen pengguna.'], 403);
        }

        $query = User::with(['approver', 'outlet'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        // Scoping for Owner Outlet: can ONLY view and manage employees in their own outlet
        if ($currentUser->isOwnerOutlet()) {
            $outletId = $currentUser->outlet_id;
            $query->where('business_id', $currentUser->business_id)
                  ->where('outlet_id', $outletId)
                  ->whereIn('role', ['pegawai', 'kasir', 'manager']);

            $baseCount = User::where('business_id', $currentUser->business_id)
                ->where('outlet_id', $outletId)
                ->whereIn('role', ['pegawai', 'kasir', 'manager']);
            $counts = [
                'total'     => (clone $baseCount)->count(),
                'pending'   => (clone $baseCount)->where('status', 'pending')->count(),
                'active'    => (clone $baseCount)->where('status', 'active')->count(),
                'rejected'  => (clone $baseCount)->where('status', 'rejected')->count(),
                'suspended' => (clone $baseCount)->where('status', 'suspended')->count(),
            ];
        } elseif ($currentUser->isOwnerBisnis() && !$currentUser->isPlatformAdmin()) {
            // Owner Bisnis: can view branch owners and staff in their own business (excludes owner_bisnis, owner_website, superadmin_platform)
            $excludedRoles = ['owner_bisnis', 'owner_website', 'superadmin_platform', 'superadmin', 'owner', 'admin'];
            $query->where('business_id', $currentUser->business_id)
                  ->whereNotIn('role', $excludedRoles);
            if ($request->outlet_id) {
                $query->where('outlet_id', $request->outlet_id);
            }
            $baseCount = User::where('business_id', $currentUser->business_id)
                ->whereNotIn('role', $excludedRoles);
            $counts = [
                'total'     => (clone $baseCount)->count(),
                'pending'   => (clone $baseCount)->where('status', 'pending')->count(),
                'active'    => (clone $baseCount)->where('status', 'active')->count(),
                'rejected'  => (clone $baseCount)->where('status', 'rejected')->count(),
                'suspended' => (clone $baseCount)->where('status', 'suspended')->count(),
            ];
        } else {
            // Platform Admin (Superadmin Platform / Owner Website): full visibility or filtered by business
            $bId = $request->header('X-Business-Id') ?? $request->business_id;
            if ($bId) {
                $query->where('business_id', (int)$bId);
                $baseCount = User::where('business_id', (int)$bId);
            } else {
                $baseCount = User::query();
            }
            $counts = [
                'total'     => (clone $baseCount)->count(),
                'pending'   => (clone $baseCount)->where('status', 'pending')->count(),
                'active'    => (clone $baseCount)->where('status', 'active')->count(),
                'rejected'  => (clone $baseCount)->where('status', 'rejected')->count(),
                'suspended' => (clone $baseCount)->where('status', 'suspended')->count(),
            ];
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->role) {
            $query->where('role', $request->role);
        }
        if ($request->search) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                  ->orWhere('email', 'like', $s);
            });
        }

        $users = $query->get();

        return response()->json([
            'users'        => $users,
            'counts'       => $counts,
            'current_user' => [
                'id'                     => $currentUser->id,
                'role'                   => $currentUser->role,
                'outlet_id'              => $currentUser->outlet_id,
                'is_superadmin_platform' => $currentUser->isSuperadminPlatform(),
                'is_owner_website'       => $currentUser->isOwnerWebsite(),
                'is_platform_admin'      => $currentUser->isPlatformAdmin(),
                'is_owner_bisnis'        => $currentUser->isOwnerBisnis(),
                'is_owner_outlet'        => $currentUser->isOwnerOutlet(),
                'is_pegawai'             => $currentUser->isPegawai(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        if ($currentUser->isPegawai()) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses menambah pengguna.'], 403);
        }

        $allowedRoles = $currentUser->isPlatformAdmin()
            ? 'in:owner_bisnis,owner_website,superadmin_platform,owner_outlet,pegawai,kasir,manager,admin,owner'
            : ($currentUser->isOwnerBisnis()
                ? 'in:owner_outlet,pegawai,kasir,manager'
                : 'in:pegawai,kasir,manager');

        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users',
            'password'  => 'required|string|min:6',
            'role'      => 'required|' . $allowedRoles,
            'outlet_id' => 'nullable|exists:outlets,id',
            'status'    => 'nullable|in:pending,active,rejected,suspended',
        ]);

        // If Owner Outlet, force their own outlet_id
        if ($currentUser->isOwnerOutlet()) {
            $data['outlet_id'] = $currentUser->outlet_id;
            if ($data['role'] === 'kasir' || $data['role'] === 'manager') {
                $data['role'] = 'pegawai';
            }
        }

        $status = $data['status'] ?? 'active';

        $user = User::create([
            'business_id' => $currentUser->business_id ?? $request->business_id ?? null,
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => Hash::make($data['password']),
            'role'        => $data['role'],
            'outlet_id'   => $data['outlet_id'] ?? null,
            'status'      => $status,
            'approved_by' => $status === 'active' ? $currentUser->id : null,
            'approved_at' => $status === 'active' ? now() : null,
        ]);

        $user->load(['approver', 'outlet']);
        return response()->json($user, 201);
    }

    public function show(Request $request, User $user)
    {
        $currentUser = $request->user();
        if (!$currentUser->canManage($user)) {
            return response()->json(['message' => 'Anda tidak memiliki wewenang mengakses data pengguna ini.'], 403);
        }

        $user->load(['approver', 'outlet']);
        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        $currentUser = $request->user();
        if (!$currentUser->canManage($user)) {
            return response()->json(['message' => 'Anda tidak memiliki wewenang mengedit data pengguna ini.'], 403);
        }

        $allowedRoles = $currentUser->isPlatformAdmin()
            ? 'in:owner_bisnis,owner_website,superadmin_platform,owner_outlet,pegawai,kasir,manager,admin,owner'
            : ($currentUser->isOwnerBisnis()
                ? 'in:owner_outlet,pegawai,kasir,manager'
                : 'in:pegawai,kasir,manager');

        $data = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'email'     => 'sometimes|email|unique:users,email,' . $user->id,
            'password'  => 'nullable|string|min:6',
            'role'      => 'sometimes|' . $allowedRoles,
            'outlet_id' => 'nullable|exists:outlets,id',
            'status'    => 'sometimes|in:pending,active,rejected,suspended',
        ]);

        if ($currentUser->isOwnerOutlet()) {
            unset($data['outlet_id']); // Cannot change outlet
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if (isset($data['status']) && $data['status'] === 'active' && $user->status !== 'active') {
            $data['approved_by'] = $currentUser->id;
            $data['approved_at'] = now();
        }

        $user->update($data);
        $user->load(['approver', 'outlet']);
        return response()->json($user);
    }

    public function approve(Request $request, User $user)
    {
        $currentUser = $request->user();
        if (!$currentUser->canManage($user)) {
            return response()->json(['message' => 'Anda tidak berwenang menyetujui akun ini.'], 403);
        }

        $allowedRoles = $currentUser->isPlatformAdmin()
            ? 'in:owner_bisnis,owner_website,superadmin_platform,owner_outlet,pegawai,kasir,manager,admin,owner'
            : ($currentUser->isOwnerBisnis()
                ? 'in:owner_outlet,pegawai,kasir,manager'
                : 'in:pegawai,kasir,manager');

        $data = $request->validate([
            'role'      => 'nullable|' . $allowedRoles,
            'outlet_id' => 'nullable|exists:outlets,id',
        ]);

        $outletId = $currentUser->isOwnerOutlet() ? $currentUser->outlet_id : ($data['outlet_id'] ?? $user->outlet_id);
        $role = $currentUser->isOwnerOutlet() ? 'pegawai' : ($data['role'] ?? $user->role);

        $user->update([
            'status'      => 'active',
            'approved_by' => $currentUser->id,
            'approved_at' => now(),
            'role'        => $role,
            'outlet_id'   => $outletId,
        ]);

        $user->load(['approver', 'outlet']);
        return response()->json([
            'message' => "Akun {$user->name} berhasil disetujui dan diaktifkan.",
            'user'    => $user,
        ]);
    }

    public function reject(Request $request, User $user)
    {
        $currentUser = $request->user();
        if (!$currentUser->canManage($user)) {
            return response()->json(['message' => 'Anda tidak berwenang menolak akun ini.'], 403);
        }

        $user->update([
            'status'      => 'rejected',
            'approved_by' => $currentUser->id,
            'approved_at' => now(),
        ]);

        $user->tokens()->delete();
        $user->load(['approver', 'outlet']);
        return response()->json([
            'message' => "Permohonan akun {$user->name} telah ditolak.",
            'user'    => $user,
        ]);
    }

    public function suspend(Request $request, User $user)
    {
        $currentUser = $request->user();
        if (!$currentUser->canManage($user)) {
            return response()->json(['message' => 'Anda tidak berwenang menonaktifkan akun ini.'], 403);
        }

        if ($user->id === $currentUser->id) {
            return response()->json(['message' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.'], 422);
        }

        $user->update(['status' => 'suspended']);
        $user->tokens()->delete();
        $user->load(['approver', 'outlet']);
        return response()->json([
            'message' => "Akun {$user->name} telah dinonaktifkan (suspended).",
            'user'    => $user,
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();
        if (!$currentUser->canManage($user)) {
            return response()->json(['message' => 'Anda tidak berwenang menghapus akun ini.'], 403);
        }

        if ($user->id === $currentUser->id) {
            return response()->json(['message' => 'Anda tidak dapat menghapus akun Anda sendiri.'], 422);
        }

        if ($user->isOwnerWebsite() || $user->isSuperadminPlatform() || $user->isOwnerBisnis()) {
            return response()->json(['message' => 'Akun Owner utama tidak dapat dihapus.'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Pengguna berhasil dihapus.']);
    }
}
