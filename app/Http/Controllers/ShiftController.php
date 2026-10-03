<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\ReceivablePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    /**
     * List all shifts with optional filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        $query = Shift::with(['user', 'closedByUser', 'creator', 'updater', 'outlet', 'shiftSchedule'])
            ->withCount(['transactions as transactions_count' => function ($q) {
                $q->where('status', 'PAID')->select(DB::raw('COUNT(DISTINCT COALESCE(order_number, CAST(id AS CHAR)))'));
            }])
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($isOutletBounded) {
            $query->where('outlet_id', (int)$user->outlet_id);
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('date')) {
            $d = $request->date;
            $query->where(function ($q) use ($d) {
                $q->whereDate('opened_at', $d)
                  ->orWhereDate('closed_at', $d);
            });
        }

        if ($request->from && $request->to) {
            $from = $request->from;
            $to = $request->to;
            $query->where(function ($q) use ($from, $to) {
                $q->where(function ($sub) use ($from, $to) {
                    $sub->whereDate('opened_at', '>=', $from)
                        ->whereDate('opened_at', '<=', $to);
                })->orWhere(function ($sub) use ($from, $to) {
                    $sub->whereDate('closed_at', '>=', $from)
                        ->whereDate('closed_at', '<=', $to);
                });
            });
        } elseif ($request->from) {
            $from = $request->from;
            $query->where(function ($q) use ($from) {
                $q->whereDate('opened_at', '>=', $from)
                  ->orWhereDate('closed_at', '>=', $from);
            });
        } elseif ($request->to) {
            $to = $request->to;
            $query->where(function ($q) use ($to) {
                $q->whereDate('opened_at', '<=', $to)
                  ->orWhere(function ($sub) use ($to) {
                      $sub->whereNotNull('closed_at')->whereDate('closed_at', '<=', $to);
                  });
            });
        }

        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('shift_id')) {
            $query->where('id', $request->shift_id);
        }

        if ($request->filled('shift_schedule_id')) {
            $query->where('shift_schedule_id', $request->shift_schedule_id);
        }

        if ($request->filled('shift_name')) {
            $query->where('shift_name', 'like', "%{$request->shift_name}%");
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($sub) use ($s) {
                $sub->where('shift_name', 'like', "%{$s}%")
                    ->orWhere('id', 'like', "%{$s}%")
                    ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', "%{$s}%"));
            });
        }

        $shifts = $query->limit(300)->get();
        $shiftIds = $shifts->pluck('id');

        $paidTransactions = Transaction::whereIn('shift_id', $shiftIds)
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->select('shift_id', 'payment_method', 'dp_payment_method', 'total_price', 'amount_paid')
            ->get();

        // Fetch receivable payments collected during these shifts
        $receivablePayments = ReceivablePayment::whereIn('shift_id', $shiftIds)->get();
        $recPaymentsByShift = $receivablePayments->groupBy('shift_id');

        $breakdownByShift = [];
        foreach ($shiftIds as $sId) {
            $breakdownByShift[$sId] = [
                'cash'        => 0.0,
                'qris'        => 0.0,
                'grab'        => 0.0,
                'transfer'    => 0.0,
                'debit'       => 0.0,
                'other'       => 0.0,
                'total_sales' => 0.0,
                'details'     => [],
            ];
        }

        foreach ($paidTransactions as $t) {
            $sId = $t->shift_id;
            if (!isset($breakdownByShift[$sId])) continue;

            $method = strtoupper(trim($t->payment_method ?? 'CASH'));
            $price = (float)$t->total_price;
            $breakdownByShift[$sId]['total_sales'] += $price;

            if ($method === 'CASH' || $method === 'TUNAI') {
                $breakdownByShift[$sId]['cash'] += $price;
                $breakdownByShift[$sId]['details']['CASH'] = ($breakdownByShift[$sId]['details']['CASH'] ?? 0.0) + $price;
            } elseif (str_contains($method, 'QRIS')) {
                $breakdownByShift[$sId]['qris'] += $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'GRAB') || str_contains($method, 'GOFOOD') || str_contains($method, 'SHOPEE') || str_contains($method, 'TIKTOK')) {
                $breakdownByShift[$sId]['grab'] += $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'TRANSFER')) {
                $breakdownByShift[$sId]['transfer'] += $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'DEBIT') || str_contains($method, 'EDC') || str_contains($method, 'CREDIT') || str_contains($method, 'KARTU')) {
                $breakdownByShift[$sId]['debit'] += $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                // If there are NO receivable payment records for this shift, fallback to amount_paid
                $hasRp = isset($recPaymentsByShift[$sId]) && $recPaymentsByShift[$sId]->count() > 0;
                if (!$hasRp) {
                    $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                    $dpPaid = (float)($t->amount_paid ?? 0);
                    if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                        $breakdownByShift[$sId]['cash'] += $dpPaid;
                    } else {
                        $breakdownByShift[$sId]['other'] += $dpPaid;
                    }
                    if ($dpPaid > 0) {
                        $breakdownByShift[$sId]['details'][$dpMethod] = ($breakdownByShift[$sId]['details'][$dpMethod] ?? 0.0) + $dpPaid;
                    }
                }
            } else {
                $breakdownByShift[$sId]['other'] += $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            }
        }

        // Add collections from kasbon payments received during each shift
        foreach ($recPaymentsByShift as $sId => $rpList) {
            if (!isset($breakdownByShift[$sId])) continue;
            foreach ($rpList as $rp) {
                $rpMethod = strtoupper(trim($rp->payment_method ?? 'CASH'));
                $rpAmt = (float)$rp->amount;

                if (!isset($breakdownByShift[$sId]['details'][$rpMethod])) {
                    $breakdownByShift[$sId]['details'][$rpMethod] = 0.0;
                }
                $breakdownByShift[$sId]['details'][$rpMethod] += $rpAmt;

                if ($rpMethod === 'CASH' || $rpMethod === 'TUNAI') {
                    $breakdownByShift[$sId]['cash'] += $rpAmt;
                } elseif (str_contains($rpMethod, 'QRIS')) {
                    $breakdownByShift[$sId]['qris'] += $rpAmt;
                } elseif (str_contains($rpMethod, 'TRANSFER')) {
                    $breakdownByShift[$sId]['transfer'] += $rpAmt;
                } elseif (str_contains($rpMethod, 'DEBIT') || str_contains($rpMethod, 'EDC') || str_contains($rpMethod, 'CREDIT') || str_contains($rpMethod, 'KARTU')) {
                    $breakdownByShift[$sId]['debit'] += $rpAmt;
                } else {
                    $breakdownByShift[$sId]['other'] += $rpAmt;
                }
            }
        }

        // Ensure total_sales is at least the sum of payments received
        foreach ($shiftIds as $sId) {
            $totalPayments = $breakdownByShift[$sId]['cash'] +
                             $breakdownByShift[$sId]['qris'] +
                             $breakdownByShift[$sId]['grab'] +
                             $breakdownByShift[$sId]['transfer'] +
                             $breakdownByShift[$sId]['debit'] +
                             $breakdownByShift[$sId]['other'];
            if ($breakdownByShift[$sId]['total_sales'] < $totalPayments) {
                $breakdownByShift[$sId]['total_sales'] = $totalPayments;
            }
        }

        // Fetch relevant closed shifts for the outlets in $shifts to calculate selisih modal awal vs kas fisik akhir shift sebelumnya
        $outletIds = $shifts->pluck('outlet_id')->unique()->filter()->values();
        $allClosedShifts = Shift::whereIn('outlet_id', $outletIds)
            ->where('status', 'CLOSED')
            ->whereNotNull('closing_cash')
            ->orderBy('opened_at', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'outlet_id', 'shift_name', 'user_id', 'opened_at', 'closed_at', 'closing_cash', 'initial_cash']);

        foreach ($shifts as $s) {
            $bd = $breakdownByShift[$s->id] ?? [
                'cash'        => 0.0,
                'qris'        => 0.0,
                'grab'        => 0.0,
                'transfer'    => 0.0,
                'debit'       => 0.0,
                'other'       => 0.0,
                'total_sales' => 0.0,
                'details'     => [],
            ];
            $s->cash_sales = $bd['cash'];
            $s->qris_sales = $bd['qris'];
            $s->grab_sales = $bd['grab'];
            $s->transfer_sales = $bd['transfer'];
            $s->debit_sales = $bd['debit'];
            $s->other_sales = $bd['other'];
            $s->payment_breakdown = $bd['details'];
            $s->system_cash = $bd['total_sales'];

            // Find latest closed shift on the same outlet prior to this shift
            $prev = $allClosedShifts
                ->where('outlet_id', $s->outlet_id)
                ->filter(function ($cs) use ($s) {
                    if ($cs->id === $s->id) return false;
                    if ($cs->opened_at && $s->opened_at) {
                        if ($cs->opened_at < $s->opened_at) return true;
                        if ($cs->opened_at == $s->opened_at) return $cs->id < $s->id;
                    }
                    return $cs->id < $s->id;
                })
                ->last();

            if ($prev && $prev->closing_cash !== null) {
                $prevClosing = (float)$prev->closing_cash;
                $s->previous_shift_id = $prev->id;
                $s->previous_shift_name = $prev->shift_name;
                $s->previous_shift_closing_cash = $prevClosing;
                $s->initial_cash_difference = round((float)$s->initial_cash - $prevClosing, 2);
            } else {
                $s->previous_shift_id = null;
                $s->previous_shift_name = null;
                $s->previous_shift_closing_cash = null;
                $s->initial_cash_difference = null;
            }
        }

        return response()->json($shifts);
    }

    /**
     * Get currently active / open shift.
     */
    public function active(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);
        $query = Shift::with(['user', 'outlet', 'shiftSchedule'])->where('status', 'OPEN');
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }
        $shift = $query->orderByDesc('opened_at')->first();

        if (!$shift) {
            $lastShift = Shift::with(['user', 'closedByUser', 'outlet', 'shiftSchedule'])
                ->where('status', 'CLOSED')
                ->when($outletId && $outletId !== 'ALL' && $outletId !== 'all', fn($q) => $q->where('outlet_id', $outletId))
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->first();

            $lastClosedData = $lastShift ? [
                'id'              => $lastShift->id,
                'shift_name'      => $lastShift->shift_name,
                'outlet_id'       => $lastShift->outlet_id,
                'outlet_name'     => $lastShift->outlet?->name,
                'cashier_name'    => $lastShift->user?->name,
                'closed_by_name'  => $lastShift->closedByUser?->name ?? $lastShift->user?->name,
                'opened_at'       => $lastShift->opened_at,
                'closed_at'       => $lastShift->closed_at,
                'initial_cash'    => (float)$lastShift->initial_cash,
                'closing_cash'    => (float)$lastShift->closing_cash,
                'system_cash'     => (float)$lastShift->system_cash,
                'cash_difference' => (float)$lastShift->cash_difference,
                'notes'           => $lastShift->notes,
            ] : null;

            return response()->json([
                'shift'             => null,
                'last_closed_shift' => $lastClosedData,
            ]);
        }

        $paidTransactions = $shift->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->get();
        $totalTransactions = $paidTransactions->unique(fn($t) => $t->order_number ?: ('trx_' . $t->id))->count();
        $totalSales = (float)$paidTransactions->sum('total_price');

        // Fetch receivable payments collected during this shift
        $shiftRecPayments = $this->getShiftReceivablePayments($shift);
        $hasShiftRecPayments = $shiftRecPayments->count() > 0;

        $cashSales = 0.0;
        $qrisSales = 0.0;
        $grabSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $otherSales = 0.0;
        $nonCashSales = 0.0;
        $paymentBreakdown = [];

        foreach ($paidTransactions as $t) {
            $method = strtoupper(trim($t->payment_method ?? 'CASH'));
            $price = (float)$t->total_price;

            if ($method === 'CASH' || $method === 'TUNAI') {
                $cashSales += $price;
                $paymentBreakdown['CASH'] = ($paymentBreakdown['CASH'] ?? 0.0) + $price;
            } elseif (str_contains($method, 'QRIS')) {
                $qrisSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'GRAB') || str_contains($method, 'GOFOOD') || str_contains($method, 'SHOPEE') || str_contains($method, 'TIKTOK')) {
                $grabSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'TRANSFER')) {
                $transferSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'DEBIT') || str_contains($method, 'EDC') || str_contains($method, 'CREDIT') || str_contains($method, 'KARTU')) {
                $debitSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                if (!$hasShiftRecPayments) {
                    $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                    $dpPaid = (float)($t->amount_paid ?? 0);
                    if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                        $cashSales += $dpPaid;
                    } else {
                        $otherSales += $dpPaid;
                        $nonCashSales += $dpPaid;
                    }
                    if ($dpPaid > 0) {
                        $paymentBreakdown[$dpMethod] = ($paymentBreakdown[$dpMethod] ?? 0.0) + $dpPaid;
                    }
                }
            } else {
                $otherSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            }
        }

        // Add collections from kasbon payments received during this shift
        foreach ($shiftRecPayments as $rp) {
            $rpMethod = strtoupper(trim($rp->payment_method ?? 'CASH'));
            $rpPrice = (float)$rp->amount;
            $paymentBreakdown[$rpMethod] = ($paymentBreakdown[$rpMethod] ?? 0.0) + $rpPrice;

            if ($rpMethod === 'CASH' || $rpMethod === 'TUNAI') {
                $cashSales += $rpPrice;
            } elseif (str_contains($rpMethod, 'QRIS')) {
                $qrisSales += $rpPrice;
                $nonCashSales += $rpPrice;
            } elseif (str_contains($rpMethod, 'TRANSFER')) {
                $transferSales += $rpPrice;
                $nonCashSales += $rpPrice;
            } elseif (str_contains($rpMethod, 'DEBIT') || str_contains($rpMethod, 'EDC') || str_contains($rpMethod, 'CREDIT') || str_contains($rpMethod, 'KARTU')) {
                $debitSales += $rpPrice;
                $nonCashSales += $rpPrice;
            } else {
                $otherSales += $rpPrice;
                $nonCashSales += $rpPrice;
            }
        }

        $totalPaymentsReceived = $cashSales + $nonCashSales;
        if ($totalSales < $totalPaymentsReceived) {
            $totalSales = $totalPaymentsReceived;
        }

        $expData = $this->getShiftExpenses($shift);
        $expectedCash = max(0, (float)$shift->initial_cash + $cashSales - $expData['cash_expenses']);

        $prevShiftForActive = Shift::where('outlet_id', $shift->outlet_id)
            ->where('status', 'CLOSED')
            ->whereNotNull('closing_cash')
            ->where(function ($q) use ($shift) {
                if ($shift->opened_at) {
                    $q->where('opened_at', '<', $shift->opened_at)
                      ->orWhere(function ($sub) use ($shift) {
                          $sub->where('opened_at', '=', $shift->opened_at)
                              ->where('id', '<', $shift->id);
                      });
                } else {
                    $q->where('id', '<', $shift->id);
                }
            })
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->first();

        if ($prevShiftForActive && $prevShiftForActive->closing_cash !== null) {
            $shift->previous_shift_id = $prevShiftForActive->id;
            $shift->previous_shift_name = $prevShiftForActive->shift_name;
            $shift->previous_shift_closing_cash = (float)$prevShiftForActive->closing_cash;
            $shift->initial_cash_difference = round((float)$shift->initial_cash - (float)$prevShiftForActive->closing_cash, 2);
        } else {
            $shift->previous_shift_id = null;
            $shift->previous_shift_name = null;
            $shift->previous_shift_closing_cash = null;
            $shift->initial_cash_difference = null;
        }

        return response()->json([
            'shift'              => $shift,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'cash_sales'         => $cashSales,
            'qris_sales'         => $qrisSales,
            'grab_sales'         => $grabSales,
            'transfer_sales'     => $transferSales,
            'debit_sales'        => $debitSales,
            'other_sales'        => $otherSales,
            'non_cash_sales'     => $nonCashSales,
            'payment_breakdown'  => $paymentBreakdown,
            'expenses'           => $expData['expenses'],
            'total_expenses'     => $expData['total_expenses'],
            'cash_expenses'      => $expData['cash_expenses'],
            'non_cash_expenses'  => $expData['non_cash_expenses'],
            'expected_cash'      => $expectedCash,
        ]);
    }

    /**
     * Get the most recently closed shift for an outlet (to compare initial cash vs previous real closing cash).
     */
    public function lastClosed(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $query = Shift::with(['user', 'closedByUser', 'outlet', 'shiftSchedule'])
            ->where('status', 'CLOSED');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        $lastShift = $query->orderByDesc('closed_at')->orderByDesc('id')->first();

        if (!$lastShift) {
            return response()->json(null);
        }

        return response()->json([
            'id'              => $lastShift->id,
            'shift_name'      => $lastShift->shift_name,
            'outlet_id'       => $lastShift->outlet_id,
            'outlet_name'     => $lastShift->outlet?->name,
            'user_id'         => $lastShift->user_id,
            'cashier_name'    => $lastShift->user?->name,
            'closed_by'       => $lastShift->closed_by,
            'closed_by_name'  => $lastShift->closedByUser?->name ?? $lastShift->user?->name,
            'opened_at'       => $lastShift->opened_at,
            'closed_at'       => $lastShift->closed_at,
            'initial_cash'    => (float)$lastShift->initial_cash,
            'closing_cash'    => (float)$lastShift->closing_cash,
            'system_cash'     => (float)$lastShift->system_cash,
            'cash_difference' => (float)$lastShift->cash_difference,
            'notes'           => $lastShift->notes,
        ]);
    }

    /**
     * Open a new shift.
     */
    public function open(Request $request)
    {
        $data = $request->validate([
            'shift_name'        => 'nullable|string|max:100',
            'shift_schedule_id' => 'nullable|integer',
            'initial_cash'      => 'nullable|numeric|min:0',
            'notes'             => 'nullable|string|max:500',
            'outlet_id'         => 'nullable|integer',
        ]);

        $user = $request->user();
        $businessId = (int) $user->business_id;

        $outletId = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : ($user->outlet_id ?? 1);

        // If user is Owner Outlet or Pegawai with assigned outlet, lock strictly to their outlet
        if (($user->isOwnerOutlet() || $user->isPegawai()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        }

        // Single-company strict validation: verify outlet belongs to this business
        $outlet = Outlet::where('id', $outletId)->where('business_id', $businessId)->first();
        if (!$outlet) {
            return response()->json([
                'message' => 'Outlet tidak valid atau tidak terdaftar dalam perusahaan Anda.'
            ], 422);
        }

        // Check if there is already an open shift for this outlet
        $existing = Shift::where('status', 'OPEN')
            ->where('business_id', $businessId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => "Masih ada shift yang aktif di outlet ini (#{$existing->id} - {$existing->shift_name}). Silakan closing shift tersebut terlebih dahulu.",
                'active_shift' => $existing,
            ], 422);
        }

        $shiftScheduleId = null;
        $shiftName = $data['shift_name'] ?? null;
        $isOwner = $user->isOwnerBisnis() || $user->isSuperadminPlatform();

        // 1. If explicit shift_schedule_id provided
        if (!empty($data['shift_schedule_id'])) {
            $schedule = ShiftSchedule::where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->find($data['shift_schedule_id']);

            if (!$schedule) {
                return response()->json([
                    'message' => 'Jadwal shift yang dipilih tidak valid atau tidak sesuai dengan outlet perusahaan Anda.'
                ], 422);
            }

            // Strict enforcement: only assigned cashiers or Owners can open
            if ($schedule->is_strict && !$isOwner) {
                $assignedIds = $schedule->assigned_user_ids ?? [];
                if (!in_array($user->id, $assignedIds)) {
                    return response()->json([
                        'message' => "Akses Ditolak: Anda ({$user->name}) tidak dijadwalkan pada '{$schedule->shift_name}'. Berdasarkan pengaturan Owner, shift ini hanya boleh dibuka oleh kasir yang ditugaskan."
                    ], 403);
                }
            }

            $shiftScheduleId = $schedule->id;
            $shiftName = $schedule->shift_name;
        } else {
            // 2. If no shift_schedule_id was sent, check if shift_name matches any scheduled shift in this outlet
            if ($shiftName) {
                $matchedSchedule = ShiftSchedule::where('business_id', $businessId)
                    ->where('outlet_id', $outletId)
                    ->where('shift_name', $shiftName)
                    ->first();

                if ($matchedSchedule) {
                    if ($matchedSchedule->is_strict && !$isOwner) {
                        $assignedIds = $matchedSchedule->assigned_user_ids ?? [];
                        if (!in_array($user->id, $assignedIds)) {
                            return response()->json([
                                'message' => "Akses Ditolak: Anda ({$user->name}) tidak dijadwalkan pada '{$matchedSchedule->shift_name}'. Berdasarkan pengaturan Owner, shift ini hanya boleh dibuka oleh kasir yang ditugaskan."
                            ], 403);
                        }
                    }
                    $shiftScheduleId = $matchedSchedule->id;
                }
            } else {
                $shiftName = 'Shift 1 (Pagi)';
            }
        }

        $shift = Shift::create([
            'business_id'       => $businessId,
            'outlet_id'         => $outletId,
            'shift_schedule_id' => $shiftScheduleId,
            'shift_name'        => $shiftName,
            'user_id'           => $user->id,
            'created_by'        => $user->id,
            'opened_at'         => now(),
            'initial_cash'      => $data['initial_cash'] ?? 0,
            'system_cash'       => 0,
            'status'            => 'OPEN',
            'notes'             => $data['notes'] ?? null,
        ]);

        $shift->load(['user', 'outlet', 'creator', 'updater', 'shiftSchedule']);

        return response()->json($shift, 201);
    }

    /**
     * Get receivable payments (pelunasan kasbon) received during this shift.
     */
    protected function getShiftReceivablePayments(Shift $shift)
    {
        return ReceivablePayment::with(['receivable.customer', 'receiver'])
            ->where(function ($query) use ($shift) {
                $query->where('shift_id', $shift->id)
                      ->orWhere(function ($q) use ($shift) {
                          $q->whereNull('shift_id')
                            ->where('outlet_id', $shift->outlet_id);
                          if ($shift->opened_at && $shift->closed_at) {
                              $q->whereBetween('created_at', [$shift->opened_at, $shift->closed_at]);
                          } elseif ($shift->opened_at) {
                              $q->where('created_at', '>=', $shift->opened_at);
                          } else {
                              $q->whereDate('payment_date', now()->toDateString());
                          }
                      });
            })
            ->get();
    }

    /**
     * Get operating expenses and cash deductions associated with a shift.
     */
    protected function getShiftExpenses(Shift $shift): array
    {
        $openedAt = $shift->opened_at;
        $closedAt = $shift->closed_at;
        $openedDate = $openedAt ? $openedAt->toDateString() : now()->toDateString();
        $closedDate = $closedAt ? $closedAt->toDateString() : now()->toDateString();

        // 1. Operating Expenses (OPEX)
        $opexQuery = \App\Models\OperatingExpense::with(['user', 'outlet'])
            ->where(function ($q) use ($shift) {
                $q->where('outlet_id', $shift->outlet_id)
                  ->orWhereNull('outlet_id');
            });

        if ($shift->business_id) {
            $opexQuery->where('business_id', $shift->business_id);
        }

        $opexQuery->where(function ($q) use ($openedAt, $closedAt, $openedDate, $closedDate) {
            if ($openedAt && $closedAt) {
                $q->where(function ($sub) use ($openedAt, $closedAt) {
                    $sub->where('created_at', '>=', $openedAt)
                        ->where('created_at', '<=', $closedAt);
                })->orWhere(function ($sub) use ($openedDate, $closedDate) {
                    $sub->whereBetween('date', [$openedDate, $closedDate]);
                });
            } elseif ($openedAt) {
                $q->where(function ($sub) use ($openedAt) {
                    $sub->where('created_at', '>=', $openedAt);
                })->orWhere(function ($sub) use ($openedDate) {
                    $sub->where('date', '>=', $openedDate);
                });
            } else {
                $q->whereDate('date', now()->toDateString());
            }
        });

        $opexList = $opexQuery->orderBy('created_at', 'asc')->get();

        $expenses = [];
        $totalExpenses = 0.0;
        $cashExpenses = 0.0;
        $nonCashExpenses = 0.0;

        foreach ($opexList as $exp) {
            $amt = (float)$exp->amount;
            $method = strtoupper(trim($exp->payment_method ?? 'CASH'));
            $isCash = in_array($method, ['CASH', 'TUNAI', 'PETTY_CASH', 'KAS_KECIL']) ||
                      str_contains($method, 'CASH') ||
                      str_contains($method, 'TUNAI') ||
                      str_contains($method, 'PETTY');

            $totalExpenses += $amt;
            if ($isCash) {
                $cashExpenses += $amt;
            } else {
                $nonCashExpenses += $amt;
            }

            $desc = $exp->name ?: ($exp->category_label ?? 'Biaya Operasional');
            $expenses[] = [
                'id'                   => $exp->id,
                'source'               => 'OPERATING_EXPENSE',
                'expense_no'           => $exp->expense_no,
                'date'                 => $exp->date ? (is_string($exp->date) ? substr($exp->date, 0, 10) : $exp->date->format('Y-m-d')) : null,
                'time'                 => $exp->created_at ? $exp->created_at->format('H:i') : '-',
                'category'             => $exp->category,
                'category_label'       => $exp->category_label ?? $exp->category,
                'name'                 => $desc,
                'description'          => $desc,
                'amount'               => $amt,
                'payment_method'       => $exp->payment_method ?? 'CASH',
                'payment_method_label' => $exp->payment_method_label ?? ($isCash ? 'Tunai / Kas di Laci' : 'Transfer Bank'),
                'is_cash'              => $isCash,
                'notes'                => $exp->notes,
                'user_name'            => $exp->user_name ?? ($exp->user?->name ?? 'Kasir'),
            ];
        }

        // 2. Cash Transactions (Type OUT for CASH_DRAWER / PETTY_CASH)
        $cashTrxQuery = \App\Models\CashTransaction::with(['user', 'outlet'])
            ->where('type', 'OUT')
            ->whereIn('account', ['CASH_DRAWER', 'PETTY_CASH'])
            ->where('category', '!=', 'SETORAN_KASIR')
            ->where(function ($q) use ($shift) {
                $q->where('outlet_id', $shift->outlet_id)
                  ->orWhereNull('outlet_id');
            });

        if ($shift->business_id) {
            $cashTrxQuery->where('business_id', $shift->business_id);
        }

        $cashTrxQuery->where(function ($q) use ($openedAt, $closedAt, $openedDate, $closedDate) {
            if ($openedAt && $closedAt) {
                $q->where(function ($sub) use ($openedAt, $closedAt) {
                    $sub->where('created_at', '>=', $openedAt)
                        ->where('created_at', '<=', $closedAt);
                })->orWhere(function ($sub) use ($openedDate, $closedDate) {
                    $sub->whereBetween('date', [$openedDate, $closedDate]);
                });
            } elseif ($openedAt) {
                $q->where(function ($sub) use ($openedAt) {
                    $sub->where('created_at', '>=', $openedAt);
                })->orWhere(function ($sub) use ($openedDate) {
                    $sub->where('date', '>=', $openedDate);
                });
            } else {
                $q->whereDate('date', now()->toDateString());
            }
        });

        $cashTrxList = $cashTrxQuery->orderBy('created_at', 'asc')->get();

        foreach ($cashTrxList as $ctx) {
            // Avoid duplicate if already captured from operating_expenses
            $isDuplicate = false;
            $ctxDate = $ctx->date ? (is_string($ctx->date) ? substr($ctx->date, 0, 10) : $ctx->date->format('Y-m-d')) : null;
            foreach ($expenses as $existing) {
                if ($existing['source'] === 'OPERATING_EXPENSE' &&
                    abs($existing['amount'] - (float)$ctx->amount) < 0.01 &&
                    $existing['date'] === $ctxDate) {
                    $isDuplicate = true;
                    break;
                }
            }
            if ($isDuplicate) continue;

            $amt = (float)$ctx->amount;
            $totalExpenses += $amt;
            $cashExpenses += $amt;

            $desc = $ctx->name ?: 'Pengeluaran Kas Kecil';
            $expenses[] = [
                'id'                   => $ctx->id,
                'source'               => 'CASH_TRANSACTION',
                'expense_no'           => $ctx->transaction_no,
                'date'                 => $ctxDate,
                'time'                 => $ctx->created_at ? $ctx->created_at->format('H:i') : '-',
                'category'             => $ctx->category,
                'category_label'       => $ctx->category_label ?? $ctx->category,
                'name'                 => $desc,
                'description'          => $desc,
                'amount'               => $amt,
                'payment_method'       => 'CASH',
                'payment_method_label' => 'Kas di Laci (Tunai)',
                'is_cash'              => true,
                'notes'                => $ctx->notes,
                'user_name'            => $ctx->user_name ?? ($ctx->user?->name ?? 'Kasir'),
            ];
        }

        return [
            'expenses'          => $expenses,
            'total_expenses'    => $totalExpenses,
            'cash_expenses'     => $cashExpenses,
            'non_cash_expenses' => $nonCashExpenses,
        ];
    }

    /**
     * Get real-time summary of sales & theoretical ingredient consumption for a shift.
     */
    public function summary(Shift $shift)
    {
        $user = auth()->user() ?? request()->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk mengelola shift di cabang outlet lain.'], 403);
            }
        }

        $shift->load(['user', 'closedByUser']);

        $paidTransactions = $shift->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->get();
        $totalTransactions = $paidTransactions->unique(fn($t) => $t->order_number ?: ('trx_' . $t->id))->count();
        $totalSales = (float)$paidTransactions->sum('total_price');

        // Fetch receivable payments collected during this shift
        $shiftRecPayments = $this->getShiftReceivablePayments($shift);
        $hasShiftRecPayments = $shiftRecPayments->count() > 0;

        $cashSales = 0.0;
        $qrisSales = 0.0;
        $grabSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $otherSales = 0.0;
        $nonCashSales = 0.0;
        $paymentBreakdown = [];

        foreach ($paidTransactions as $t) {
            $method = strtoupper(trim($t->payment_method ?? 'CASH'));
            $price = (float)$t->total_price;

            if ($method === 'CASH' || $method === 'TUNAI') {
                $cashSales += $price;
                $paymentBreakdown['CASH'] = ($paymentBreakdown['CASH'] ?? 0.0) + $price;
            } elseif (str_contains($method, 'QRIS')) {
                $qrisSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'GRAB') || str_contains($method, 'GOFOOD') || str_contains($method, 'SHOPEE') || str_contains($method, 'TIKTOK')) {
                $grabSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'TRANSFER')) {
                $transferSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'DEBIT') || str_contains($method, 'EDC') || str_contains($method, 'CREDIT') || str_contains($method, 'KARTU')) {
                $debitSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                if (!$hasShiftRecPayments) {
                    $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                    $dpPaid = (float)($t->amount_paid ?? 0);
                    if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                        $cashSales += $dpPaid;
                    } else {
                        $otherSales += $dpPaid;
                        $nonCashSales += $dpPaid;
                    }
                    if ($dpPaid > 0) {
                        $paymentBreakdown[$dpMethod] = ($paymentBreakdown[$dpMethod] ?? 0.0) + $dpPaid;
                    }
                }
            } else {
                $otherSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            }
        }

        // Add collections from kasbon payments received during this shift
        foreach ($shiftRecPayments as $rp) {
            $rpMethod = strtoupper(trim($rp->payment_method ?? 'CASH'));
            $rpPrice = (float)$rp->amount;
            $paymentBreakdown[$rpMethod] = ($paymentBreakdown[$rpMethod] ?? 0.0) + $rpPrice;

            if ($rpMethod === 'CASH' || $rpMethod === 'TUNAI') {
                $cashSales += $rpPrice;
            } elseif (str_contains($rpMethod, 'QRIS')) {
                $qrisSales += $rpPrice;
                $nonCashSales += $rpPrice;
            } elseif (str_contains($rpMethod, 'TRANSFER')) {
                $transferSales += $rpPrice;
                $nonCashSales += $rpPrice;
            } elseif (str_contains($rpMethod, 'DEBIT') || str_contains($rpMethod, 'EDC') || str_contains($rpMethod, 'CREDIT') || str_contains($rpMethod, 'KARTU')) {
                $debitSales += $rpPrice;
                $nonCashSales += $rpPrice;
            } else {
                $otherSales += $rpPrice;
                $nonCashSales += $rpPrice;
            }
        }

        $totalPaymentsReceived = $cashSales + $nonCashSales;
        if ($totalSales < $totalPaymentsReceived) {
            $totalSales = $totalPaymentsReceived;
        }

        $expData = $this->getShiftExpenses($shift);
        $expectedCash = max(0, (float)$shift->initial_cash + $cashSales - $expData['cash_expenses']);

        // Group actual menus sold (exclude dummy equal split installment rows, include SPLIT_CLOSED actual items)
        $actualMenuTransactions = $shift->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->with('menu')
            ->get();

        $menuSummary = [];
        foreach ($actualMenuTransactions as $t) {
            $menuId = $t->menu_id;
            if (!isset($menuSummary[$menuId])) {
                $menuSummary[$menuId] = [
                    'menu_id'   => $menuId,
                    'menu_name' => $t->menu?->name ?? 'Menu #'.$menuId,
                    'qty'       => 0,
                    'total'     => 0.0,
                ];
            }
            $menuSummary[$menuId]['qty'] += (int)$t->qty;
            $menuSummary[$menuId]['total'] += (float)$t->total_price;
        }

        // Calculate ingredient usage
        $ingredientUsages = $shift->calculateIngredientUsage();

        // Active Open Bills on this outlet (grouped by order_number & customer_name)
        $openBills = Transaction::where('outlet_id', $shift->outlet_id)
            ->where('status', 'HOLD')
            ->select(
                'order_number',
                'customer_name',
                'notes',
                DB::raw('SUM(total_price) as total_amount'),
                DB::raw('SUM(qty) as total_items'),
                DB::raw('MIN(created_at) as created_at')
            )
            ->groupBy('order_number', 'customer_name', 'notes')
            ->get();

        $openBillsCount = $openBills->count();
        $openBillsTotal = (float)$openBills->sum('total_amount');

        $ordersGrouped = [];
        foreach ($paidTransactions as $t) {
            $ordNum = $t->order_number ?: ('TRX-' . $t->id);
            if (!isset($ordersGrouped[$ordNum])) {
                $ordersGrouped[$ordNum] = [
                    'order_number'    => $ordNum,
                    'time'            => $t->created_at ? $t->created_at->format('H:i') : '-',
                    'total_price'     => 0.0,
                    'total_items'     => 0,
                    'payment_method'  => $t->payment_method ?? 'CASH',
                    'customer_name'   => $t->customer_name ?? null,
                ];
            }
            $ordersGrouped[$ordNum]['total_price'] += (float)$t->total_price;
            $ordersGrouped[$ordNum]['total_items'] += (int)$t->qty;
        }

        foreach ($shiftRecPayments as $rp) {
            $rec = $rp->receivable;
            $payNo = $rp->payment_no ?: ('PAY-' . $rp->id);
            $ordersGrouped[$payNo] = [
                'order_number'    => $payNo . ' (' . ($rec?->order_number ?: $rec?->receivable_no ?: 'Kasbon') . ')',
                'time'            => $rp->created_at ? $rp->created_at->format('H:i') : '-',
                'total_price'     => (float)$rp->amount,
                'total_items'     => 1,
                'payment_method'  => strtoupper($rp->payment_method ?: 'CASH'),
                'customer_name'   => 'Pelunasan: ' . ($rec?->customer_name ?: 'Pelanggan'),
            ];
        }

        $ordersList = array_values($ordersGrouped);
        $orderNumbers = array_keys($ordersGrouped);
        $orderRange = '-';
        if (count($orderNumbers) === 1) {
            $orderRange = $orderNumbers[0];
        } elseif (count($orderNumbers) > 1) {
            $orderRange = reset($orderNumbers) . ' s/d ' . end($orderNumbers);
        }

        return response()->json([
            'shift'              => $shift,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'cash_sales'         => $cashSales,
            'qris_sales'         => $qrisSales,
            'grab_sales'         => $grabSales,
            'transfer_sales'     => $transferSales,
            'debit_sales'        => $debitSales,
            'other_sales'        => $otherSales,
            'non_cash_sales'     => $nonCashSales,
            'payment_breakdown'  => $paymentBreakdown,
            'expenses'           => $expData['expenses'],
            'total_expenses'     => $expData['total_expenses'],
            'cash_expenses'      => $expData['cash_expenses'],
            'non_cash_expenses'  => $expData['non_cash_expenses'],
            'expected_cash'      => $expectedCash,
            'menus_sold'         => array_values($menuSummary),
            'ingredient_usages'  => $ingredientUsages,
            'orders'             => $ordersList,
            'order_numbers'      => $orderNumbers,
            'order_number_range' => $orderRange,
            'open_bills_count'   => $openBillsCount,
            'open_bills_total'   => $openBillsTotal,
            'open_bills'         => $openBills,
        ]);
    }

    /**
     * Close an active shift and post aggregated stock movements.
     */
    public function close(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk menutup shift di cabang outlet lain.'], 403);
            }
        }

        if ($shift->status !== 'OPEN') {
            return response()->json(['message' => 'Shift ini sudah ditutup sebelumnya.'], 422);
        }

        // Check active OPEN BILLS in HOLD status for this outlet
        $openBills = Transaction::where('outlet_id', $shift->outlet_id)
            ->where('status', 'HOLD')
            ->select(
                'order_number',
                'customer_name',
                'notes',
                DB::raw('SUM(total_price) as total_amount'),
                DB::raw('SUM(qty) as total_items')
            )
            ->groupBy('order_number', 'customer_name', 'notes')
            ->get();

        $openBillsCount = $openBills->count();
        $openBillsTotal = (float)$openBills->sum('total_amount');
        $allowCarryOver = $request->boolean('allow_carry_over');

        if ($openBillsCount > 0 && !$allowCarryOver) {
            return response()->json([
                'has_open_bills'   => true,
                'open_bills_count' => $openBillsCount,
                'open_bills_total' => $openBillsTotal,
                'open_bills'       => $openBills,
                'message'          => "Masih ada {$openBillsCount} tagihan terbuka atas nama pelanggan (Open Bill) senilai Rp " . number_format($openBillsTotal, 0, ',', '.') . " di outlet ini. Anda dapat mengalihkan tagihan ke shift berikutnya atau menyelesaikan pembayarannya terlebih dahulu.",
            ], 422);
        }

        $data = $request->validate([
            'closing_cash' => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
        ]);

        $result = DB::transaction(function () use ($request, $shift, $data, $openBillsCount, $openBillsTotal, $allowCarryOver) {
            $paidTransactions = $shift->transactions()
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('status', 'PAID')
                            ->where(function ($inner) {
                                $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                            });
                    })->orWhere('status', 'SPLIT_CLOSED');
                })
                ->get();
            $systemSales = (float)$paidTransactions->sum('total_price');

            // Include collections from kasbon payments received during this shift
            $shiftRecPayments = $this->getShiftReceivablePayments($shift);
            $hasShiftRecPayments = $shiftRecPayments->count() > 0;

            $cashSales = 0.0;
            $nonCashSales = 0.0;
            foreach ($paidTransactions as $t) {
                $method = strtoupper(trim($t->payment_method ?? 'CASH'));
                if ($method === 'CASH' || $method === 'TUNAI') {
                    $cashSales += (float)$t->total_price;
                } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                    if (!$hasShiftRecPayments) {
                        $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                        $dpPaid = (float)($t->amount_paid ?? 0);
                        if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                            $cashSales += $dpPaid;
                        } else {
                            $nonCashSales += $dpPaid;
                        }
                    }
                } else {
                    $nonCashSales += (float)$t->total_price;
                }
            }

            foreach ($shiftRecPayments as $rp) {
                $rpMethod = strtoupper(trim($rp->payment_method ?? 'CASH'));
                $rpPrice = (float)$rp->amount;
                if ($rpMethod === 'CASH' || $rpMethod === 'TUNAI') {
                    $cashSales += $rpPrice;
                } else {
                    $nonCashSales += $rpPrice;
                }
            }

            $totalPaymentsReceived = $cashSales + $nonCashSales;
            if ($systemSales < $totalPaymentsReceived) {
                $systemSales = $totalPaymentsReceived;
            }

            $expData = $this->getShiftExpenses($shift);
            $closingCash = (float)$data['closing_cash'];
            $expectedCash = max(0, (float)$shift->initial_cash + $cashSales - $expData['cash_expenses']);
            $cashDiff = $closingCash - $expectedCash;

            // 1. Calculate aggregated theoretical ingredient usage
            $ingredientUsages = $shift->calculateIngredientUsage();
            $createdMovements = [];

            // 2. Post 1 StockMovement per ingredient with type SALE_USAGE
            foreach ($ingredientUsages as $item) {
                if ($item['total_qty'] <= 0) continue;

                $m = StockMovement::create([
                    'date'          => now()->toDateString(),
                    'ingredient_id' => $item['ingredient_id'],
                    'type'          => 'SALE_USAGE',
                    'qty'           => $item['total_qty'],
                    'note'          => "Closing Shift #{$shift->id} ({$shift->shift_name}) - {$shift->user->name}",
                    'shift_id'      => $shift->id,
                    'outlet_id'     => $shift->outlet_id ?? $request->user()->outlet_id ?? 1,
                    'user_id'       => $request->user()->id,
                    'created_by'    => $request->user()->id,
                ]);
                $createdMovements[] = $m;
            }

            // 3. Update shift to CLOSED with carry-over notes if any
            $combinedNotes = $shift->notes;
            if ($openBillsCount > 0 && $allowCarryOver) {
                $carryNote = "Open Bill Dialihkan ke Shift Berikutnya: {$openBillsCount} tagihan pelanggan (Rp " . number_format($openBillsTotal, 0, ',', '.') . ")";
                $combinedNotes = $combinedNotes ? $combinedNotes . "\n" . $carryNote : $carryNote;
            }
            if (!empty($data['notes'])) {
                $combinedNotes = $combinedNotes ? $combinedNotes . "\nClosing: " . $data['notes'] : $data['notes'];
            }

            $shift->update([
                'status'          => 'CLOSED',
                'closed_at'       => now(),
                'system_cash'     => $systemSales,
                'closing_cash'    => $closingCash,
                'cash_difference' => $cashDiff,
                'closed_by'       => $request->user()->id,
                'updated_by'      => $request->user()->id,
                'notes'           => $combinedNotes,
            ]);

            return [
                'message'          => 'Shift berhasil ditutup.' . ($openBillsCount > 0 ? " {$openBillsCount} tagihan pelanggan dialihkan ke shift berikutnya." : ''),
                'shift'            => $shift->fresh(['user', 'closedByUser', 'creator', 'updater']),
                'movements_count'  => count($createdMovements),
                'total_sales'      => $systemSales,
                'cash_sales'       => $cashSales,
                'non_cash_sales'   => $nonCashSales,
                'expenses'         => $expData['expenses'],
                'total_expenses'   => $expData['total_expenses'],
                'cash_expenses'    => $expData['cash_expenses'],
                'non_cash_expenses'=> $expData['non_cash_expenses'],
                'expected_cash'    => $expectedCash,
                'cash_difference'  => $cashDiff,
                'carry_over_count' => $openBillsCount,
                'carry_over_total' => $openBillsTotal,
            ];
        });

        return response()->json($result);
    }

    /**
     * Get all transactions within a shift, optionally showing ingredient contribution.
     */
    public function transactions(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk melihat transaksi shift di cabang outlet lain.'], 403);
            }
        }

        $ingId = $request->ingredient_id ? (int)$request->ingredient_id : null;

        $transactions = $shift->transactions()
            ->with(['menu.recipes.items.ingredient', 'user', 'outlet', 'shift', 'creator', 'updater', 'cancelledByUser', 'voidRequestedByUser', 'voidApprovedByUser', 'voidRejectedByUser', 'modifiers.ingredient', 'discount', 'urgentNotes.ingredient', 'customer'])
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $rows = [];
        $totalUsageForIngredient = 0.0;
        $ingUnit = '';

        foreach ($transactions as $t) {
            $menu = $t->menu;
            $recipe = $menu ? ($menu->recipes->firstWhere('version', $t->recipe_version) ?: $menu->recipes->first()) : null;

            $itemUsage = 0.0;
            if ($ingId && $recipe) {
                $item = $recipe->items->firstWhere('ingredient_id', $ingId);
                if ($item) {
                    $itemUsage = (float)$item->qty * (int)$t->qty;
                    $totalUsageForIngredient += $itemUsage;
                    $ingUnit = $item->unit ?: $item->ingredient?->unit_pakai;
                }
            }

            $rows[] = [
                'id'              => $t->id,
                'order_number'    => $t->order_number,
                'time'            => $t->created_at ? $t->created_at->format('H:i') : '-',
                'date'            => $t->date,
                'menu_id'         => $t->menu_id,
                'menu_name'       => $menu?->name ?? 'Menu #'.$t->menu_id,
                'menu_price'      => (float)($menu?->price ?? 0),
                'qty'             => (int)$t->qty,
                'total_price'     => (float)$t->total_price,
                'user_name'       => $t->user?->name ?? 'Kasir',
                'payment_method'  => $t->payment_method ?? 'CASH',
                'status'          => $t->status ?? 'PAID',
                'ingredient_usage'=> $ingId ? round($itemUsage, 3) : null,
                'ingredient_unit' => $ingId ? $ingUnit : null,
            ];
        }

        // Add receivable payments collected during shift
        $shiftRecPayments = $this->getShiftReceivablePayments($shift);
        foreach ($shiftRecPayments as $rp) {
            $rec = $rp->receivable;
            $rows[] = [
                'id'              => 'rp_' . $rp->id,
                'order_number'    => $rp->payment_no . ' (' . ($rec?->order_number ?: $rec?->receivable_no ?: 'Kasbon') . ')',
                'time'            => $rp->created_at ? $rp->created_at->format('H:i') : '-',
                'date'            => $rp->payment_date ? (is_string($rp->payment_date) ? substr($rp->payment_date, 0, 10) : $rp->payment_date->format('Y-m-d')) : '-',
                'menu_id'         => null,
                'menu_name'       => 'Pelunasan Kasbon - ' . ($rec?->customer_name ?: 'Pelanggan'),
                'menu_price'      => (float)$rp->amount,
                'qty'             => 1,
                'total_price'     => (float)$rp->amount,
                'user_name'       => $rp->receiver?->name ?? 'Kasir',
                'payment_method'  => strtoupper($rp->payment_method ?: 'CASH'),
                'status'          => 'PAID',
                'ingredient_usage'=> null,
                'ingredient_unit' => null,
            ];
        }

        $totalOrders = $transactions->unique(fn($t) => $t->order_number ?: ('trx_' . $t->id))->count() + $shiftRecPayments->count();
        $expData = $this->getShiftExpenses($shift);

        return response()->json([
            'shift'                      => $shift->load(['user', 'closedByUser', 'outlet']),
            'ingredient_id'              => $ingId,
            'total_ingredient_usage'     => round($totalUsageForIngredient, 3),
            'ingredient_unit'            => $ingUnit,
            'total_transactions'         => $totalOrders,
            'total_items'                => count($rows),
            'total_sales'                => array_sum(array_column($rows, 'total_price')),
            'expenses'                   => $expData['expenses'],
            'total_expenses'             => $expData['total_expenses'],
            'cash_expenses'              => $expData['cash_expenses'],
            'non_cash_expenses'          => $expData['non_cash_expenses'],
            'transactions'               => $rows,
            'raw_transactions'           => $transactions,
        ]);
    }

    /**
     * Get receipt data for printing shift closing report.
     * Matches the thermal receipt format: outlet header, items sold, payment breakdown, expenses, totals.
     */
    public function receipt(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
            }
        }

        $shift->load(['user', 'closedByUser', 'outlet']);

        // Outlet & Business info for receipt header
        $outlet = $shift->outlet;
        $business = $outlet ? \App\Models\Business::find($outlet->business_id) : null;

        // Get all PAID transactions (exclude dummy EQUAL split rows, include SPLIT_CLOSED actual items)
        $paidTransactions = $shift->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->with('menu')
            ->orderBy('created_at', 'asc')
            ->get();

        // Items sold grouped by menu
        $itemsSold = [];
        foreach ($paidTransactions as $t) {
            $menuId = $t->menu_id;
            $menuName = $t->menu?->name ?? 'Menu #' . $menuId;
            if (!isset($itemsSold[$menuId])) {
                $itemsSold[$menuId] = [
                    'menu_name' => $menuName,
                    'qty'       => 0,
                    'total'     => 0.0,
                ];
            }
            $itemsSold[$menuId]['qty'] += (int)$t->qty;
            $itemsSold[$menuId]['total'] += (float)$t->total_price;
        }

        // Payment method breakdown
        $allPaidTransactions = $paidTransactions;
        $shiftRecPayments = $this->getShiftReceivablePayments($shift);
        $hasShiftRecPayments = $shiftRecPayments->count() > 0;

        $paymentBreakdown = [];
        $cashTotal = 0.0;
        $nonCashTotal = 0.0;

        foreach ($allPaidTransactions as $t) {
            $method = strtoupper($t->payment_method ?? 'CASH');

            if ($method === 'CASH' || $method === 'TUNAI') {
                $cashTotal += (float)$t->total_price;
                $paymentBreakdown['CASH'] = ($paymentBreakdown['CASH'] ?? 0.0) + (float)$t->total_price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                if (!$hasShiftRecPayments) {
                    $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                    $dpPaid = (float)($t->amount_paid ?? 0);
                    if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                        $cashTotal += $dpPaid;
                    } else {
                        $nonCashTotal += $dpPaid;
                    }
                    if ($dpPaid > 0) {
                        $paymentBreakdown[$dpMethod] = ($paymentBreakdown[$dpMethod] ?? 0.0) + $dpPaid;
                    }
                }
            } else {
                $nonCashTotal += (float)$t->total_price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + (float)$t->total_price;
            }
        }

        // Include collections from kasbon payments received during this shift
        foreach ($shiftRecPayments as $rp) {
            $rpMethod = strtoupper(trim($rp->payment_method ?? 'CASH'));
            $rpPrice = (float)$rp->amount;
            if (!isset($paymentBreakdown[$rpMethod])) {
                $paymentBreakdown[$rpMethod] = 0.0;
            }
            $paymentBreakdown[$rpMethod] += $rpPrice;

            if ($rpMethod === 'CASH' || $rpMethod === 'TUNAI') {
                $cashTotal += $rpPrice;
            } else {
                $nonCashTotal += $rpPrice;
            }
        }

        // Operating expenses during shift period
        $expData = $this->getShiftExpenses($shift);
        $expenses = $expData['expenses'];
        $totalExpenses = $expData['total_expenses'];
        $cashExpenses = $expData['cash_expenses'];
        $nonCashExpenses = $expData['non_cash_expenses'];

        $totalSales = (float)$allPaidTransactions->sum('total_price');
        $totalPaymentsReceived = $cashTotal + $nonCashTotal;
        if ($totalSales < $totalPaymentsReceived) {
            $totalSales = $totalPaymentsReceived;
        }
        $totalTransactions = $allPaidTransactions->unique(fn($t) => $t->order_number ?: ('trx_' . $t->id))->count() + $shiftRecPayments->count();
        $expectedCashInDrawer = max(0, (float)$shift->initial_cash + $cashTotal - $cashExpenses);

        // Group all transactions by order_number for order list on receipt
        $ordersGrouped = [];
        foreach ($paidTransactions as $t) {
            $ordNum = $t->order_number ?: ('TRX-' . $t->id);
            if (!isset($ordersGrouped[$ordNum])) {
                $ordersGrouped[$ordNum] = [
                    'order_number'    => $ordNum,
                    'time'            => $t->created_at ? $t->created_at->format('H:i') : '-',
                    'total_price'     => 0.0,
                    'total_items'     => 0,
                    'payment_method'  => $t->payment_method ?? 'CASH',
                    'customer_name'   => $t->customer_name ?? null,
                ];
            }
            $ordersGrouped[$ordNum]['total_price'] += (float)$t->total_price;
            $ordersGrouped[$ordNum]['total_items'] += (int)$t->qty;
        }

        foreach ($shiftRecPayments as $rp) {
            $rec = $rp->receivable;
            $payNo = $rp->payment_no ?: ('PAY-' . $rp->id);
            $ordersGrouped[$payNo] = [
                'order_number'    => $payNo . ' (' . ($rec?->order_number ?: $rec?->receivable_no ?: 'Kasbon') . ')',
                'time'            => $rp->created_at ? $rp->created_at->format('H:i') : '-',
                'total_price'     => (float)$rp->amount,
                'total_items'     => 1,
                'payment_method'  => strtoupper($rp->payment_method ?: 'CASH'),
                'customer_name'   => 'Pelunasan: ' . ($rec?->customer_name ?: 'Pelanggan'),
            ];
        }
        $ordersList = array_values($ordersGrouped);
        $orderNumbers = array_keys($ordersGrouped);
        $orderRange = '-';
        if (count($orderNumbers) === 1) {
            $orderRange = $orderNumbers[0];
        } elseif (count($orderNumbers) > 1) {
            $orderRange = reset($orderNumbers) . ' s/d ' . end($orderNumbers);
        }

        return response()->json([
            'outlet_name'        => $outlet?->name ?? 'Outlet',
            'outlet_address'     => $outlet?->address ?? '',
            'outlet_phone'       => $outlet?->phone ?? '',
            'business_name'      => $business?->name ?? '',
            'shift'              => $shift,
            'kasir_name'         => $shift->user?->name ?? 'Kasir',
            'closed_by_name'     => $shift->closedByUser?->name ?? $shift->user?->name ?? 'Kasir',
            'items_sold'         => array_values($itemsSold),
            'orders'             => $ordersList,
            'order_numbers'      => $orderNumbers,
            'order_number_range' => $orderRange,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'payment_breakdown'  => $paymentBreakdown,
            'cash_total'         => $cashTotal,
            'non_cash_total'     => $nonCashTotal,
            'non_cash_details'   => collect($paymentBreakdown)->filter(function ($v, $k) {
                return !in_array($k, ['CASH', 'TUNAI']);
            })->toArray(),
            'expenses'           => $expenses,
            'total_expenses'     => $totalExpenses,
            'cash_expenses'      => $cashExpenses,
            'non_cash_expenses'  => $nonCashExpenses,
            'expected_cash'      => $expectedCashInDrawer,
            'net_amount'         => $totalSales - $totalExpenses,
            'initial_cash'       => (float)$shift->initial_cash,
            'system_cash'        => $totalSales,
            'closing_cash'       => $shift->closing_cash !== null ? (float)$shift->closing_cash : null,
            'cash_difference'    => $shift->cash_difference !== null ? (float)$shift->cash_difference : null,
        ]);
    }

    /**
     * Deposit cash from closing shift to Kas Besar (CashTransaction IN).
     */
    public function deposit(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk mengelola shift di cabang outlet lain.'], 403);
            }
        }

        $data = $request->validate([
            'amount'  => 'required|numeric|min:0.01',
            'account' => 'nullable|string|in:KAS_BESAR,BANK_MAIN',
            'notes'   => 'nullable|string|max:500',
            'date'    => 'nullable|date',
        ]);

        $businessId = $shift->business_id ?? $user->business_id ?? 1;
        $depositDate = $data['date'] ?? ($shift->closed_at ? $shift->closed_at->toDateString() : now()->toDateString());
        $account = $data['account'] ?? 'KAS_BESAR';
        $amount = (float)$data['amount'];
        $notes = $data['notes'] ?? "Setoran Closing Shift #{$shift->id} ({$shift->shift_name}) ke Kas Besar";

        $transactionNo = \App\Models\CashTransaction::generateTransactionNo($businessId, $depositDate);

        $trx = \App\Models\CashTransaction::create([
            'business_id'    => $businessId,
            'outlet_id'      => $shift->outlet_id,
            'transaction_no' => $transactionNo,
            'date'           => $depositDate,
            'type'           => 'IN',
            'activity_type'  => 'OPERATING',
            'category'       => 'SETORAN_KASIR',
            'name'           => "Setoran Kasir Shift #{$shift->id} ke Kas Besar",
            'amount'         => $amount,
            'account'        => $account,
            'payment_method' => 'CASH',
            'notes'          => $notes,
            'user_id'        => $user->id,
            'created_by'     => $user->id,
        ]);

        // Append to shift notes for audit trail
        $depositNote = "Setor Kas Besar: Rp " . number_format($amount, 0, ',', '.') . " (" . ($account === 'KAS_BESAR' ? 'Kas Besar' : 'Bank') . ")";
        $combinedNotes = $shift->notes ? $shift->notes . "\n" . $depositNote : $depositNote;
        $shift->update([
            'notes' => $combinedNotes,
        ]);

        return response()->json([
            'message'          => 'Uang kasir berhasil disetorkan ke Kas Besar.',
            'cash_transaction' => $trx,
            'shift'            => $shift->fresh(['user', 'closedByUser', 'outlet']),
        ], 201);
    }
}
