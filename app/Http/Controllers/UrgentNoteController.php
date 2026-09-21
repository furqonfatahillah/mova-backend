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
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->query('outlet_id') ?? $request->header('X-Outlet-Id') ?? $user->outlet_id);

        $query = UrgentNote::with([
            'outlet',
            'transaction.user',
            'menu',
            'ingredient',
            'resolvedByUser',
            'creator'
        ])
        ->where('business_id', $businessId)
        ->orderByDesc('id');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                  ->orWhere('item_name', 'like', "%{$s}%")
                  ->orWhere('notes', 'like', "%{$s}%")
                  ->orWhereHas('menu', fn($mq) => $mq->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('ingredient', fn($iq) => $iq->where('name', 'like', "%{$s}%"));
            });
        }

        $perPage = (int)($request->query('per_page', 50));
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
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->query('outlet_id') ?? $request->header('X-Outlet-Id') ?? $user->outlet_id);

        $baseQuery = UrgentNote::where('business_id', $businessId);
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $baseQuery->where('outlet_id', $outletId);
        }

        if ($request->filled('from')) {
            $baseQuery->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $baseQuery->whereDate('created_at', '<=', $request->to);
        }

        $pendingCount = (clone $baseQuery)->where('status', 'PENDING')->count();
        $pendingTransactionsCount = (clone $baseQuery)->where('status', 'PENDING')->distinct('order_number')->count('order_number');
        $resolvedCount = (clone $baseQuery)->where('status', 'RESOLVED')->count();
        $totalCount = (clone $baseQuery)->count();

        // High deficit ingredients
        $pendingIngredients = (clone $baseQuery)
            ->where('status', 'PENDING')
            ->whereNotNull('ingredient_id')
            ->select('ingredient_id', DB::raw('SUM(pending_qty) as total_pending_qty'), DB::raw('COUNT(*) as note_count'))
            ->groupBy('ingredient_id')
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
            });

        return response()->json([
            'pending_count'              => $pendingCount,
            'pending_transactions_count' => $pendingTransactionsCount,
            'resolved_count'             => $resolvedCount,
            'total_count'                => $totalCount,
            'pending_ingredients'        => $pendingIngredients,
        ]);
    }

    /**
     * Resolve / Fulfill a pending urgent note by deducting the remaining stock shortfall.
     */
    public function resolve(Request $request, UrgentNote $urgentNote)
    {
        if ($urgentNote->status !== 'PENDING') {
            return response()->json([
                'message' => 'Nota urgent ini sudah diselesaikan atau dibatalkan sebelumnya.'
            ], 422);
        }

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$urgentNote->outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk menyelesaikan nota urgent di cabang outlet lain.'
                ], 403);
            }
        }

        $outletId = $urgentNote->outlet_id ?: 1;
        $pendingQty = (float)$urgentNote->pending_qty;
        $notes = $request->input('notes', 'Pelunasan Bahan Tergantung via Nota Urgent');

        $result = DB::transaction(function () use ($urgentNote, $outletId, $pendingQty, $notes, $request) {
            $movementId = null;

            // 1. Deduct Ingredient Stock via StockMovement
            if ($urgentNote->ingredient_id) {
                $ing = Ingredient::findOrFail($urgentNote->ingredient_id);
                $availableStock = $ing->stockForOutlet((int)$outletId);

                // Create StockMovement to deduct remaining deficit
                $mov = StockMovement::create([
                    'business_id'    => $urgentNote->business_id,
                    'outlet_id'      => $outletId,
                    'ingredient_id'  => $ing->id,
                    'date'           => now()->toDateString(),
                    'type'           => 'SALE_USAGE',
                    'qty'            => $pendingQty,
                    'note'           => "{$urgentNote->order_number} – Pelunasan Nota Urgent: {$urgentNote->item_name} ({$pendingQty} {$urgentNote->unit})",
                    'transaction_id' => $urgentNote->transaction_id,
                    'user_id'        => $request->user()->id,
                    'created_by'     => $request->user()->id,
                ]);
                $movementId = $mov->id;
            }

            // 2. Deduct DIRECT Product Stock
            if ($urgentNote->menu_id && $urgentNote->item_type === 'DIRECT') {
                $menu = Menu::find($urgentNote->menu_id);
                if ($menu && $menu->track_stock) {
                    $menu->decrement('stock', $pendingQty);
                    if ($outletId) {
                        try {
                            $om = OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
                            if ($om) {
                                $om->decrement('stock', $pendingQty);
                            }
                        } catch (\Throwable $e) {}
                    }
                }
            }

            // 3. Mark UrgentNote as RESOLVED
            $urgentNote->update([
                'status'                 => 'RESOLVED',
                'resolved_at'            => now(),
                'resolved_by'            => $request->user()->id,
                'resolution_notes'       => $notes,
                'resolution_movement_id' => $movementId,
                'updated_by'             => $request->user()->id,
            ]);

            // 4. If all urgent notes for this transaction are resolved, update transaction urgent_status to RESOLVED
            if ($urgentNote->transaction_id) {
                $hasPendingOthers = UrgentNote::where('transaction_id', $urgentNote->transaction_id)
                    ->where('status', 'PENDING')
                    ->exists();

                if (!$hasPendingOthers) {
                    Transaction::where('id', $urgentNote->transaction_id)->update([
                        'urgent_status' => 'RESOLVED',
                        'updated_by'    => $request->user()->id,
                    ]);
                }
            }

            return $urgentNote->fresh(['outlet', 'menu', 'ingredient', 'resolvedByUser']);
        });

        return response()->json([
            'message'     => "Kekurangan bahan ({$pendingQty} {$urgentNote->unit}) berhasil dilunasi dari stok.",
            'urgent_note' => $result,
        ]);
    }

    /**
     * Cancel / Void a pending urgent note.
     */
    public function cancel(Request $request, UrgentNote $urgentNote)
    {
        if ($urgentNote->status !== 'PENDING') {
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

        return response()->json([
            'message'     => 'Nota urgent berhasil dibatalkan.',
            'urgent_note' => $urgentNote->fresh(),
        ]);
    }

    /**
     * Bulk resolve all pending urgent notes for a given ingredient or all available items.
     */
    public function batchResolve(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id ?: 1;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->input('outlet_id') ?? $request->header('X-Outlet-Id') ?? $user->outlet_id);
        $ingredientId = $request->input('ingredient_id');
        $orderNumber = $request->input('order_number');
        $noteIds = $request->input('note_ids');

        $query = UrgentNote::where('business_id', $businessId)->where('status', 'PENDING');
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
                'message' => 'Tidak ada nota urgent pending yang dipilih / ditemukan.',
                'resolved_count' => 0
            ]);
        }

        $resolvedCount = 0;
        foreach ($notes as $n) {
            try {
                $this->resolve($request, $n);
                $resolvedCount++;
            } catch (\Throwable $e) {}
        }

        return response()->json([
            'message'        => "Berhasil melunasi {$resolvedCount} item bahan tergantung.",
            'resolved_count' => $resolvedCount,
        ]);
    }
}
