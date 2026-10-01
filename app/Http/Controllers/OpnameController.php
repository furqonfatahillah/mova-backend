<?php

namespace App\Http\Controllers;

use App\Models\Opname;
use App\Models\Ingredient;
use App\Models\Outlet;
use Illuminate\Http\Request;

class OpnameController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $from = $request->from ?? now()->toDateString();
        $to   = $request->to   ?? now()->toDateString();

        $query = Opname::with(['ingredient', 'user', 'creator', 'updater', 'outlet'])
            ->where('period_from', $from)
            ->where('period_to',   $to);

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        return response()->json($query->get()->keyBy('ingredient_id'));
    }

    public function upsert(Request $request)
    {
        $data = $request->validate([
            'period_from'   => 'required|date',
            'period_to'     => 'required|date|after_or_equal:period_from',
            'ingredient_id' => 'required|exists:ingredients,id',
            'outlet_id'     => 'nullable|exists:outlets,id',
            'opname_no'     => 'nullable|string|max:50',
            'opname_date'   => 'nullable|date',
            'actual_qty'    => 'nullable|numeric|min:0',
            'reason'        => 'nullable|string|max:255',
            'approver'      => 'nullable|string|max:100',
            'notes'         => 'nullable|string',
            'is_closed'     => 'nullable|boolean',
            'action'        => 'nullable|string',
        ]);

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = $data['outlet_id'] ?? $user?->outlet_id ?? 1;
        }
        $isOwnerOrManager = $this->checkIsOwnerOrManager($user);

        $existing = Opname::where('period_from', $data['period_from'])
            ->where('period_to', $data['period_to'])
            ->where('ingredient_id', $data['ingredient_id'])
            ->where('outlet_id', $outletId)
            ->first();

        $opnameDate = $data['opname_date'] ?? ($existing?->opname_date ? (is_string($existing->opname_date) ? $existing->opname_date : $existing->opname_date->toDateString()) : now()->toDateString());
        $today = now()->toDateString();

        // 1. Tidak boleh tanggal mundur sebelum hari ini
        if ($opnameDate < $today || $data['period_to'] < $today) {
            return response()->json([
                'message' => 'Tanggal pelaksanaan opname tidak boleh tanggal mundur (sebelum hari ini).'
            ], 422);
        }

        // 2. Tidak boleh tanggal mundur dari sesi opname yang telah di-release sebelumnya di cabang ini
        $latestReleased = Opname::where('outlet_id', $outletId)
            ->where('is_closed', true)
            ->where(function ($q) use ($data) {
                if (!empty($data['opname_no'])) {
                    $q->where('opname_no', '!=', $data['opname_no']);
                }
            })
            ->orderByDesc('opname_date')
            ->orderByDesc('period_to')
            ->first();

        if ($latestReleased) {
            $latestDate = $latestReleased->opname_date
                ? (is_string($latestReleased->opname_date) ? $latestReleased->opname_date : $latestReleased->opname_date->toDateString())
                : $latestReleased->period_to;
            if ($opnameDate < $latestDate || $data['period_to'] < $latestDate) {
                return response()->json([
                    'message' => "Tanggal pelaksanaan opname ({$opnameDate}) tidak boleh mundur dari sesi opname yang telah di-release sebelumnya ({$latestDate})."
                ], 422);
            }
        }

        if ($existing && $existing->is_closed) {
            // If the item is already closed/released, keep actual_qty locked to protect physical stock movement integrity,
            // but allow updating Root Cause analysis metadata (reason, approver, notes).
            if (isset($data['actual_qty']) && $data['actual_qty'] !== null && (float)$data['actual_qty'] !== (float)$existing->actual_qty) {
                return response()->json([
                    'message' => "Item opname ini sudah berstatus release (terkunci). Kuantitas fisik (stok aktual) tidak dapat diubah lagi."
                ], 422);
            }

            $existing->update([
                'reason'     => $data['reason']   ?? $existing->reason,
                'approver'   => $data['approver'] ?? $existing->approver,
                'notes'      => $data['notes']    ?? $existing->notes,
                'updated_by' => $user->id,
            ]);

            $existing->load(['ingredient', 'user', 'creator', 'updater', 'outlet']);
            return response()->json($existing);
        }

        $opnameNo = $data['opname_no'] ?? ($existing?->opname_no);
        if (!$opnameNo) {
            $dateClean = str_replace('-', '', $opnameDate);
            $countToday = Opname::whereDate('created_at', now()->toDateString())
                ->whereNotNull('opname_no')
                ->distinct('opname_no')
                ->count('opname_no') + 1;
            $opnameNo = 'OPN-' . $dateClean . '-' . str_pad($countToday, 4, '0', STR_PAD_LEFT);
        }

        $isClosed = ($isOwnerOrManager && (($data['action'] ?? '') === 'RELEASE' || !empty($data['is_closed'])));

        // Precompute theoretical snapshot values
        $reportCtrl = app(ReportController::class);
        $varianceRows = $reportCtrl->buildVarianceArray($data['period_from'], $data['period_to'], (int)$outletId);
        $var = collect($varianceRows)->firstWhere('ingredient.id', (int)$data['ingredient_id']);

        $actualQty = isset($data['actual_qty']) && $data['actual_qty'] !== null ? (float)$data['actual_qty'] : null;
        $hasActual = $actualQty !== null;

        $updateData = [
            ...$data,
            'opname_no'   => $opnameNo,
            'opname_date' => $opnameDate,
            'outlet_id'   => $outletId,
            'user_id'     => $user->id,
            'is_closed'   => $isClosed,
        ];

        if ($var) {
            $costPerUnit = (float)($var['cost_per_unit'] ?? 0);
            $stokAkhirTeo = (float)($var['stok_akhir_teoritis'] ?? 0);
            $varQty = $hasActual ? round($actualQty - $stokAkhirTeo, 4) : null;
            $varVal = $hasActual ? round($varQty * $costPerUnit, 0) : null;
            $denom = abs($stokAkhirTeo) > 0.0001 ? abs($stokAkhirTeo) : ($hasActual && abs($actualQty) > 0.0001 ? abs($actualQty) : 1.0);
            $varPct = $hasActual ? round(($varQty / $denom) * 100, 2) : null;
            $status = null;
            if ($hasActual) {
                $status = abs($varQty) < 0.0001 ? 'NORMAL' : (abs($varPct) <= (float)($var['ingredient']->tolerance ?? 1) ? 'NORMAL' : (abs($varPct) <= 5 ? 'WASPADA' : 'TIDAK WAJAR'));
            }

            $updateData['stok_awal_periode']   = $var['stok_awal_periode'] ?? 0;
            $updateData['pembelian']           = $var['pembelian'] ?? 0;
            $updateData['pemakaian_teoritis']  = $var['pemakaian_teoritis'] ?? 0;
            $updateData['prep_usage']          = $var['prep_usage'] ?? 0;
            $updateData['prep_output']         = $var['prep_output'] ?? 0;
            $updateData['waste_qty']           = $var['waste'] ?? 0;
            $updateData['waste_value']         = $var['waste_value'] ?? 0;
            $updateData['transfer_in']         = $var['transfer_in'] ?? 0;
            $updateData['transfer_out']        = $var['transfer_out'] ?? 0;
            $updateData['adjustment_qty']      = $var['adjustment'] ?? 0;
            $updateData['stok_akhir_teoritis'] = $stokAkhirTeo;
            $updateData['cost_per_unit']       = $costPerUnit;
            $updateData['nilai_teoritis']      = round($stokAkhirTeo * $costPerUnit, 0);
            $updateData['nilai_aktual']        = $hasActual ? round($actualQty * $costPerUnit, 0) : null;
            $updateData['variance_qty']        = $varQty;
            $updateData['variance_value']      = $varVal;
            $updateData['variance_pct']        = $varPct;
            $updateData['status']              = $status;
        }

        if ($existing) {
            $updateData['updated_by'] = $user->id;
        } else {
            $updateData['created_by'] = $user->id;
        }

        $opname = Opname::updateOrCreate(
            [
                'period_from'   => $data['period_from'],
                'period_to'     => $data['period_to'],
                'ingredient_id' => $data['ingredient_id'],
                'outlet_id'     => $outletId,
            ],
            $updateData
        );

        if ($isClosed) {
            $this->syncOpnameStockMovements(
                $opnameNo,
                $data['period_from'],
                $data['period_to'],
                (int)$outletId,
                $user,
                $opnameDate
            );
        }

        $opname->load(['ingredient', 'user', 'creator', 'updater', 'outlet']);
        return response()->json($opname);
    }

    public function bulkUpsert(Request $request)
    {
        $request->validate([
            'period_from'   => 'required|date',
            'period_to'     => 'required|date',
            'outlet_id'     => 'nullable|exists:outlets,id',
            'opname_no'     => 'nullable|string|max:50',
            'opname_date'   => 'nullable|date',
            'approver'      => 'nullable|string|max:100',
            'notes'         => 'nullable|string',
            'action'        => 'nullable|string',
            'is_closed'     => 'nullable|boolean',
            'items'         => 'required|array',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.actual_qty'    => 'nullable|numeric|min:0',
            'items.*.reason'        => 'nullable|string',
            'items.*.approver'      => 'nullable|string',
            'items.*.notes'         => 'nullable|string',
        ]);

        $opnameDate = $request->opname_date ?? now()->toDateString();
        $today = now()->toDateString();

        // 1. Tidak boleh tanggal mundur sebelum hari ini
        if ($opnameDate < $today || $request->period_to < $today) {
            return response()->json([
                'message' => 'Tanggal pelaksanaan opname tidak boleh di-inputkan tanggal mundur (sebelum hari ini).'
            ], 422);
        }

        $outletId = $request->outlet_id ?? $request->user()->outlet_id ?? 1;
        $user = $request->user();
        $isOwnerOrManager = $this->checkIsOwnerOrManager($user);

        // 2. Tidak boleh tanggal mundur dari sesi opname yang telah di-release sebelumnya di cabang ini
        $latestReleased = Opname::where('outlet_id', $outletId)
            ->where('is_closed', true)
            ->where(function ($q) use ($request) {
                if ($request->filled('opname_no')) {
                    $q->where('opname_no', '!=', $request->opname_no);
                }
            })
            ->orderByDesc('opname_date')
            ->orderByDesc('period_to')
            ->first();

        if ($latestReleased) {
            $latestDate = $latestReleased->opname_date
                ? (is_string($latestReleased->opname_date) ? $latestReleased->opname_date : $latestReleased->opname_date->toDateString())
                : $latestReleased->period_to;
            if ($opnameDate < $latestDate || $request->period_to < $latestDate) {
                return response()->json([
                    'message' => "Tanggal pelaksanaan opname ({$opnameDate}) tidak boleh mundur dari sesi opname yang telah di-release sebelumnya ({$latestDate})."
                ], 422);
            }
        }

        // Check if any item in this period & outlet already has a released/closed session
        $existingSession = Opname::where('period_from', $request->period_from)
            ->where('period_to', $request->period_to)
            ->where('outlet_id', $outletId)
            ->whereNotNull('opname_no')
            ->first();

        if ($existingSession && $existingSession->is_closed) {
            return response()->json([
                'message' => "Sesi Opname ({$existingSession->opname_no}) sudah di-release dan dikunci permanen. Dokumen tidak dapat diubah lagi."
            ], 422);
        }

        // Determine if this opname session is RELEASED or DRAFT
        $isClosed = ($isOwnerOrManager && ($request->action === 'RELEASE' || $request->is_closed));

        $opnameNo = $request->opname_no ?? ($existingSession?->opname_no);
        if (!$opnameNo) {
            $dateClean = str_replace('-', '', $opnameDate);
            $countToday = Opname::whereDate('created_at', now()->toDateString())
                ->whereNotNull('opname_no')
                ->distinct('opname_no')
                ->count('opname_no') + 1;
            $opnameNo = 'OPN-' . $dateClean . '-' . str_pad($countToday, 4, '0', STR_PAD_LEFT);
        }

        // Precompute theoretical snapshot values
        $reportCtrl = app(ReportController::class);
        $varianceRows = $reportCtrl->buildVarianceArray($request->period_from, $request->period_to, (int)$outletId);
        $varByIng = collect($varianceRows)->keyBy(fn($r) => $r['ingredient']->id);

        $results = [];
        foreach ($request->items as $item) {
            $existing = Opname::where('period_from', $request->period_from)
                ->where('period_to', $request->period_to)
                ->where('ingredient_id', $item['ingredient_id'])
                ->where('outlet_id', $outletId)
                ->first();

            $actualQty = isset($item['actual_qty']) && $item['actual_qty'] !== null ? (float)$item['actual_qty'] : null;
            $hasActual = $actualQty !== null;

            $updateData = [
                'opname_no'   => $opnameNo,
                'opname_date' => $opnameDate,
                'actual_qty'  => $actualQty,
                'reason'      => $item['reason'] ?? ($request->reason ?? null),
                'approver'    => $item['approver'] ?? ($request->approver ?? null),
                'notes'       => $item['notes'] ?? ($request->notes ?? null),
                'outlet_id'   => $outletId,
                'user_id'     => $user->id,
                'is_closed'   => $isClosed,
            ];

            $var = $varByIng->get($item['ingredient_id']);
            if ($var) {
                $costPerUnit = (float)($var['cost_per_unit'] ?? 0);
                $stokAkhirTeo = (float)($var['stok_akhir_teoritis'] ?? 0);
                $varQty = $hasActual ? round($actualQty - $stokAkhirTeo, 4) : null;
                $varVal = $hasActual ? round($varQty * $costPerUnit, 0) : null;
                $denom = abs($stokAkhirTeo) > 0.0001 ? abs($stokAkhirTeo) : ($hasActual && abs($actualQty) > 0.0001 ? abs($actualQty) : 1.0);
                $varPct = $hasActual ? round(($varQty / $denom) * 100, 2) : null;
                $status = null;
                if ($hasActual) {
                    $status = abs($varQty) < 0.0001 ? 'NORMAL' : (abs($varPct) <= (float)($var['ingredient']->tolerance ?? 1) ? 'NORMAL' : (abs($varPct) <= 5 ? 'WASPADA' : 'TIDAK WAJAR'));
                }

                $updateData['stok_awal_periode']   = $var['stok_awal_periode'] ?? 0;
                $updateData['pembelian']           = $var['pembelian'] ?? 0;
                $updateData['pemakaian_teoritis']  = $var['pemakaian_teoritis'] ?? 0;
                $updateData['prep_usage']          = $var['prep_usage'] ?? 0;
                $updateData['prep_output']         = $var['prep_output'] ?? 0;
                $updateData['waste_qty']           = $var['waste'] ?? 0;
                $updateData['waste_value']         = $var['waste_value'] ?? 0;
                $updateData['transfer_in']         = $var['transfer_in'] ?? 0;
                $updateData['transfer_out']        = $var['transfer_out'] ?? 0;
                $updateData['adjustment_qty']      = $var['adjustment'] ?? 0;
                $updateData['stok_akhir_teoritis'] = $stokAkhirTeo;
                $updateData['cost_per_unit']       = $costPerUnit;
                $updateData['nilai_teoritis']      = round($stokAkhirTeo * $costPerUnit, 0);
                $updateData['nilai_aktual']        = $hasActual ? round($actualQty * $costPerUnit, 0) : null;
                $updateData['variance_qty']        = $varQty;
                $updateData['variance_value']      = $varVal;
                $updateData['variance_pct']        = $varPct;
                $updateData['status']              = $status;
            }

            if ($existing) {
                $updateData['updated_by'] = $user->id;
            } else {
                $updateData['created_by'] = $user->id;
            }

            $opname = Opname::updateOrCreate(
                [
                    'period_from'   => $request->period_from,
                    'period_to'     => $request->period_to,
                    'ingredient_id' => $item['ingredient_id'],
                    'outlet_id'     => $outletId,
                ],
                $updateData
            );
            $opname->load(['ingredient', 'user', 'creator', 'updater', 'outlet']);
            $results[] = $opname;
        }

        if ($isClosed) {
            $this->syncOpnameStockMovements(
                $opnameNo,
                $request->period_from,
                $request->period_to,
                (int)$outletId,
                $user,
                $opnameDate
            );
        }

        $statusMessage = $isClosed
            ? "Data opname fisik ({$opnameNo}) berhasil di-release & disetujui, dan mutasi penyesuaian telah dibukukan ke Kartu Stok!"
            : "Data opname fisik ({$opnameNo}) berhasil disimpan sebagai DRAFT (Menunggu Release Owner).";

        return response()->json([
            'message'    => $statusMessage,
            'opname_no'  => $opnameNo,
            'is_closed'  => $isClosed,
            'status'     => $isClosed ? 'RELEASED' : 'DRAFT',
            'items'      => $results,
        ]);
    }

    /**
     * Release / Approve a Draft Opname session (Owner / Store Manager only)
     */
    public function releaseSession(Request $request, $opnameNo)
    {
        $user = $request->user();
        $isOwnerOrManager = $this->checkIsOwnerOrManager($user);

        if (!$isOwnerOrManager) {
            return response()->json([
                'message' => 'Hanya Owner atau Store Manager yang berhak merilis (Release) dokumen opname.'
            ], 403);
        }

        $opnames = Opname::where('opname_no', $opnameNo)->get();
        if ($opnames->isEmpty()) {
            return response()->json(['message' => 'Dokumen sesi opname tidak ditemukan.'], 404);
        }

        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $firstOpn = $opnames->first();
            if ($firstOpn && (int)$firstOpn->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk merilis dokumen opname di cabang lain.'], 403);
            }
        }

        $approverName = $request->approver ?: ($user->name ?? 'Owner Bisnis');
        $firstOpn = $opnames->first();

        // Compute theoretical snapshot baseline only if existing snapshot is missing
        $reportCtrl = app(ReportController::class);
        $varByIng = null;

        foreach ($opnames as $opn) {
            $hasExistingSnapshot = ($opn->stok_akhir_teoritis !== null);

            if (!$hasExistingSnapshot) {
                if ($varByIng === null) {
                    $varianceRows = $reportCtrl->buildVarianceArray($firstOpn->period_from, $firstOpn->period_to, (int)$firstOpn->outlet_id);
                    $varByIng = collect($varianceRows)->keyBy(fn($r) => $r['ingredient']->id);
                }
                $var = $varByIng->get($opn->ingredient_id);
                if ($var) {
                    $costPerUnit = (float)($var['cost_per_unit'] ?? 0);
                    $stokAkhirTeo = (float)($var['stok_akhir_teoritis'] ?? 0);
                    $actualQty = $opn->actual_qty !== null ? (float)$opn->actual_qty : null;
                    $hasActual = $actualQty !== null;
                    $varQty = $hasActual ? round($actualQty - $stokAkhirTeo, 4) : null;
                    $varVal = $hasActual ? round($varQty * $costPerUnit, 0) : null;
                    $denom = abs($stokAkhirTeo) > 0.0001 ? abs($stokAkhirTeo) : ($hasActual && abs($actualQty) > 0.0001 ? abs($actualQty) : 1.0);
                    $varPct = $hasActual ? round(($varQty / $denom) * 100, 2) : null;
                    $status = null;
                    if ($hasActual) {
                        $status = abs($varQty) < 0.0001 ? 'NORMAL' : (abs($varPct) <= (float)($var['ingredient']->tolerance ?? 1) ? 'NORMAL' : (abs($varPct) <= 5 ? 'WASPADA' : 'TIDAK WAJAR'));
                    }

                    $opn->stok_awal_periode   = $var['stok_awal_periode'] ?? 0;
                    $opn->pembelian           = $var['pembelian'] ?? 0;
                    $opn->pemakaian_teoritis  = $var['pemakaian_teoritis'] ?? 0;
                    $opn->prep_usage          = $var['prep_usage'] ?? 0;
                    $opn->prep_output         = $var['prep_output'] ?? 0;
                    $opn->waste_qty           = $var['waste'] ?? 0;
                    $opn->waste_value         = $var['waste_value'] ?? 0;
                    $opn->transfer_in         = $var['transfer_in'] ?? 0;
                    $opn->transfer_out        = $var['transfer_out'] ?? 0;
                    $opn->adjustment_qty      = $var['adjustment'] ?? 0;
                    $opn->stok_akhir_teoritis = $stokAkhirTeo;
                    $opn->cost_per_unit       = $costPerUnit;
                    $opn->nilai_teoritis      = round($stokAkhirTeo * $costPerUnit, 0);
                    $opn->nilai_aktual        = $hasActual ? round($actualQty * $costPerUnit, 0) : null;
                    $opn->variance_qty        = $varQty;
                    $opn->variance_value      = $varVal;
                    $opn->variance_pct        = $varPct;
                    $opn->status              = $status;
                }
            } else {
                // 🔒 Preserve the exact pre-opname theoretical stock snapshot
                $stokAkhirTeo = (float)$opn->stok_akhir_teoritis;
                $costPerUnit  = (float)($opn->cost_per_unit ?: ($opn->ingredient?->harga / max(1, (float)($opn->ingredient?->konversi ?: 1))));
                $actualQty    = $opn->actual_qty !== null ? (float)$opn->actual_qty : null;
                $hasActual    = $actualQty !== null;
                $varQty       = $hasActual ? round($actualQty - $stokAkhirTeo, 4) : null;
                $varVal       = $hasActual ? round($varQty * $costPerUnit, 0) : null;
                $denom        = abs($stokAkhirTeo) > 0.0001 ? abs($stokAkhirTeo) : ($hasActual && abs($actualQty) > 0.0001 ? abs($actualQty) : 1.0);
                $varPct       = $hasActual ? round(($varQty / $denom) * 100, 2) : null;
                $status       = null;
                if ($hasActual) {
                    $status   = abs($varQty) < 0.0001 ? 'NORMAL' : (abs($varPct) <= (float)($opn->ingredient?->tolerance ?? 1) ? 'NORMAL' : (abs($varPct) <= 5 ? 'WASPADA' : 'TIDAK WAJAR'));
                }

                $opn->nilai_teoritis = round($stokAkhirTeo * $costPerUnit, 0);
                $opn->nilai_aktual   = $hasActual ? round($actualQty * $costPerUnit, 0) : null;
                $opn->variance_qty   = $varQty;
                $opn->variance_value = $varVal;
                $opn->variance_pct   = $varPct;
                $opn->status         = $status;
            }

            $opn->is_closed  = true;
            $opn->approver   = $approverName;
            if ($request->filled('notes')) {
                $opn->notes = $request->notes;
            }
            $opn->updated_by = $user->id;
            $opn->save();
        }

        if ($firstOpn) {
            $this->syncOpnameStockMovements(
                $opnameNo,
                $firstOpn->period_from,
                $firstOpn->period_to,
                (int)$firstOpn->outlet_id,
                $user,
                $firstOpn->opname_date
            );
        }

        return response()->json([
            'message'   => "Sesi Opname {$opnameNo} berhasil di-release dan disetujui oleh Owner ({$approverName}), dan mutasi penyesuaian telah dibukukan ke Kartu Stok!",
            'opname_no' => $opnameNo,
            'is_closed' => true,
            'status'    => 'RELEASED',
        ]);
    }

    /**
     * Generate StockMovement adjustments for a released opname session.
     * Reconciles difference between physical actual_qty and theoretical ending stock.
     */
    private function syncOpnameStockMovements(string $opnameNo, string $periodFrom, string $periodTo, int $outletId, $user, ?string $opnameDate = null): void
    {
        // 1. Remove previous adjustments generated for this opname session (idempotency)
        \App\Models\StockMovement::where('outlet_id', $outletId)
            ->where('note', 'like', "%{$opnameNo}%")
            ->delete();

        $opnames = Opname::with('ingredient')
            ->where('opname_no', $opnameNo)
            ->where('outlet_id', $outletId)
            ->get();

        $adjDate = $opnameDate ?: ($opnames->first()?->opname_date ?: $periodTo);

        foreach ($opnames as $opn) {
            if ($opn->actual_qty === null) continue;

            $stokTeoritis = (float)($opn->stok_akhir_teoritis ?? 0);
            $actualQty    = (float)$opn->actual_qty;
            $diff         = round($actualQty - $stokTeoritis, 4);

            if (abs($diff) < 0.0001) {
                continue; // Exact match, no adjustment needed
            }

            $ingredient = $opn->ingredient ?: Ingredient::find($opn->ingredient_id);
            if (!$ingredient) continue;

            $targetOid = ($outletId && $outletId !== 'ALL' && $outletId !== 'all') ? (int)$outletId : null;
            $hargaPerPakai = (float) $ingredient->costPerPakaiForOutlet($targetOid);

            if ($diff > 0) {
                // Surplus: ADJUSTMENT_IN (+)
                \App\Models\StockMovement::create([
                    'business_id'   => $user->business_id ?? $ingredient->business_id,
                    'date'          => $adjDate,
                    'ingredient_id' => $opn->ingredient_id,
                    'outlet_id'     => $outletId,
                    'type'          => 'ADJUSTMENT_IN',
                    'qty'           => abs($diff),
                    'unit_price'    => round($hargaPerPakai, 2),
                    'total_price'   => round(abs($diff) * $hargaPerPakai, 2),
                    'note'          => "Penyesuaian Opname Fisik {$opnameNo} (Surplus +)",
                    'user_id'       => $user->id,
                    'created_by'    => $user->id,
                ]);
            } else {
                // Defisit: ADJUSTMENT_OUT (-)
                \App\Models\StockMovement::create([
                    'business_id'   => $user->business_id ?? $ingredient->business_id,
                    'date'          => $adjDate,
                    'ingredient_id' => $opn->ingredient_id,
                    'outlet_id'     => $outletId,
                    'type'          => 'ADJUSTMENT_OUT',
                    'qty'           => abs($diff),
                    'unit_price'    => round($hargaPerPakai, 2),
                    'total_price'   => round(abs($diff) * $hargaPerPakai, 2),
                    'note'          => "Penyesuaian Opname Fisik {$opnameNo} (Selisih Kurang -)",
                    'user_id'       => $user->id,
                    'created_by'    => $user->id,
                ]);
            }
        }
    }

    /**
     * Get list of historical Opname sessions with summary variance
     */
    public function history(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $query = Opname::with(['outlet', 'creator', 'user'])
            ->whereNotNull('opname_no')
            ->orderByDesc('opname_date')
            ->orderByDesc('id');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        $allOpnames = $query->get();
        $grouped = $allOpnames->groupBy('opname_no');

        $sessions = [];
        $reportCtrl = app(ReportController::class);

        foreach ($grouped as $opnameNo => $items) {
            $first = $items->first();
            $outId = $first->outlet_id;
            $from = $first->period_from;
            $to = $first->period_to;

            $totalItems = $items->count();
            $itemsCounted = $items->filter(fn($i) => $i->actual_qty !== null)->count();
            $surplusCount = 0;
            $deficitCount = 0;
            $matchCount = 0;
            $netVarianceValue = 0;
            $totalTheoreticalValue = 0;
            $totalActualValue = 0;
            $totalDeficitValue = 0;
            $totalSurplusValue = 0;

            if ($first->is_closed) {
                // 🔒 LOCKED SNAPSHOT: Immutable frozen figures from database
                foreach ($items as $it) {
                    $teoVal = (float)($it->nilai_teoritis ?? round(((float)($it->stok_akhir_teoritis ?? 0)) * ((float)($it->cost_per_unit ?? 0)), 0));
                    $totalTheoreticalValue += $teoVal;

                    if ($it->actual_qty !== null) {
                        $actVal = (float)($it->nilai_aktual ?? round(((float)$it->actual_qty) * ((float)($it->cost_per_unit ?? 0)), 0));
                        $totalActualValue += $actVal;
                    }

                    if ($it->variance_value !== null) {
                        $varVal = (float)$it->variance_value;
                        $varQty = (float)$it->variance_qty;
                        $netVarianceValue += $varVal;
                        if ($varQty > 0.0001) {
                            $surplusCount++;
                            $totalSurplusValue += $varVal;
                        } elseif ($varQty < -0.0001) {
                            $deficitCount++;
                            $totalDeficitValue += abs($varVal);
                        } else {
                            $matchCount++;
                        }
                    }
                }
            } else {
                $varianceRows = $reportCtrl->buildVarianceArray($from, $to, $outId);
                $varByIng = collect($varianceRows)->keyBy(fn($r) => $r['ingredient']->id);

                foreach ($items as $it) {
                    $var = $varByIng->get($it->ingredient_id);
                    if ($var) {
                        $teoVal = $var['nilai_teoritis'] ?? round(($var['stok_akhir_teoritis'] ?? 0) * ($var['cost_per_unit'] ?? 0), 0);
                        $totalTheoreticalValue += $teoVal;

                        if ($it->actual_qty !== null) {
                            $actVal = $var['nilai_aktual'] ?? round(((float)$it->actual_qty) * ($var['cost_per_unit'] ?? 0), 0);
                            $totalActualValue += $actVal;
                        }

                        if ($var['variance_value'] !== null) {
                            $netVarianceValue += $var['variance_value'];
                            if ($var['variance_qty'] > 0.0001) {
                                $surplusCount++;
                                $totalSurplusValue += $var['variance_value'];
                            } elseif ($var['variance_qty'] < -0.0001) {
                                $deficitCount++;
                                $totalDeficitValue += abs($var['variance_value']);
                            } else {
                                $matchCount++;
                            }
                        }
                    }
                }
            }

            $sessions[] = [
                'opname_no'               => $opnameNo,
                'opname_date'             => $first->opname_date ?: $first->period_to,
                'period_from'             => $from,
                'period_to'               => $to,
                'outlet_id'               => $outId,
                'outlet_name'             => $first->outlet?->name ?? "Outlet #{$outId}",
                'created_by_name'         => $first->creator?->name ?? ($first->user?->name ?? 'Staff Gudang'),
                'approver'                => $first->approver,
                'notes'                   => $first->notes,
                'total_items'             => $totalItems,
                'items_counted'           => $itemsCounted,
                'surplus_count'           => $surplusCount,
                'deficit_count'           => $deficitCount,
                'match_count'             => $matchCount,
                'total_theoretical_value' => round($totalTheoreticalValue, 0),
                'total_actual_value'      => round($totalActualValue, 0),
                'total_surplus_value'     => round($totalSurplusValue, 0),
                'total_deficit_value'     => round($totalDeficitValue, 0),
                'net_variance_value'      => round($netVarianceValue, 0),
                'is_closed'               => (bool) $first->is_closed,
                'status'                  => $first->is_closed ? 'RELEASED' : 'DRAFT',
                'created_at'              => $first->created_at?->format('Y-m-d H:i:s'),
            ];
        }

        return response()->json($sessions);
    }

    /**
     * Get detailed Berita Acara session data for a specific opname_no
     */
    public function showSession($opnameNo)
    {
        $opnames = Opname::with(['ingredient', 'outlet', 'creator', 'user', 'updater'])
            ->where('opname_no', $opnameNo)
            ->get();

        if ($opnames->isEmpty()) {
            return response()->json(['message' => 'Dokumen sesi opname tidak ditemukan.'], 404);
        }

        $user = auth()->user() ?? request()->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $firstOpn = $opnames->first();
            if ($firstOpn && (int)$firstOpn->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk melihat sesi opname cabang lain.'], 403);
            }
        }

        $first = $opnames->first();
        $outId = $first->outlet_id;
        $from = $first->period_from;
        $to = $first->period_to;

        $detailedItems = [];
        $totalSurplusValue = 0;
        $totalDeficitValue = 0;
        $totalWasteValue = 0;
        $totalVarianceValue = 0;
        $totalTheoreticalValue = 0;
        $totalActualValue = 0;
        $statusCounts = ['NORMAL' => 0, 'WASPADA' => 0, 'TIDAK WAJAR' => 0];

        if ($first->is_closed) {
            // 🔒 LOCKED SNAPSHOT: Read directly from frozen opname records
            foreach ($opnames as $opn) {
                $ing = $opn->ingredient;
                $costPerUnit = (float) ($opn->cost_per_unit ?? ($ing ? $ing->harga / max(1, (float)$ing->konversi) : 0));
                $stokTeo = (float) ($opn->stok_akhir_teoritis ?? 0);
                $teoVal = (float) ($opn->nilai_teoritis ?? round($stokTeo * $costPerUnit, 0));
                $totalTheoreticalValue += $teoVal;

                $actQty = $opn->actual_qty !== null ? (float)$opn->actual_qty : null;
                $actVal = null;
                if ($actQty !== null) {
                    $actVal = $opn->nilai_aktual !== null ? (float)$opn->nilai_aktual : round($actQty * $costPerUnit, 0);
                    $totalActualValue += $actVal;
                }

                $varQty = $opn->variance_qty !== null ? (float)$opn->variance_qty : ($actQty !== null ? round($actQty - $stokTeo, 4) : null);
                $varVal = $opn->variance_value !== null ? (float)$opn->variance_value : ($varQty !== null ? round($varQty * $costPerUnit, 0) : null);

                if ($varVal !== null) {
                    $totalVarianceValue += $varVal;
                    if ($varVal > 0) $totalSurplusValue += $varVal;
                    if ($varVal < 0) $totalDeficitValue += abs($varVal);
                }

                $wasteVal = (float) ($opn->waste_value ?? 0);
                $totalWasteValue += $wasteVal;

                $status = $opn->status ?? ($actQty !== null ? (abs($varQty ?? 0) < 0.0001 ? 'NORMAL' : 'NORMAL') : null);
                if ($status && isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                }

                $detailedItems[] = [
                    'ingredient_id'        => $opn->ingredient_id,
                    'code'                 => $ing?->code ?? '-',
                    'name'                 => $ing?->name ?? "Bahan #{$opn->ingredient_id}",
                    'category'             => $ing?->category ?? 'Umum',
                    'unit_pakai'           => $ing?->unit_pakai ?? 'satuan',
                    'unit_beli'            => $ing?->unit_beli ?? 'satuan',
                    'konversi'             => $ing?->konversi ?? 1,
                    'cost_per_unit'        => $costPerUnit,
                    'harga'                => $ing?->harga ?? 0,
                    'stok_awal_periode'    => (float) ($opn->stok_awal_periode ?? 0),
                    'pembelian'            => (float) ($opn->pembelian ?? 0),
                    'pemakaian_teoritis'   => (float) ($opn->pemakaian_teoritis ?? 0),
                    'prep_usage'           => (float) ($opn->prep_usage ?? 0),
                    'prep_output'          => (float) ($opn->prep_output ?? 0),
                    'waste'                => (float) ($opn->waste_qty ?? 0),
                    'waste_value'          => $wasteVal,
                    'transfer_in'          => (float) ($opn->transfer_in ?? 0),
                    'transfer_out'         => (float) ($opn->transfer_out ?? 0),
                    'stok_akhir_teoritis'  => $stokTeo,
                    'nilai_teoritis'       => $teoVal,
                    'stok_akhir_aktual'    => $actQty,
                    'nilai_aktual'         => $actVal,
                    'variance_qty'         => $varQty,
                    'variance_pct'         => $opn->variance_pct !== null ? (float)$opn->variance_pct : null,
                    'variance_value'       => $varVal,
                    'status'               => $status,
                    'reason'               => $opn->reason ?: $opn->notes,
                    'approver'             => $opn->approver,
                    'audit'                => [
                        'created_at'      => $opn->created_at?->format('Y-m-d H:i:s'),
                        'created_by_name' => $opn->creator?->name ?? ($opn->user?->name ?? 'Staff'),
                        'updated_at'      => $opn->updated_at?->format('Y-m-d H:i:s'),
                        'updated_by_name' => $opn->updater?->name ?? ($opn->user?->name ?? 'Staff'),
                    ],
                ];
            }
        } else {
            $reportCtrl = app(ReportController::class);
            $varianceRows = $reportCtrl->buildVarianceArray($from, $to, $outId);
            $opnamesByIng = $opnames->keyBy('ingredient_id');

            foreach ($varianceRows as $vr) {
                $ingId = $vr['ingredient']->id;
                $opnRecord = $opnamesByIng->get($ingId);

                $varVal = $vr['variance_value'] ?? 0;
                $costPerUnit = $vr['cost_per_unit'] ?? ($vr['ingredient']->harga / max(1, (float)$vr['ingredient']->konversi));
                $teoVal = $vr['nilai_teoritis'] ?? round(($vr['stok_akhir_teoritis'] ?? 0) * $costPerUnit, 0);
                $totalTheoreticalValue += $teoVal;

                $actQty = $vr['stok_akhir_aktual'];
                $actVal = null;
                if ($actQty !== null) {
                    $actVal = $vr['nilai_aktual'] ?? round($actQty * $costPerUnit, 0);
                    $totalActualValue += $actVal;
                }

                if ($vr['variance_value'] !== null) {
                    $totalVarianceValue += $varVal;
                    if ($varVal > 0) $totalSurplusValue += $varVal;
                    if ($varVal < 0) $totalDeficitValue += abs($varVal);
                }
                if (isset($vr['waste_value'])) $totalWasteValue += $vr['waste_value'];
                if ($vr['status'] && isset($statusCounts[$vr['status']])) {
                    $statusCounts[$vr['status']]++;
                }

                $detailedItems[] = [
                    'ingredient_id'        => $ingId,
                    'code'                 => $vr['ingredient']->code,
                    'name'                 => $vr['ingredient']->name,
                    'category'             => $vr['ingredient']->category,
                    'unit_pakai'           => $vr['ingredient']->unit_pakai,
                    'unit_beli'            => $vr['ingredient']->unit_beli,
                    'konversi'             => $vr['ingredient']->konversi,
                    'cost_per_unit'        => $costPerUnit,
                    'harga'                => $vr['ingredient']->harga,
                    'stok_awal_periode'    => $vr['stok_awal_periode'],
                    'pembelian'            => $vr['pembelian'],
                    'pemakaian_teoritis'   => $vr['pemakaian_teoritis'],
                    'prep_usage'           => $vr['prep_usage'] ?? 0,
                    'prep_output'          => $vr['prep_output'] ?? 0,
                    'waste'                => $vr['waste'],
                    'waste_value'          => $vr['waste_value'] ?? 0,
                    'transfer_in'          => $vr['transfer_in'],
                    'transfer_out'         => $vr['transfer_out'],
                    'stok_akhir_teoritis'  => $vr['stok_akhir_teoritis'],
                    'nilai_teoritis'       => $teoVal,
                    'stok_akhir_aktual'    => $actQty,
                    'nilai_aktual'         => $actVal,
                    'variance_qty'         => $vr['variance_qty'],
                    'variance_pct'         => $vr['variance_pct'],
                    'variance_value'       => $vr['variance_value'],
                    'status'               => $vr['status'],
                    'reason'               => $opnRecord?->reason ?: $opnRecord?->notes,
                    'approver'             => $opnRecord?->approver,
                    'audit'                => [
                        'created_at'      => $opnRecord?->created_at?->format('Y-m-d H:i:s'),
                        'created_by_name' => $opnRecord?->created_by_name ?? ($opnRecord?->user?->name ?? 'Staff'),
                        'updated_at'      => $opnRecord?->changed_at,
                        'updated_by_name' => $opnRecord?->changed_by_name,
                    ],
                ];
            }
        }

        return response()->json([
            'session' => [
                'opname_no'       => $first->opname_no,
                'opname_date'     => $first->opname_date ?: $first->period_to,
                'period_from'     => $from,
                'period_to'       => $to,
                'outlet_id'       => $outId,
                'outlet'          => $first->outlet,
                'created_by_name' => $first->creator?->name ?? ($first->user?->name ?? 'Staff Gudang'),
                'approver'        => $first->approver,
                'notes'           => $first->notes,
                'is_closed'       => (bool) $first->is_closed,
                'status'          => $first->is_closed ? 'RELEASED' : 'DRAFT',
                'created_at'      => $first->created_at?->format('Y-m-d H:i:s'),
            ],
            'summary' => [
                'total_items'             => count($detailedItems),
                'items_counted'           => collect($detailedItems)->filter(fn($i) => $i['stok_akhir_aktual'] !== null)->count(),
                'total_theoretical_value' => round($totalTheoreticalValue, 0),
                'total_actual_value'      => round($totalActualValue, 0),
                'total_variance_value'    => round($totalVarianceValue, 0),
                'total_surplus_value'     => round($totalSurplusValue, 0),
                'total_deficit_value'     => round($totalDeficitValue, 0),
                'total_waste_value'       => round($totalWasteValue, 0),
                'status_counts'           => $statusCounts,
            ],
            'items' => $detailedItems,
        ]);
    }

    /**
     * Check if user has Owner, Admin, or Store Manager privilege
     */
    protected function checkIsOwnerOrManager($user): bool
    {
        if (!$user) {
            return false;
        }

        return \App\Models\Role::isOwnerOrManagerRole($user->role_id ?? $user->role);
    }
}
