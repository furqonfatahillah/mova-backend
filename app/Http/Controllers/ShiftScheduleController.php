<?php

namespace App\Http\Controllers;

use App\Models\ShiftSchedule;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;

class ShiftScheduleController extends Controller
{
    /**
     * Helper to resolve the active business ID.
     */
    protected function getBusinessId(Request $request): int
    {
        $user = $request->user();
        if ($user->isSuperadminPlatform()) {
            return (int) ($request->header('X-Business-Id') ?? $request->business_id ?? $user->business_id ?? 1);
        }
        return (int) $user->business_id;
    }

    /**
     * Check if user is owner of the business or platform superadmin.
     */
    protected function isOwner(Request $request): bool
    {
        $user = $request->user();
        return $user->isOwnerBisnis() || $user->isSuperadminPlatform();
    }

    /**
     * Display a listing of shift schedules for the active business/outlet.
     */
    public function index(Request $request)
    {
        $businessId = $this->getBusinessId($request);

        $query = ShiftSchedule::where('business_id', $businessId)
            ->with(['outlet:id,name,code', 'creator:id,name', 'updater:id,name'])
            ->orderBy('outlet_id')
            ->orderBy('start_time')
            ->orderBy('id');

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', (int) $request->outlet_id);
        }

        if ($request->has('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        $schedules = $query->get();

        return response()->json($schedules);
    }

    /**
     * List all eligible employees within the same company for scheduling.
     */
    public function employees(Request $request)
    {
        $businessId = $this->getBusinessId($request);

        // Strictly scoped to the company
        $query = User::where('business_id', $businessId)
            ->where('status', 'active')
            ->select('id', 'name', 'email', 'role', 'outlet_id')
            ->with('outlet:id,name,code')
            ->orderBy('name');

        if ($request->filled('outlet_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('outlet_id', (int) $request->outlet_id)
                  ->orWhereNull('outlet_id');
            });
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created shift schedule (OWNER ONLY, SINGLE COMPANY STRICT).
     */
    public function store(Request $request)
    {
        if (!$this->isOwner($request)) {
            return response()->json([
                'message' => 'Akses Ditolak: Hanya Owner bisnis yang memiliki izin untuk mengatur master jadwal shift.'
            ], 403);
        }

        $businessId = $this->getBusinessId($request);

        $data = $request->validate([
            'outlet_id'         => 'required|integer',
            'shift_name'        => 'required|string|max:100',
            'start_time'        => 'required|string',
            'end_time'          => 'required|string',
            'assigned_user_ids' => 'nullable|array',
            'assigned_user_ids.*' => 'integer',
            'is_strict'         => 'boolean',
            'active'            => 'boolean',
        ]);

        // Verify that outlet belongs to the same business
        $outlet = Outlet::where('id', $data['outlet_id'])
            ->where('business_id', $businessId)
            ->first();

        if (!$outlet) {
            return response()->json([
                'message' => 'Outlet tidak ditemukan atau bukan milik perusahaan Anda.'
            ], 422);
        }

        // Strictly verify that all assigned users belong to this single business
        $assignedIds = array_values(array_unique(array_filter($data['assigned_user_ids'] ?? [])));
        if (!empty($assignedIds)) {
            $userCount = User::whereIn('id', $assignedIds)
                ->where('business_id', $businessId)
                ->count();

            if ($userCount !== count($assignedIds)) {
                return response()->json([
                    'message' => 'Validasi gagal: Terdapat staf/kasir yang tidak terdaftar di perusahaan Anda.'
                ], 422);
            }
        }

        $schedule = ShiftSchedule::create([
            'business_id'       => $businessId,
            'outlet_id'         => $outlet->id,
            'shift_name'        => $data['shift_name'],
            'start_time'        => $data['start_time'],
            'end_time'          => $data['end_time'],
            'assigned_user_ids' => $assignedIds,
            'is_strict'         => $data['is_strict'] ?? false,
            'active'            => $data['active'] ?? true,
            'created_by'        => $request->user()->id,
        ]);

        $schedule->load(['outlet:id,name,code', 'creator:id,name']);

        return response()->json($schedule, 201);
    }

    /**
     * Display the specified shift schedule.
     */
    public function show(Request $request, $id)
    {
        $businessId = $this->getBusinessId($request);

        $schedule = ShiftSchedule::where('business_id', $businessId)
            ->with(['outlet:id,name,code', 'creator:id,name', 'updater:id,name'])
            ->findOrFail($id);

        return response()->json($schedule);
    }

    /**
     * Update the specified shift schedule (OWNER ONLY, SINGLE COMPANY STRICT).
     */
    public function update(Request $request, $id)
    {
        if (!$this->isOwner($request)) {
            return response()->json([
                'message' => 'Akses Ditolak: Hanya Owner bisnis yang memiliki izin untuk mengubah master jadwal shift.'
            ], 403);
        }

        $businessId = $this->getBusinessId($request);

        $schedule = ShiftSchedule::where('business_id', $businessId)->findOrFail($id);

        $data = $request->validate([
            'outlet_id'         => 'sometimes|required|integer',
            'shift_name'        => 'sometimes|required|string|max:100',
            'start_time'        => 'sometimes|required|string',
            'end_time'          => 'sometimes|required|string',
            'assigned_user_ids' => 'nullable|array',
            'assigned_user_ids.*' => 'integer',
            'is_strict'         => 'boolean',
            'active'            => 'boolean',
        ]);

        if (isset($data['outlet_id'])) {
            $outlet = Outlet::where('id', $data['outlet_id'])
                ->where('business_id', $businessId)
                ->first();

            if (!$outlet) {
                return response()->json([
                    'message' => 'Outlet tidak ditemukan atau bukan milik perusahaan Anda.'
                ], 422);
            }
            $schedule->outlet_id = $outlet->id;
        }

        if (isset($data['assigned_user_ids'])) {
            $assignedIds = array_values(array_unique(array_filter($data['assigned_user_ids'])));
            if (!empty($assignedIds)) {
                $userCount = User::whereIn('id', $assignedIds)
                    ->where('business_id', $businessId)
                    ->count();

                if ($userCount !== count($assignedIds)) {
                    return response()->json([
                        'message' => 'Validasi gagal: Terdapat staf/kasir yang tidak terdaftar di perusahaan Anda.'
                    ], 422);
                }
            }
            $schedule->assigned_user_ids = $assignedIds;
        }

        if (isset($data['shift_name'])) $schedule->shift_name = $data['shift_name'];
        if (isset($data['start_time'])) $schedule->start_time = $data['start_time'];
        if (isset($data['end_time'])) $schedule->end_time = $data['end_time'];
        if (isset($data['is_strict'])) $schedule->is_strict = $data['is_strict'];
        if (isset($data['active'])) $schedule->active = $data['active'];

        $schedule->updated_by = $request->user()->id;
        $schedule->save();

        $schedule->load(['outlet:id,name,code', 'creator:id,name', 'updater:id,name']);

        return response()->json($schedule);
    }

    /**
     * Remove the specified shift schedule (OWNER ONLY).
     */
    public function destroy(Request $request, $id)
    {
        if (!$this->isOwner($request)) {
            return response()->json([
                'message' => 'Akses Ditolak: Hanya Owner bisnis yang memiliki izin untuk menghapus master jadwal shift.'
            ], 403);
        }

        $businessId = $this->getBusinessId($request);

        $schedule = ShiftSchedule::where('business_id', $businessId)->findOrFail($id);

        // Check if there is an active shift currently opened using this schedule
        $activeShiftCount = $schedule->shifts()->where('status', 'OPEN')->count();
        if ($activeShiftCount > 0) {
            return response()->json([
                'message' => 'Tidak dapat menghapus master shift ini karena sedang ada shift operasional yang aktif.'
            ], 422);
        }

        $schedule->delete();

        return response()->json([
            'message' => "Jadwal shift '{$schedule->shift_name}' berhasil dihapus."
        ]);
    }
}
