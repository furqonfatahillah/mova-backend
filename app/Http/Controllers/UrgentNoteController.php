<?php

namespace App\Http\Controllers;

use App\Models\UrgentNote;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\OutletMenu;
use App\Models\StockMovement;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UrgentNoteController extends Controller
{
    /**
     * Display a paginated listing of urgent notes.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id ?: 1;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        // Auto-sync missing urgent notes from any unlinked urgent transactions
        $this->syncMissingUrgentNotesFromTransactions($businessId, $outletId);

        // Auto-resolve any notes that have zero deficit or whose ingredient is no longer negative/deficit
        $this->autoResolveFulfilledUrgentNotes($businessId, $outletId);

        $query = UrgentNote::with([
            'outlet',
            'transaction.user',
            'menu',
            'ingredient',
            'resolvedByUser',
            'approvalRequestedByUser',
            'approvedByUser',
            'rejectedByUser',
            'creator'
        ])
        ->where('business_id', $businessId)
        ->orderByDesc('id');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        if ($request->filled('status')) {
            if ($request->status === 'ACTIVE' || $request->status === 'DEFICIT') {
                $query->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                      ->where('pending_qty', '>', 0.0001);
            } elseif ($request->status === 'PENDING' || $request->status === 'APPROVAL_PENDING') {
                $query->where('status', $request->status)
                      ->where('pending_qty', '>', 0.0001);
            } elseif ($request->status !== 'ALL' && $request->status !== 'all') {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('from')) {
            $query->where(function ($q) use ($request) {
                $q->whereDate('created_at', '>=', $request->from)
                  ->orWhereHas('transaction', fn($tq) => $tq->where('date', '>=', $request->from));
            });
        }

        if ($request->filled('to')) {
            $query->where(function ($q) use ($request) {
                $q->whereDate('created_at', '<=', $request->to)
                  ->orWhereHas('transaction', fn($tq) => $tq->where('date', '<=', $request->to));
            });
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                  ->orWhere('item_name', 'like', "%{$s}%")
                  ->orWhere('notes', 'like', "%{$s}%")
                  ->orWhere('requested_notes', 'like', "%{$s}%")
                  ->orWhere('reject_reason', 'like', "%{$s}%")
                  ->orWhereHas('menu', fn($mq) => $mq->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('ingredient', fn($iq) => $iq->where('name', 'like', "%{$s}%"));
            });
        }

        $perPage = (int)($request->query('per_page', 250));
        $notes = $query->paginate($perPage);

        return response()->json($notes);
    }

    /**
     * Get summary metrics for urgent notes dashboard & POS quick indicator.
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id ?: 1;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        // Auto-resolve any notes that have zero deficit
        $this->autoResolveFulfilledUrgentNotes($businessId, $outletId);

        $baseQuery = UrgentNote::where('business_id', $businessId);
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $baseQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        if ($request->filled('from')) {
            $baseQuery->where(function ($q) use ($request) {
                $q->whereDate('created_at', '>=', $request->from)
                  ->orWhereHas('transaction', fn($tq) => $tq->where('date', '>=', $request->from));
            });
        }
        if ($request->filled('to')) {
            $baseQuery->where(function ($q) use ($request) {
                $q->whereDate('created_at', '<=', $request->to)
                  ->orWhereHas('transaction', fn($tq) => $tq->where('date', '<=', $request->to));
            });
        }

        $pendingCount = (clone $baseQuery)->where('status', 'PENDING')->where('pending_qty', '>', 0.0001)->count();
        $approvalPendingCount = (clone $baseQuery)->where('status', 'APPROVAL_PENDING')->where('pending_qty', '>', 0.0001)->count();
        $pendingTransactionsCount = (clone $baseQuery)->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])->where('pending_qty', '>', 0.0001)->distinct('order_number')->count('order_number');
        $resolvedCount = (clone $baseQuery)->where('status', 'RESOLVED')->count();
        $totalCount = (clone $baseQuery)->count();

        // High deficit ingredients (only those with positive deficit > 0)
        $pendingIngredients = (clone $baseQuery)
            ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
            ->where('pending_qty', '>', 0.0001)
            ->whereNotNull('ingredient_id')
            ->select('ingredient_id', DB::raw('SUM(pending_qty) as total_pending_qty'), DB::raw('COUNT(*) as note_count'))
            ->groupBy('ingredient_id')
            ->having('total_pending_qty', '>', 0.0001)
            ->with('ingredient')
            ->get()
            ->map(function ($row) use ($outletId) {
                $ing = $row->ingredient;
                $currentStock = 0;
                if ($ing) {
                    $currentStock = $outletId && $outletId !== 'ALL' && $outletId !== 'all'
                        ? $ing->stockForOutlet((int)$outletId)
                        : $ing->consolidatedStock();
                }
                return [
                    'ingredient_id'      => $row->ingredient_id,
                    'ingredient_name'    => $ing?->name ?? 'Bahan #' . $row->ingredient_id,
                    'unit'               => $ing?->unit_pakai ?? 'satuan',
                    'total_pending_qty'  => (float)$row->total_pending_qty,
                    'note_count'         => (int)$row->note_count,
                    'current_stock'      => (float)$currentStock,
                    'can_resolve_all'    => $currentStock >= (float)$row->total_pending_qty,
                ];
            })
            ->filter(fn($it) => $it['total_pending_qty'] > 0.0001)
            ->values();

        return response()->json([
            'pending_count'              => $pendingCount,
            'approval_pending_count'     => $approvalPendingCount,
            'pending_transactions_count' => $pendingTransactionsCount,
            'resolved_count'             => $resolvedCount,
            'total_count'                => $totalCount,
            'pending_ingredients'        => $pendingIngredients,
        ]);
    }

    /**
     * Staff/Cashier requests resolution of a pending urgent note for Manager/Owner approval.
     */
    public function requestResolution(Request $request, UrgentNote $urgentNote)
    {
        if (!in_array($urgentNote->status, ['PENDING', 'REJECTED'])) {
            return response()->json([
                'message' => 'Nota urgent ini sudah diselesaikan, sedang menunggu persetujuan, atau sudah dibatalkan.'
            ], 422);
        }

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$urgentNote->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk mengajukan nota urgent di cabang outlet lain.'
                ], 403);
            }
        }

        // If user is already an Owner or Manager, execute direct approval & resolution
        if ($user->isOwnerBisnis() || $user->isOwnerOutlet() || $user->isPlatformAdmin()) {
            return $this->executeApproveResolution($request, $urgentNote);
        }

        $notes = $request->input('notes', 'Permohonan pelunasan stok bahan tergantung diajukan oleh kasir/staf.');

        $urgentNote->update([
            'status'                => 'APPROVAL_PENDING',
            'approval_requested_by' => $user->id,
            'approval_requested_at' => now(),
            'requested_notes'       => $notes,
            'updated_by'            => $user->id,
        ]);

        return response()->json([
            'message'     => "Permohonan pelunasan nota urgent {$urgentNote->order_number} ({$urgentNote->item_name}) berhasil diajukan ke akun Manager/Owner untuk diverifikasi.",
            'urgent_note' => $urgentNote->fresh(['outlet', 'menu', 'ingredient', 'approvalRequestedByUser']),
        ]);
    }

    /**
     * Batch request resolution for multiple urgent notes or entire order.
     */
    public function batchRequestResolution(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id ?: 1;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }
        $orderNumber = $request->input('order_number');
        $noteIds = $request->input('note_ids');
        $notes = $request->input('notes', 'Permohonan pelunasan stok diajukan');

        $query = UrgentNote::where('business_id', $businessId)->whereIn('status', ['PENDING', 'REJECTED']);
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }
        if ($orderNumber) {
            $query->where('order_number', $orderNumber);
        }
        if (!empty($noteIds) && is_array($noteIds)) {
            $query->whereIn('id', $noteIds);
        }

        $items = $query->get();
        if ($items->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada nota urgent berstatus pending yang dapat diajukan.',
                'requested_count' => 0
            ], 422);
        }

        // If user is Owner or Manager, directly batch resolve & approve
        if ($user->isOwnerBisnis() || $user->isOwnerOutlet() || $user->isPlatformAdmin()) {
            return $this->batchApproveResolution($request);
        }

        $count = 0;
        foreach ($items as $item) {
            $item->update([
                'status'                => 'APPROVAL_PENDING',
                'approval_requested_by' => $user->id,
                'approval_requested_at' => now(),
                'requested_notes'       => $notes,
                'updated_by'            => $user->id,
            ]);
            $count++;
        }

        return response()->json([
            'message'         => "Berhasil mengajukan {$count} item bahan tergantung ke akun Manager/Owner untuk disetujui.",
            'requested_count' => $count,
        ]);
    }

    /**
     * Manager / Owner approves resolution of an urgent note and deducts stock.
     */
    public function approveResolution(Request $request, UrgentNote $urgentNote)
    {
        $user = $request->user();
        if (!$user->isOwnerBisnis() && !$user->isOwnerOutlet() && !$user->isPlatformAdmin()) {
            return response()->json([
                'message' => 'Hanya akun Manager Outlet atau Owner Bisnis yang memiliki wewenang untuk menyetujui pelunasan nota urgent.'
            ], 403);
        }

        if (!in_array($urgentNote->status, ['PENDING', 'APPROVAL_PENDING'])) {
            return response()->json([
                'message' => 'Nota urgent ini sudah diselesaikan atau dibatalkan sebelumnya.'
            ], 422);
        }

        return $this->executeApproveResolution($request, $urgentNote);
    }

    /**
     * Helper to execute stock deduction and mark urgent note as RESOLVED & APPROVED.
     */
    private function executeApproveResolution(Request $request, UrgentNote $urgentNote)
    {
        $user = $request->user();
        $outletId = $urgentNote->outlet_id ?: 1;
        $pendingQty = (float)$urgentNote->pending_qty;
        $notes = $request->input('notes') ?: ($urgentNote->requested_notes ?: 'Pelunasan Bahan Tergantung (Disetujui Manager/Owner)');

        $result = DB::transaction(function () use ($urgentNote, $outletId, $pendingQty, $notes, $user) {
            $movementId = null;

            // 1. Deduct Ingredient Stock via StockMovement only if not already deducted at order time
            if ($urgentNote->ingredient_id) {
                $ing = Ingredient::findOrFail($urgentNote->ingredient_id);
                $availableStock = $ing->stockForOutlet((int)$outletId);
                $costPerPakai = $ing->costPerPakaiForOutlet((int)$outletId);
                $totalCost = round($pendingQty * $costPerPakai, 2);

                // Cek apakah transaksi sudah memotong stok bahan ini sebelumnya di tabel stock_movements
                $alreadyDeducted = $urgentNote->transaction_id
                    ? (float)StockMovement::where('transaction_id', $urgentNote->transaction_id)
                        ->where('ingredient_id', $ing->id)
                        ->where('type', 'SALE_USAGE')
                        ->where('note', 'not like', '%Pelunasan Nota Urgent%')
                        ->sum('qty')
                    : 0.0;

                $neededDeduction = round(max(0, $urgentNote->required_qty - $alreadyDeducted), 4);

                // Hanya buat StockMovement baru jika pada saat order stoknya belum terpotong penuh
                if ($neededDeduction > 0.0001) {
                    $mov = StockMovement::create([
                        'business_id'    => $urgentNote->business_id,
                        'outlet_id'      => $outletId,
                        'ingredient_id'  => $ing->id,
                        'date'           => now()->toDateString(),
                        'type'           => 'SALE_USAGE',
                        'qty'            => $neededDeduction,
                        'unit_price'     => $costPerPakai * max((float)$ing->konversi, 1),
                        'total_price'    => round($neededDeduction * $costPerPakai, 2),
                        'cost_before'    => $costPerPakai,
                        'cost_after'     => $costPerPakai,
                        'note'           => "{$urgentNote->order_number} – Pelunasan Nota Urgent (Disetujui: {$user->name}): {$urgentNote->item_name} ({$neededDeduction} {$urgentNote->unit})",
                        'transaction_id' => $urgentNote->transaction_id,
                        'user_id'        => $user->id,
                        'created_by'     => $user->id,
                    ]);
                    $movementId = $mov->id;
                } else {
                    $movementId = StockMovement::where('transaction_id', $urgentNote->transaction_id)
                        ->where('ingredient_id', $ing->id)
                        ->value('id');
                }
            }

            // 2. Deduct DIRECT Product Stock only if not already deducted at order time
            if ($urgentNote->menu_id && $urgentNote->item_type === 'DIRECT') {
                $menu = Menu::find($urgentNote->menu_id);
                if ($urgentNote->deducted_qty < $urgentNote->required_qty && $menu && $menu->track_stock) {
                    $qtyToCut = max(0, $urgentNote->required_qty - $urgentNote->deducted_qty);
                    if ($qtyToCut > 0) {
                        $menu->decrement('stock', $qtyToCut);
                        if ($outletId) {
                            try {
                                $om = OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                                if ($om) {
                                    $om->decrement('stock', $qtyToCut);
                                }
                            } catch (\Throwable $e) {}
                        }
                    }
                }
            }

            // 3. Mark UrgentNote as RESOLVED with Approval Audit Trail
            $urgentNote->update([
                'status'                 => 'RESOLVED',
                'approved_by'            => $user->id,
                'approved_at'            => now(),
                'resolved_at'            => now(),
                'resolved_by'            => $user->id,
                'resolution_notes'       => $notes,
                'resolution_movement_id' => $movementId,
                'updated_by'             => $user->id,
            ]);

            // 4. If all urgent notes for this transaction are resolved, update transaction urgent_status to RESOLVED
            if ($urgentNote->transaction_id) {
                $hasPendingOthers = UrgentNote::where('transaction_id', $urgentNote->transaction_id)
                    ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                    ->exists();

                if (!$hasPendingOthers) {
                    Transaction::where('id', $urgentNote->transaction_id)->update([
                        'urgent_status' => 'RESOLVED',
                        'updated_by'    => $user->id,
                    ]);
                }
            }

            // 5. Post accounting journal entry for COGS / Inventory deduction of the resolved deficit
            try {
                \App\Services\AccountingService::postUrgentNoteResolution($urgentNote);
            } catch (\Throwable $e) {}

            return $urgentNote->fresh(['outlet', 'menu', 'ingredient', 'resolvedByUser', 'approvedByUser']);
        });

        return response()->json([
            'message'     => "Pelunasan bahan tergantung ({$pendingQty} {$urgentNote->unit}) berhasil disetujui dan stok telah dipotong.",
            'urgent_note' => $result,
        ]);
    }

    /**
     * Batch approve resolution of urgent notes by Manager/Owner.
     */
    public function batchApproveResolution(Request $request)
    {
        $user = $request->user();
        if (!$user->isOwnerBisnis() && !$user->isOwnerOutlet() && !$user->isPlatformAdmin()) {
            return response()->json([
                'message' => 'Hanya akun Manager Outlet atau Owner Bisnis yang memiliki hak akses untuk menyetujui pelunasan nota urgent.'
            ], 403);
        }

        $businessId = $user->business_id ?: 1;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }
        $ingredientId = $request->input('ingredient_id');
        $orderNumber = $request->input('order_number');
        $noteIds = $request->input('note_ids');

        $query = UrgentNote::where('business_id', $businessId)->whereIn('status', ['PENDING', 'APPROVAL_PENDING']);
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }
        if ($ingredientId) {
            $query->where('ingredient_id', $ingredientId);
        }
        if ($orderNumber) {
            $query->where('order_number', $orderNumber);
        }
        if (!empty($noteIds) && is_array($noteIds)) {
            $query->whereIn('id', $noteIds);
        }

        $notes = $query->get();
        if ($notes->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada nota urgent yang perlu disetujui.',
                'resolved_count' => 0
            ], 422);
        }

        $resolvedCount = 0;
        foreach ($notes as $n) {
            try {
                $this->executeApproveResolution($request, $n);
                $resolvedCount++;
            } catch (\Throwable $e) {}
        }

        return response()->json([
            'message'        => "Berhasil menyetujui dan melunasi {$resolvedCount} item bahan tergantung.",
            'resolved_count' => $resolvedCount,
        ]);
    }

    /**
     * Manager / Owner rejects a resolution request.
     */
    public function rejectResolution(Request $request, UrgentNote $urgentNote)
    {
        $user = $request->user();
        if (!$user->isOwnerBisnis() && !$user->isOwnerOutlet() && !$user->isPlatformAdmin()) {
            return response()->json([
                'message' => 'Hanya akun Manager Outlet atau Owner Bisnis yang dapat menolak permohonan pelunasan.'
            ], 403);
        }

        if ($urgentNote->status !== 'APPROVAL_PENDING') {
            return response()->json([
                'message' => 'Nota urgent ini tidak sedang dalam status menunggu persetujuan.'
            ], 422);
        }

        $reason = $request->input('reason', 'Ditolak oleh Manager/Owner');

        $urgentNote->update([
            'status'        => 'PENDING',
            'rejected_by'   => $user->id,
            'rejected_at'   => now(),
            'reject_reason' => $reason,
            'updated_by'    => $user->id,
        ]);

        return response()->json([
            'message'     => "Permohonan pelunasan nota urgent {$urgentNote->order_number} berhasil ditolak.",
            'urgent_note' => $urgentNote->fresh(['outlet', 'menu', 'ingredient', 'rejectedByUser']),
        ]);
    }

    /**
     * Batch reject resolution requests by Manager/Owner.
     */
    public function batchRejectResolution(Request $request)
    {
        $user = $request->user();
        if (!$user->isOwnerBisnis() && !$user->isOwnerOutlet() && !$user->isPlatformAdmin()) {
            return response()->json([
                'message' => 'Hanya akun Manager Outlet atau Owner Bisnis yang dapat menolak permohonan pelunasan.'
            ], 403);
        }

        $businessId = $user->business_id ?: 1;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }
        $orderNumber = $request->input('order_number');
        $noteIds = $request->input('note_ids');
        $reason = $request->input('reason', 'Ditolak secara massal oleh Manager/Owner');

        $query = UrgentNote::where('business_id', $businessId)->where('status', 'APPROVAL_PENDING');
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }
        if ($orderNumber) {
            $query->where('order_number', $orderNumber);
        }
        if (!empty($noteIds) && is_array($noteIds)) {
            $query->whereIn('id', $noteIds);
        }

        $notes = $query->get();
        if ($notes->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada permohonan pelunasan yang dapat ditolak.',
                'rejected_count' => 0
            ], 422);
        }

        $count = 0;
        foreach ($notes as $n) {
            $n->update([
                'status'        => 'PENDING',
                'rejected_by'   => $user->id,
                'rejected_at'   => now(),
                'reject_reason' => $reason,
                'updated_by'    => $user->id,
            ]);
            $count++;
        }

        return response()->json([
            'message'        => "Berhasil menolak {$count} permohonan pelunasan nota urgent.",
            'rejected_count' => $count,
        ]);
    }

    /**
     * Backward-compatible alias for resolve.
     */
    public function resolve(Request $request, UrgentNote $urgentNote)
    {
        $user = $request->user();
        if (!$user->isOwnerBisnis() && !$user->isOwnerOutlet() && !$user->isPlatformAdmin()) {
            return $this->requestResolution($request, $urgentNote);
        }

        return $this->approveResolution($request, $urgentNote);
    }

    /**
     * Backward-compatible alias for batchResolve.
     */
    public function batchResolve(Request $request)
    {
        $user = $request->user();
        if (!$user->isOwnerBisnis() && !$user->isOwnerOutlet() && !$user->isPlatformAdmin()) {
            return $this->batchRequestResolution($request);
        }

        return $this->batchApproveResolution($request);
    }

    /**
     * Cancel / Void a pending urgent note.
     */
    public function cancel(Request $request, UrgentNote $urgentNote)
    {
        if (!in_array($urgentNote->status, ['PENDING', 'APPROVAL_PENDING'])) {
            return response()->json([
                'message' => 'Nota urgent ini sudah tidak berstatus pending.'
            ], 422);
        }

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$urgentNote->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk membatalkan nota urgent di cabang outlet lain.'
                ], 403);
            }
        }

        $reason = $request->input('reason', 'Dibatalkan oleh kasir / penyesuaian manual');

        $urgentNote->update([
            'status'           => 'CANCELLED',
            'resolution_notes' => "DIBATALKAN: {$reason}",
            'resolved_at'      => now(),
            'resolved_by'      => $request->user()->id,
            'updated_by'       => $request->user()->id,
        ]);

        try {
            \App\Services\AccountingService::deleteReferenceEntry('URGENT_NOTE', $urgentNote->id, $urgentNote->business_id ?: 1);
        } catch (\Throwable $e) {}

        return response()->json([
            'message'     => 'Nota urgent berhasil dibatalkan.',
            'urgent_note' => $urgentNote->fresh(),
        ]);
    }

    /**
     * Synchronize missing urgent notes from transactions that were marked as urgent.
     */
    private function syncMissingUrgentNotesFromTransactions(int $businessId, ?int $outletId = null): void
    {
        try {
            $query = Transaction::with(['menu.recipes.items.ingredient', 'menu.bundleItems'])
                ->where('business_id', $businessId)
                ->where(function ($q) {
                    $q->where('is_urgent_note', true)
                      ->orWhere('urgent_status', 'PENDING');
                })
                ->whereDoesntHave('urgentNotes')
                ->limit(50);

            if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
                $query->where(function ($q) use ($outletId) {
                    $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                });
            }

            $urgentTrxs = $query->get();

            foreach ($urgentTrxs as $trx) {
                $menu = $trx->menu;
                if (!$menu) continue;
                $dateStr = $trx->date ? (is_string($trx->date) ? substr($trx->date, 0, 10) : $trx->date->format('Y-m-d')) : now()->toDateString();
                $recipe = $menu->activeRecipe($dateStr);
                $qty = (int)($trx->qty ?: 1);

                if ($recipe && $recipe->items && $recipe->items->count() > 0) {
                    foreach ($recipe->items as $item) {
                        $ing = $item->ingredient;
                        if (!$ing) continue;
                        $reqQty = (float)($item->qty * $qty);
                        $unit = $item->unit ?: $ing->unit_pakai ?: 'satuan';
                        $currentStock = (float)$ing->stockForOutlet($trx->outlet_id ?: 1);
                        $deducted = max(0, min($reqQty, $currentStock));
                        $pending = max(0, round($reqQty - $deducted, 4));
                        if ($pending <= 0) {
                            continue; // Hanya bahan yang habis / defisit yang dicatat sebagai nota urgent
                        }

                        UrgentNote::create([
                            'business_id'    => $businessId,
                            'outlet_id'      => $trx->outlet_id,
                            'transaction_id' => $trx->id,
                            'order_number'   => $trx->order_number ?: ('TRX-' . $trx->id),
                            'menu_id'        => $menu->id,
                            'ingredient_id'  => $ing->id,
                            'item_type'      => 'RECIPE',
                            'item_name'      => $menu->name . ' - ' . $ing->name,
                            'required_qty'   => $reqQty,
                            'deducted_qty'   => $deducted,
                            'pending_qty'    => $pending,
                            'unit'           => $unit,
                            'status'         => 'PENDING',
                            'notes'          => "Nota Urgent POS #{$trx->order_number} – {$menu->name} (Defisit {$pending} {$unit})",
                            'created_by'     => $trx->user_id,
                        ]);
                    }
                } elseif ($menu->item_type === 'DIRECT') {
                    UrgentNote::create([
                        'business_id'    => $businessId,
                        'outlet_id'      => $trx->outlet_id,
                        'transaction_id' => $trx->id,
                        'order_number'   => $trx->order_number ?: ('TRX-' . $trx->id),
                        'menu_id'        => $menu->id,
                        'ingredient_id'  => null,
                        'item_type'      => 'DIRECT',
                        'item_name'      => $menu->name,
                        'required_qty'   => $qty,
                        'deducted_qty'   => 0,
                        'pending_qty'    => $qty,
                        'unit'           => $menu->unit ?: 'pcs',
                        'status'         => 'PENDING',
                        'notes'          => "Nota Urgent POS Retail #{$trx->order_number} – {$menu->name}",
                        'created_by'     => $trx->user_id,
                    ]);
                }
            }

            // Step 2: Auto-heal recent transactions (last 14 days) where ingredients were consumed into deficit/negative stock, but transaction was not marked as urgent
            $unflaggedTrxs = Transaction::with(['menu.recipes.items.ingredient', 'menu.bundleItems'])
                ->where('business_id', $businessId)
                ->where('is_urgent_note', false)
                ->whereDoesntHave('urgentNotes')
                ->whereDate('created_at', '>=', now()->subDays(14)->toDateString())
                ->whereHas('movements', function ($mq) {
                    $mq->where('note', 'like', '%Defisit%')
                      ->orWhereHas('ingredient.outletIngredients', function ($oiq) {
                          $oiq->where('current_stock', '<', 0);
                      });
                })
                ->limit(50)
                ->get();

            foreach ($unflaggedTrxs as $trx) {
                $menu = $trx->menu;
                if (!$menu) continue;
                $dateStr = $trx->date ? (is_string($trx->date) ? substr($trx->date, 0, 10) : $trx->date->format('Y-m-d')) : now()->toDateString();
                $recipe = $menu->activeRecipe($dateStr);
                $qty = (int)($trx->qty ?: 1);
                $createdAny = false;

                if ($recipe && $recipe->items && $recipe->items->count() > 0) {
                    foreach ($recipe->items as $item) {
                        $ing = $item->ingredient;
                        if (!$ing) continue;
                        $reqQty = (float)($item->qty * $qty);
                        $unit = $item->unit ?: $ing->unit_pakai ?: 'satuan';
                        $targetOt = $trx->outlet_id ?: 1;
                        $currentStock = (float)$ing->stockForOutlet($targetOt);

                        if ($currentStock < $reqQty || $currentStock < 0) {
                            $deducted = max(0, min($reqQty, max(0, $currentStock + $reqQty)));
                            $pending = max(0, round($reqQty - $deducted, 4));
                            if ($pending <= 0) {
                                $pending = $reqQty;
                            }

                            UrgentNote::create([
                                'business_id'    => $businessId,
                                'outlet_id'      => $trx->outlet_id,
                                'transaction_id' => $trx->id,
                                'order_number'   => $trx->order_number ?: ('TRX-' . $trx->id),
                                'menu_id'        => $menu->id,
                                'ingredient_id'  => $ing->id,
                                'item_type'      => 'RECIPE',
                                'item_name'      => $menu->name . ' - ' . $ing->name,
                                'required_qty'   => $reqQty,
                                'deducted_qty'   => $deducted,
                                'pending_qty'    => $pending,
                                'unit'           => $unit,
                                'status'         => 'PENDING',
                                'notes'          => "Nota Urgent POS (Auto-Healed) #{$trx->order_number} – {$menu->name} (Defisit {$pending} {$unit})",
                                'created_by'     => $trx->user_id,
                            ]);
                            $createdAny = true;
                        }
                    }
                }

                if ($createdAny) {
                    $trx->update([
                        'is_urgent_note' => true,
                        'urgent_status'  => 'PENDING',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Non-blocking sync
        }
    }

    /**
     * Auto-resolve urgent notes and delete bogus zero-deficit records.
     */
    private function autoResolveFulfilledUrgentNotes(int $businessId, ?int $outletId = null): void
    {
        try {
            // 1. Delete bogus urgent notes that never had any deficit (pending_qty <= 0)
            UrgentNote::where('business_id', $businessId)
                ->where('pending_qty', '<=', 0.0001)
                ->delete();

            // 2. Resolve genuine notes where the ingredient is now replenished / no longer minus in the outlet
            $query = UrgentNote::where('business_id', $businessId)
                ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                ->whereNotNull('ingredient_id');

            if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
                $query->where('outlet_id', $outletId);
            }

            $pendingNotes = $query->with('ingredient')->get();

            foreach ($pendingNotes as $un) {
                $ing = $un->ingredient;
                if (!$ing) continue;
                $targetOt = $un->outlet_id ?: 1;
                $cStock = (float)$ing->stockForOutlet($targetOt);

                // If stock is non-negative and pending_qty is satisfied
                if ($cStock >= (float)$un->pending_qty || $cStock >= 0) {
                    $un->update([
                        'status'           => 'RESOLVED',
                        'resolved_at'      => now(),
                        'resolution_notes' => 'Terselesaikan otomatis (stok fisik bahan di gudang/outlet telah mencukupi).'
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Non-blocking auto resolve
        }
    }
}
