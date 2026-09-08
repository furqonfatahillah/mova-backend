<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use Illuminate\Http\Request;

class OutletController extends Controller
{
    public function publicList(Request $request)
    {
        $query = Outlet::where('active', true);
        if ($request->filled('business_id')) {
            $query->where('business_id', (int)$request->business_id);
        }

        return response()->json(
            $query->orderByDesc('is_main')
                ->orderBy('name')
                ->select('id', 'business_id', 'code', 'name', 'is_main')
                ->get()
        );
    }

    public function index()
    {
        return response()->json(
            Outlet::with(['creator', 'updater'])
                ->orderByDesc('is_main')
                ->orderBy('code')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if ($user && $user->business && !$user->business->canAddOutlet()) {
            return response()->json([
                'message' => "Batas kuota cabang untuk paket {$user->business->package_type} telah tercapai (maksimal {$user->business->max_outlets} cabang). Silakan hubungi pengelola platform untuk menambah kuota cabang."
            ], 422);
        }

        $data = $request->validate([
            'code'     => 'required|string|max:30',
            'name'     => 'required|string|max:255',
            'address'  => 'nullable|string|max:500',
            'phone'    => 'nullable|string|max:50',
            'pic_name' => 'nullable|string|max:100',
            'is_main'  => 'nullable|boolean',
            'active'   => 'nullable|boolean',
        ]);

        if (!empty($data['is_main']) && $data['is_main']) {
            Outlet::where('is_main', true)->update(['is_main' => false]);
        }

        $data['created_by'] = $user?->id;

        $outlet = Outlet::create($data);
        $outlet->load(['creator', 'updater']);
        return response()->json($outlet, 201);
    }

    public function show(Outlet $outlet)
    {
        $outlet->load(['creator', 'updater', 'transfersOut', 'transfersIn']);
        return response()->json($outlet);
    }

    public function update(Request $request, Outlet $outlet)
    {
        $data = $request->validate([
            'code'     => 'sometimes|string|max:30|unique:outlets,code,' . $outlet->id,
            'name'     => 'sometimes|string|max:255',
            'address'  => 'nullable|string|max:500',
            'phone'    => 'nullable|string|max:50',
            'pic_name' => 'nullable|string|max:100',
            'is_main'  => 'nullable|boolean',
            'active'   => 'nullable|boolean',
        ]);

        if (isset($data['is_main']) && $data['is_main']) {
            Outlet::where('id', '!=', $outlet->id)->where('is_main', true)->update(['is_main' => false]);
        }

        $data['updated_by'] = $request->user()?->id;

        $outlet->update($data);
        $outlet->load(['creator', 'updater']);
        return response()->json($outlet);
    }

    public function destroy(Outlet $outlet)
    {
        if ($outlet->is_main) {
            return response()->json(['message' => 'Outlet utama (pusat) tidak dapat dihapus.'], 422);
        }

        if ($outlet->transfersOut()->exists() || $outlet->transfersIn()->exists()) {
            // Soft deactivate instead of hard delete to maintain foreign key integrity
            $outlet->update(['active' => false]);
            return response()->json(['message' => 'Outlet telah dinonaktifkan karena memiliki riwayat transfer bahan.']);
        }

        $outlet->delete();
        return response()->json(['message' => 'Outlet berhasil dihapus.']);
    }
}
