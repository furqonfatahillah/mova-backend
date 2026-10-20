<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Services\AuthorityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorityController extends Controller
{
    /**
     * Get permission definitions and preset templates
     */
    public function definitions(Request $request)
    {
        return response()->json([
            'modules'     => array_values(AuthorityService::MODULES),
            'presets'     => array_values(AuthorityService::getPresets()),
            'total_perms' => count(AuthorityService::getAllPermissionKeys()),
        ]);
    }

    /**
     * Get list of users in the business unit with authority statuses
     */
    public function users(Request $request)
    {
        $currentUser = $request->user();

        if ($currentUser->isPegawai()) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses pengaturan otoritas.'], 403);
        }

        $query = User::with(['outlet', 'roleMaster'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('name', 'asc');

        if ($currentUser->isOwnerBisnis() && !$currentUser->isPlatformAdmin()) {
            // Owner Bisnis: can view all users in their business unit
            $query->where('business_id', $currentUser->business_id)
                  ->whereNotIn('role', ['superadmin_platform', 'owner_website', 'superadmin']);

            if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
                $query->where('outlet_id', $request->outlet_id);
            }
        } elseif ($currentUser->isOwnerOutlet()) {
            // Owner / Manager Outlet: view staff in their outlet
            $query->where('business_id', $currentUser->business_id)
                  ->where('outlet_id', $currentUser->outlet_id)
                  ->whereIn('role', ['pegawai', 'kasir', 'manager']);
        } else {
            // Platform Admin: filter by business if specified
            $bId = $request->header('X-Business-Id') ?? $request->business_id;
            if ($bId) {
                $query->where('business_id', (int)$bId);
            }
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                  ->orWhere('email', 'like', $s);
            });
        }

        $users = $query->get()->map(function ($u) {
            $allKeys = AuthorityService::getAllPermissionKeys();
            $effective = $u->effective_permissions;
            return [
                'id'                     => $u->id,
                'name'                   => $u->name,
                'email'                  => $u->email,
                'role'                   => $u->role,
                'role_label'             => $u->role_label,
                'outlet_id'              => $u->outlet_id,
                'outlet_name'            => $u->outlet_name,
                'business_id'            => $u->business_id,
                'status'                 => $u->status,
                'is_custom_permissions'  => $u->is_custom_permissions,
                'permissions'            => $u->permissions,
                'effective_permissions'  => $effective,
                'permission_count'       => count($effective),
                'total_possible_perms'   => count($allKeys),
                'coverage_pct'           => count($allKeys) > 0 ? round((count($effective) / count($allKeys)) * 100) : 0,
            ];
        });

        return response()->json([
            'users' => $users,
            'summary' => [
                'total_users'  => $users->count(),
                'customized'   => $users->where('is_custom_permissions', true)->count(),
                'default_role' => $users->where('is_custom_permissions', false)->count(),
            ],
        ]);
    }

    /**
     * Get authority details for a specific user
     */
    public function getUserAuthority(Request $request, User $user)
    {
        $currentUser = $request->user();

        if (!$currentUser->isPlatformAdmin() && (int)$currentUser->business_id !== (int)$user->business_id) {
            return response()->json(['message' => 'User tidak berada dalam unit bisnis Anda.'], 403);
        }

        $user->load(['outlet', 'roleMaster']);
        $allKeys = AuthorityService::getAllPermissionKeys();
        $effective = $user->effective_permissions;

        return response()->json([
            'user' => [
                'id'                    => $user->id,
                'name'                  => $user->name,
                'email'                 => $user->email,
                'role'                  => $user->role,
                'role_label'            => $user->role_label,
                'outlet_id'             => $user->outlet_id,
                'outlet_name'           => $user->outlet_name,
                'business_id'           => $user->business_id,
                'status'                => $user->status,
                'is_custom_permissions' => $user->is_custom_permissions,
                'permissions'           => $user->permissions,
                'effective_permissions' => $effective,
                'permission_count'      => count($effective),
                'total_possible_perms'  => count($allKeys),
            ],
            'modules' => array_values(AuthorityService::MODULES),
            'presets' => array_values(AuthorityService::getPresets()),
        ]);
    }

    /**
     * Update permissions for a specific user
     */
    public function updateUserAuthority(Request $request, User $user)
    {
        $currentUser = $request->user();

        if ($currentUser->isPegawai()) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses mengubah otoritas.'], 403);
        }

        if (!$currentUser->isPlatformAdmin()) {
            if ((int)$currentUser->business_id !== (int)$user->business_id) {
                return response()->json(['message' => 'User tidak berada dalam unit bisnis Anda.'], 403);
            }

            if ($user->isPlatformAdmin()) {
                return response()->json(['message' => 'Tidak dapat mengubah otoritas akun platform admin.'], 403);
            }

            if ($user->id === $currentUser->id && !$currentUser->isOwnerBisnis()) {
                return response()->json(['message' => 'Anda tidak dapat mengubah otoritas akun Anda sendiri.'], 403);
            }
        }

        $request->validate([
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        $validKeys = AuthorityService::getAllPermissionKeys();

        if ($request->has('permissions') && is_array($request->permissions)) {
            // Filter only valid known permission keys
            $cleanPerms = array_values(array_intersect($request->permissions, $validKeys));
            $user->permissions = $cleanPerms;
        } else {
            // Null resets to default role template
            $user->permissions = null;
        }

        $user->save();
        $user->load(['outlet', 'roleMaster']);

        return response()->json([
            'message'               => 'Otoritas akses akun berhasil diperbarui.',
            'user_id'               => $user->id,
            'is_custom_permissions' => $user->is_custom_permissions,
            'permissions'           => $user->permissions,
            'effective_permissions' => $user->effective_permissions,
        ]);
    }

    /**
     * Apply a preset template to one or multiple users
     */
    public function applyPreset(Request $request)
    {
        $currentUser = $request->user();

        if ($currentUser->isPegawai() || (!$currentUser->isOwnerBisnis() && !$currentUser->isPlatformAdmin())) {
            return response()->json(['message' => 'Hanya Owner Bisnis yang dapat menerapkan template otoritas.'], 403);
        }

        $data = $request->validate([
            'user_ids'   => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
            'preset_id'  => 'required|string',
        ]);

        $presets = AuthorityService::getPresets();
        $presetId = $data['preset_id'];

        if ($presetId === 'reset_default') {
            $newPerms = null;
        } elseif (isset($presets[$presetId])) {
            $newPerms = $presets[$presetId]['permissions'];
        } else {
            return response()->json(['message' => "Preset '{$presetId}' tidak ditemukan."], 422);
        }

        $query = User::whereIn('id', $data['user_ids']);
        if (!$currentUser->isPlatformAdmin()) {
            $query->where('business_id', $currentUser->business_id)
                  ->whereNotIn('role', ['superadmin_platform', 'owner_website', 'superadmin', 'owner_bisnis']);
        }

        $targetUsers = $query->get();
        $updatedCount = 0;

        foreach ($targetUsers as $target) {
            $target->permissions = $newPerms;
            $target->save();
            $updatedCount++;
        }

        return response()->json([
            'message'       => "Template '{$presetId}' berhasil diterapkan ke {$updatedCount} akun.",
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Copy permissions from one user to other users
     */
    public function copyPermissions(Request $request)
    {
        $currentUser = $request->user();

        if ($currentUser->isPegawai() || (!$currentUser->isOwnerBisnis() && !$currentUser->isPlatformAdmin())) {
            return response()->json(['message' => 'Hanya Owner Bisnis yang dapat menyalin otoritas akun.'], 403);
        }

        $data = $request->validate([
            'source_user_id' => 'required|integer|exists:users,id',
            'target_user_ids' => 'required|array|min:1',
            'target_user_ids.*' => 'integer|exists:users,id',
        ]);

        $sourceUser = User::findOrFail($data['source_user_id']);

        if (!$currentUser->isPlatformAdmin() && (int)$sourceUser->business_id !== (int)$currentUser->business_id) {
            return response()->json(['message' => 'Akun sumber tidak berada dalam unit bisnis Anda.'], 403);
        }

        $sourcePerms = $sourceUser->permissions ?? $sourceUser->effective_permissions;

        $query = User::whereIn('id', $data['target_user_ids']);
        if (!$currentUser->isPlatformAdmin()) {
            $query->where('business_id', $currentUser->business_id)
                  ->whereNotIn('role', ['superadmin_platform', 'owner_website', 'superadmin', 'owner_bisnis']);
        }

        $targetUsers = $query->get();
        $updatedCount = 0;

        foreach ($targetUsers as $target) {
            $target->permissions = $sourcePerms;
            $target->save();
            $updatedCount++;
        }

        return response()->json([
            'message'       => "Otoritas dari {$sourceUser->name} berhasil disalin ke {$updatedCount} akun tujuan.",
            'updated_count' => $updatedCount,
        ]);
    }
}
