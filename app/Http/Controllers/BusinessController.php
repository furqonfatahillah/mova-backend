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
        if (!$user->isSuperadminPlatform()) {
            return response()->json(['message' => 'Unauthorized. Hanya Superadmin Platform yang dapat mengakses seluruh penyewa.'], 403);
        }

        $query = Business::query()->withCount(['outlets', 'users', 'menus', 'ingredients']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('owner_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderByDesc('id')->get());
    }

    /**
     * Superadmin Platform: Create a new tenant business
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->isSuperadminPlatform()) {
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
        if (!$user->isSuperadminPlatform() && (int)$user->business_id !== (int)$business->id) {
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
        if (!$user->isSuperadminPlatform()) {
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
}
