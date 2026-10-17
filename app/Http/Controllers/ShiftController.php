<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\ReceivablePayment;
use App\Models\UrgentNote;
use App\Services\AccountingService;
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

        $query = Shift::with(['user', 'closedByUser', 'creator', 'updater', 'outlet', 'shiftSchedule', 'depositSubmittedByUser', 'depositApprovedByUser'])
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

        // Fetch receivable payments collected during these shifts (supporting both shift_id and time-window fallback)
        $receivablePayments = ReceivablePayment::with('receivable')
            ->where(function ($query) use ($shiftIds, $shifts) {
                $query->whereIn('shift_id', $shiftIds);
                foreach ($shifts as $s) {
                    $query->orWhere(function ($sub) use ($s) {
                        $sub->whereNull('shift_id')
                            ->where('outlet_id', $s->outlet_id);
                        if ($s->opened_at && $s->closed_at) {
                            $sub->whereBetween('created_at', [$s->opened_at, $s->closed_at]);
                        } elseif ($s->opened_at) {
                            $sub->where('created_at', '>=', $s->opened_at);
                        }
                    });
                }
            })
            ->get();

        $recPaymentsByShift = [];
        foreach ($receivablePayments as $rp) {
            $sId = $rp->shift_id;
            if (!$sId) {
                foreach ($shifts as $s) {
                    if ($s->outlet_id == $rp->outlet_id) {
                        if ($s->opened_at && $s->closed_at && $rp->created_at >= $s->opened_at && $rp->created_at <= $s->closed_at) {
                            $sId = $s->id;
                            break;
                        } elseif ($s->opened_at && !$s->closed_at && $rp->created_at >= $s->opened_at) {
                            $sId = $s->id;
                            break;
                        }
                    }
                }
            }
            if ($sId) {
                if (!isset($recPaymentsByShift[$sId])) $recPaymentsByShift[$sId] = collect();
                $recPaymentsByShift[$sId]->push($rp);
            }
        }

        // Fetch Kasbon records originated by these shifts to know their settlement status
        $kasbonReceivables = \App\Models\Receivable::whereIn('shift_id', $shiftIds)
            ->with(['payments'])
            ->get()
            ->groupBy('shift_id');

        $breakdownByShift = [];
        foreach ($shiftIds as $sId) {
            $breakdownByShift[$sId] = [
                'cash'        => 0.0,
                'qris'        => 0.0,
                'grab'        => 0.0,
                'gofood'      => 0.0,
                'shopee'      => 0.0,
                'transfer'    => 0.0,
                'debit'       => 0.0,
                'kasbon'      => 0.0,
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
            } elseif (str_contains($method, 'GOFOOD') || str_contains($method, 'GOJEK')) {
                $breakdownByShift[$sId]['gofood'] = ($breakdownByShift[$sId]['gofood'] ?? 0.0) + $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'SHOPEE')) {
                $breakdownByShift[$sId]['shopee'] = ($breakdownByShift[$sId]['shopee'] ?? 0.0) + $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'GRAB')) {
                $breakdownByShift[$sId]['grab'] = ($breakdownByShift[$sId]['grab'] ?? 0.0) + $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'TIKTOK')) {
                $breakdownByShift[$sId]['tiktok'] = ($breakdownByShift[$sId]['tiktok'] ?? 0.0) + $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'TRANSFER')) {
                $breakdownByShift[$sId]['transfer'] += $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'DEBIT') || str_contains($method, 'EDC') || str_contains($method, 'CREDIT') || str_contains($method, 'KARTU')) {
                $breakdownByShift[$sId]['debit'] += $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG']) || str_contains($method, 'KASBON') || str_contains($method, 'PIUTANG')) {
                $breakdownByShift[$sId]['kasbon'] = ($breakdownByShift[$sId]['kasbon'] ?? 0.0) + $price;
                $breakdownByShift[$sId]['details'][$method] = ($breakdownByShift[$sId]['details'][$method] ?? 0.0) + $price;
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

        // Add collections from kasbon payments received during each shift into their respective payment method
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
                } elseif (str_contains($rpMethod, 'GOFOOD') || str_contains($rpMethod, 'GOJEK')) {
                    $breakdownByShift[$sId]['gofood'] = ($breakdownByShift[$sId]['gofood'] ?? 0.0) + $rpAmt;
                } elseif (str_contains($rpMethod, 'SHOPEE')) {
                    $breakdownByShift[$sId]['shopee'] = ($breakdownByShift[$sId]['shopee'] ?? 0.0) + $rpAmt;
                } elseif (str_contains($rpMethod, 'GRAB')) {
                    $breakdownByShift[$sId]['grab'] = ($breakdownByShift[$sId]['grab'] ?? 0.0) + $rpAmt;
                } elseif (str_contains($rpMethod, 'TRANSFER')) {
                    $breakdownByShift[$sId]['transfer'] += $rpAmt;
                } elseif (str_contains($rpMethod, 'DEBIT') || str_contains($rpMethod, 'EDC') || str_contains($rpMethod, 'CREDIT') || str_contains($rpMethod, 'KARTU')) {
                    $breakdownByShift[$sId]['debit'] += $rpAmt;
                } else {
                    $breakdownByShift[$sId]['other'] += $rpAmt;
                }
            }
        }

        // Fetch relevant closed shifts for the outlets in $shifts to calculate selisih modal awal vs kas fisik akhir shift sebelumnya
        $outletIds = $shifts->pluck('outlet_id')->unique()->filter()->values();
        $allClosedShifts = Shift::whereIn('outlet_id', $outletIds)
            ->where('status', 'CLOSED')
            ->whereNotNull('closing_cash')
            ->orderBy('opened_at', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'outlet_id', 'shift_name', 'user_id', 'opened_at', 'closed_at', 'closing_cash', 'initial_cash', 'deposit_amount', 'deposit_status', 'notes']);

        // Fetch all shifts for these outlets to accurately detect if subsequent shift has opened or is running
        $allShiftsForOutlets = Shift::whereIn('outlet_id', $outletIds)
            ->orderBy('opened_at', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'outlet_id', 'shift_name', 'status', 'opened_at', 'closed_at']);

        // Fetch deposits for these shifts and closed shifts
        $allRelevantShiftIds = $shiftIds->concat($allClosedShifts->pluck('id'))->unique()->filter()->values();
        $depositTransactions = \App\Models\CashTransaction::whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
            ->whereIn('status', ['APPROVED', 'PENDING'])
            ->whereIn('shift_id', $allRelevantShiftIds)
            ->get();

        // Fetch modal kas besar transactions for these shifts
        $modalKasBesarTxs = \App\Models\CashTransaction::whereIn('shift_id', $allRelevantShiftIds)
            ->where('category', 'TARIK_MODAL_KASIR')
            ->where('type', 'OUT')
            ->get()
            ->keyBy('shift_id');

        $depositsByShift = [];
        foreach ($depositTransactions as $dtx) {
            $sId = $dtx->shift_id;
            if ($sId) {
                if (!isset($depositsByShift[$sId])) {
                    $depositsByShift[$sId] = collect();
                }
                $depositsByShift[$sId]->push($dtx);
            }
        }

        foreach ($shifts as $s) {
            $bd = $breakdownByShift[$s->id] ?? [
                'cash'        => 0.0,
                'qris'        => 0.0,
                'grab'        => 0.0,
                'gofood'      => 0.0,
                'shopee'      => 0.0,
                'tiktok'      => 0.0,
                'transfer'    => 0.0,
                'debit'       => 0.0,
                'kasbon'      => 0.0,
                'other'       => 0.0,
                'total_sales' => 0.0,
                'details'     => [],
            ];
            $s->cash_sales = $bd['cash'];
            $s->qris_sales = $bd['qris'];
            $s->grab_sales = $bd['grab'] ?? 0.0;
            $s->gofood_sales = $bd['gofood'] ?? 0.0;
            $s->shopee_sales = $bd['shopee'] ?? 0.0;
            $s->tiktok_sales = $bd['tiktok'] ?? 0.0;
            $s->delivery_sales = ($bd['grab'] ?? 0.0) + ($bd['gofood'] ?? 0.0) + ($bd['shopee'] ?? 0.0) + ($bd['tiktok'] ?? 0.0);
            $s->transfer_sales = $bd['transfer'];
            $s->debit_sales = $bd['debit'];
            
            // Kasbon settlement status for this shift
            $shiftRecs = $kasbonReceivables[$s->id] ?? collect();
            $kbTotal = (float)$shiftRecs->sum('total_amount');
            $kbPaid = (float)$shiftRecs->sum('paid_amount');
            $kbRemaining = (float)$shiftRecs->sum('remaining_amount');

            $kbSettlementMethods = [];
            foreach ($shiftRecs as $rec) {
                foreach ($rec->payments as $p) {
                    $pm = strtoupper(trim($p->payment_method ?? 'CASH'));
                    $kbSettlementMethods[$pm] = ($kbSettlementMethods[$pm] ?? 0.0) + (float)$p->amount;
                }
            }

            // Collections received during this shift (money entering via cash/qris/transfer to settle kasbon)
            $shiftRpList = $recPaymentsByShift[$s->id] ?? collect();
            $rpCollections = [
                'total'    => (float)$shiftRpList->sum('amount'),
                'cash'     => 0.0,
                'qris'     => 0.0,
                'transfer' => 0.0,
                'debit'    => 0.0,
                'other'    => 0.0,
            ];
            foreach ($shiftRpList as $rp) {
                $amt = (float)$rp->amount;
                $m = strtoupper(trim($rp->payment_method ?? 'CASH'));
                if ($m === 'CASH' || $m === 'TUNAI') $rpCollections['cash'] += $amt;
                elseif (str_contains($m, 'QRIS')) $rpCollections['qris'] += $amt;
                elseif (str_contains($m, 'TRANSFER')) $rpCollections['transfer'] += $amt;
                elseif (str_contains($m, 'DEBIT') || str_contains($m, 'EDC') || str_contains($m, 'CREDIT')) $rpCollections['debit'] += $amt;
                else $rpCollections['other'] += $amt;
            }

            $s->kasbon_sales = (float)($bd['kasbon'] ?? 0.0);
            if ($s->kasbon_sales == 0 && $kbTotal > 0) {
                $s->kasbon_sales = $kbTotal;
            }
            $s->kasbon_settled = $kbPaid;
            $s->kasbon_remaining = $kbRemaining;
            $s->kasbon_settlement_methods = $kbSettlementMethods;
            $s->receivable_collections = $rpCollections;

            $s->other_sales = $bd['other'];
            $s->payment_breakdown = $bd['details'];
            $s->system_cash = $bd['total_sales'];

            // Uang Keluar / Biaya Operasional Kasir pada Sesi Shift
            $expData = $this->getShiftExpenses($s);
            $s->total_expenses = $expData['total_expenses'];
            $s->cash_expenses = $expData['cash_expenses'];
            $s->non_cash_expenses = $expData['non_cash_expenses'];

            // Deposit data
            $sDepositTxs = $depositsByShift[$s->id] ?? collect();
            $lastDepositTx = $sDepositTxs->last();

            $depositStatus = $s->deposit_status ?: ($lastDepositTx ? ($lastDepositTx->status ?: 'APPROVED') : 'NONE');
            $depositAmount = ($depositStatus === 'APPROVED' || $depositStatus === 'PENDING')
                ? ($s->deposit_amount !== null ? (float)$s->deposit_amount : (float)$sDepositTxs->sum('amount'))
                : 0.0;
            $depositAccount = $s->deposit_account ?: ($lastDepositTx?->account ?? ($depositAmount > 0 ? 'KAS_BESAR' : null));
            $depositAccountLabel = $s->deposit_account_label ?: ($lastDepositTx?->account_label ?? ($depositAmount > 0 ? 'Kas Besar / Brankas' : null));

            $s->deposit_status = $depositStatus;
            $s->deposit_amount = $depositAmount;
            $s->is_deposited = $depositStatus === 'APPROVED' && $depositAmount > 0;
            $s->is_deposit_pending = $depositStatus === 'PENDING';
            $s->is_deposit_rejected = $depositStatus === 'REJECTED';
            $s->deposit_account = $depositAccount;
            $s->deposit_account_label = $depositAccountLabel;
            $s->deposit_date = $s->deposit_submitted_at ? $s->deposit_submitted_at->toDateString() : ($lastDepositTx?->date ? (is_string($lastDepositTx->date) ? substr($lastDepositTx->date, 0, 10) : $lastDepositTx->date->format('Y-m-d')) : null);
            $s->deposit_transaction_no = $lastDepositTx?->transaction_no;
            $s->deposit_notes = $s->deposit_notes ?: $lastDepositTx?->notes;
            $s->deposit_submitted_at = $s->deposit_submitted_at;
            $s->deposit_submitted_by_name = $s->deposit_submitted_by_name ?: ($lastDepositTx?->user_name ?? $s->user?->name);
            $s->deposit_approved_at = $s->deposit_approved_at;
            $s->deposit_approved_by_name = $s->deposit_approved_by_name ?: ($lastDepositTx?->approved_by_name);
            $s->deposit_rejection_reason = $s->deposit_rejection_reason ?: ($lastDepositTx?->rejection_reason);

            // Selisih Setoran Kasir vs Kas Fisik Laci:
            if ($s->status === 'CLOSED' && $depositAmount > 0 && $s->closing_cash !== null) {
                $s->deposit_diff = round($depositAmount - (float)$s->closing_cash, 2);
            } else {
                $s->deposit_diff = 0.0;
            }

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

                // Ambil setoran shift sebelumnya
                $prevDepTxs = $depositsByShift[$prev->id] ?? collect();
                $prevDepStatus = $prev->deposit_status ?: ($prevDepTxs->last()?->status ?: 'NONE');
                $prevDepAmt = ($prevDepStatus === 'APPROVED' || $prevDepStatus === 'PENDING')
                    ? ($prev->deposit_amount !== null ? (float)$prev->deposit_amount : (float)$prevDepTxs->sum('amount'))
                    : 0.0;

                // Jika setoran shift lalu APPROVED atau PENDING, kurangi dari kas fisik laci
                $prevDepDeducted = ($prevDepStatus === 'APPROVED' || $prevDepStatus === 'PENDING') ? $prevDepAmt : 0.0;
                $prevRemainingInDrawer = max(0.0, round($prevClosing - $prevDepDeducted, 2));

                $s->previous_shift_id = $prev->id;
                $s->previous_shift_name = $prev->shift_name;
                $s->previous_shift_closing_cash = $prevClosing;
                $s->previous_shift_deposit_amount = $prevDepDeducted;
                $s->previous_shift_remaining_drawer = $prevRemainingInDrawer;

                // Cek sumber modal shift ini (apakah dari Kas Besar)
                $kbTx = $modalKasBesarTxs[$s->id] ?? null;
                $kbAmount = $kbTx ? (float)$kbTx->amount : 0.0;
                $isFromKasBesar = $kbAmount > 0 || str_contains($s->notes ?? '', 'Kas Besar');

                if ($isFromKasBesar) {
                    $s->initial_cash_source = 'KAS_BESAR';
                    $effectiveKb = $kbAmount > 0 ? $kbAmount : (float)$s->initial_cash;
                    $expectedInitial = round($prevRemainingInDrawer + $effectiveKb, 2);
                    $s->initial_cash_difference = round((float)$s->initial_cash - $expectedInitial, 2);
                } elseif ($prevRemainingInDrawer == 0.0) {
                    // Kas fisik shift sebelumnya sudah habis disetor (sisa laci Rp 0).
                    // Shift baru memulai kasir dengan modal awal baru (mandiri atau dari kas toko).
                    // Karena shift lalu tidak meninggalkan uang di laci (sudah disetor penuh), TIDAK ADA SELISIH antar shift!
                    $s->initial_cash_source = 'DRAWER';
                    $s->initial_cash_difference = 0.0;
                } else {
                    // Ada sisa kas di laci dari shift sebelumnya (misal disetor sebagian / carry over)
                    $s->initial_cash_source = 'DRAWER';
                    $s->initial_cash_difference = round((float)$s->initial_cash - $prevRemainingInDrawer, 2);
                }
            } else {
                $s->previous_shift_id = null;
                $s->previous_shift_name = null;
                $s->previous_shift_closing_cash = null;
                $s->previous_shift_deposit_amount = null;
                $s->previous_shift_remaining_drawer = null;
                $s->initial_cash_difference = null;
                $s->initial_cash_source = str_contains($s->notes ?? '', 'Kas Besar') ? 'KAS_BESAR' : 'DRAWER';
            }

            // Find next shift on the same outlet (shift yang dibuka setelah shift ini)
            $next = $allShiftsForOutlets
                ->where('outlet_id', $s->outlet_id)
                ->filter(function ($other) use ($s) {
                    if ($other->id === $s->id) return false;
                    if ($other->opened_at && $s->opened_at) {
                        if ($other->opened_at > $s->opened_at) return true;
                        if ($other->opened_at == $s->opened_at) return $other->id > $s->id;
                    }
                    return $other->id > $s->id;
                })
                ->first();

            // Cek apakah ada shift OPEN yang aktif di outlet ini (selain shift ini)
            $activeInOutlet = $allShiftsForOutlets
                ->where('outlet_id', $s->outlet_id)
                ->where('status', 'OPEN')
                ->where('id', '!=', $s->id)
                ->first();

            $hasNextShift = ($next !== null) || ($activeInOutlet !== null);
            $targetNext = $next ?: $activeInOutlet;

            $canDeposit = true;
            $cannotDepositReason = null;
            if ($s->status !== 'CLOSED') {
                $canDeposit = false;
                $cannotDepositReason = 'Shift masih berjalan (belum ditutup).';
            } elseif ($depositStatus === 'APPROVED' || ($depositAmount > 0 && $depositStatus !== 'REJECTED')) {
                $canDeposit = false;
                $cannotDepositReason = 'Setoran sudah disetujui / sudah disetor.';
            } elseif ($depositStatus === 'PENDING') {
                $canDeposit = false;
                $cannotDepositReason = 'Setoran sedang menunggu persetujuan Owner.';
            } elseif ($hasNextShift) {
                $canDeposit = false;
                $nextLabel = $targetNext ? ($targetNext->status === 'OPEN' ? 'sedang berjalan' : 'sudah dibuka') : 'sudah dibuka';
                $nextTitle = $targetNext ? ($targetNext->shift_name ?: "Shift #{$targetNext->id}") : 'berikutnya';
                $cannotDepositReason = "Shift selanjutnya ({$nextTitle}) {$nextLabel}.";
            }

            $s->has_next_shift = $hasNextShift;
            $s->next_shift_id = $targetNext?->id;
            $s->next_shift_name = $targetNext?->shift_name;
            $s->can_deposit = $canDeposit;
            $s->cannot_deposit_reason = $cannotDepositReason;
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
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }
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

            $lastClosedData = null;
            if ($lastShift) {
                $depositTxs = \App\Models\CashTransaction::where('shift_id', $lastShift->id)
                    ->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                    ->whereIn('status', ['APPROVED', 'PENDING'])
                    ->get();
                $totalDeposited = (float)$depositTxs->sum('amount');
                $lastDeposit = $depositTxs->last();

                $depositStatus = $lastShift->deposit_status ?: ($lastDeposit ? ($lastDeposit->status ?: 'APPROVED') : 'NONE');
                $depositAmount = ($depositStatus === 'APPROVED' || $depositStatus === 'PENDING')
                    ? ($lastShift->deposit_amount !== null ? (float)$lastShift->deposit_amount : $totalDeposited)
                    : 0.0;
                $depositAmt = ($depositStatus === 'APPROVED' || $depositStatus === 'PENDING') ? (float)$depositAmount : 0.0;
                $remainingCashInDrawer = $lastShift->closing_cash !== null ? max(0, round((float)$lastShift->closing_cash - $depositAmt, 2)) : 0.0;

                $kasBesarBalance = 0.0;
                $jl = \App\Models\JournalLine::where('account_code', '1-11001')
                    ->when($outletId, fn($q) => $q->where(function ($sub) use ($outletId) {
                        $sub->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                    }))
                    ->selectRaw('SUM(debit) - SUM(credit) as bal')
                    ->first();
                if ($jl && $jl->bal !== null) {
                    $kasBesarBalance = (float)$jl->bal;
                } else {
                    $ctBal = \App\Models\CashTransaction::where('account', 'KAS_BESAR')
                        ->where('status', 'APPROVED')
                        ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                        ->selectRaw('SUM(CASE WHEN type = "IN" THEN amount ELSE -amount END) as bal')
                        ->value('bal');
                    $kasBesarBalance = (float)($ctBal ?? 0);
                }

                $lastClosedData = [
                    'id'                       => $lastShift->id,
                    'shift_name'               => $lastShift->shift_name,
                    'outlet_id'                => $lastShift->outlet_id,
                    'outlet_name'              => $lastShift->outlet?->name,
                    'cashier_name'             => $lastShift->user?->name,
                    'closed_by_name'           => $lastShift->closedByUser?->name ?? $lastShift->user?->name,
                    'opened_at'                => $lastShift->opened_at,
                    'closed_at'                => $lastShift->closed_at,
                    'initial_cash'             => (float)$lastShift->initial_cash,
                    'closing_cash'             => (float)$lastShift->closing_cash,
                    'remaining_cash_in_drawer' => $remainingCashInDrawer,
                    'kas_besar_balance'        => $kasBesarBalance,
                    'deposit_status'           => $depositStatus,
                    'deposit_amount'           => $depositAmount,
                    'is_deposited'             => $depositStatus === 'APPROVED' && $depositAmount > 0,
                    'system_cash'              => (float)$lastShift->system_cash,
                    'cash_difference'          => (float)$lastShift->cash_difference,
                    'notes'                    => $lastShift->notes,
                ];
            }

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
        $gofoodSales = 0.0;
        $shopeeSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $kasbonSales = 0.0;
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
            } elseif (str_contains($method, 'GOFOOD') || str_contains($method, 'GOJEK')) {
                $gofoodSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'SHOPEE')) {
                $shopeeSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'GRAB')) {
                $grabSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'TIKTOK')) {
                $tiktokSales = ($tiktokSales ?? 0.0) + $price;
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
            } elseif (in_array($method, ['KASBON', 'PIUTANG']) || str_contains($method, 'KASBON') || str_contains($method, 'PIUTANG')) {
                $kasbonSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
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
            $prevClosing = (float)$prevShiftForActive->closing_cash;

            // Ambil transaksi setoran shift sebelumnya
            $prevDepTxs = \App\Models\CashTransaction::where('shift_id', $prevShiftForActive->id)
                ->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                ->get();
            $prevDepAmt = $prevShiftForActive->deposit_amount !== null ? (float)$prevShiftForActive->deposit_amount : (float)$prevDepTxs->sum('amount');
            $prevDepStatus = $prevShiftForActive->deposit_status ?: ($prevDepTxs->last()?->status ?: 'NONE');
            $prevDepDeducted = ($prevDepStatus === 'APPROVED' || $prevDepStatus === 'PENDING' || $prevDepAmt > 0) ? $prevDepAmt : 0.0;
            $prevRemainingInDrawer = max(0.0, round($prevClosing - $prevDepDeducted, 2));

            $shift->previous_shift_id = $prevShiftForActive->id;
            $shift->previous_shift_name = $prevShiftForActive->shift_name;
            $shift->previous_shift_closing_cash = $prevClosing;
            $shift->previous_shift_deposit_amount = $prevDepDeducted;
            $shift->previous_shift_remaining_drawer = $prevRemainingInDrawer;

            // Cek sumber modal shift aktif ini (apakah dari Kas Besar)
            $kbTx = \App\Models\CashTransaction::where('shift_id', $shift->id)
                ->where('category', 'TARIK_MODAL_KASIR')
                ->where('type', 'OUT')
                ->first();
            $kbAmount = $kbTx ? (float)$kbTx->amount : 0.0;
            $isFromKasBesar = $kbAmount > 0 || str_contains($shift->notes ?? '', 'Kas Besar');

            if ($isFromKasBesar) {
                $shift->initial_cash_source = 'KAS_BESAR';
                $effectiveKb = $kbAmount > 0 ? $kbAmount : (float)$shift->initial_cash;
                $expectedInitial = round($prevRemainingInDrawer + $effectiveKb, 2);
                $shift->initial_cash_difference = round((float)$shift->initial_cash - $expectedInitial, 2);
            } elseif ($prevRemainingInDrawer == 0.0) {
                // Kas fisik shift sebelumnya sudah habis disetor (sisa laci Rp 0).
                // Shift baru memulai kasir dengan modal awal baru (mandiri atau dari kas toko).
                // Karena shift lalu tidak meninggalkan uang di laci (sudah disetor penuh), TIDAK ADA SELISIH antar shift!
                $shift->initial_cash_source = 'DRAWER';
                $shift->initial_cash_difference = 0.0;
            } else {
                // Ada sisa kas di laci dari shift sebelumnya
                $shift->initial_cash_source = 'DRAWER';
                $shift->initial_cash_difference = round((float)$shift->initial_cash - $prevRemainingInDrawer, 2);
            }
        } else {
            $shift->previous_shift_id = null;
            $shift->previous_shift_name = null;
            $shift->previous_shift_closing_cash = null;
            $shift->previous_shift_deposit_amount = null;
            $shift->previous_shift_remaining_drawer = null;
            $shift->initial_cash_difference = null;
            $shift->initial_cash_source = str_contains($shift->notes ?? '', 'Kas Besar') ? 'KAS_BESAR' : 'DRAWER';
        }

        $shiftRecs = \App\Models\Receivable::where('shift_id', $shift->id)->with('payments')->get();
        $kbTotal = (float)$shiftRecs->sum('total_amount');
        $kbPaid = (float)$shiftRecs->sum('paid_amount');
        $kbRemaining = (float)$shiftRecs->sum('remaining_amount');
        $kbSettlementMethods = [];
        foreach ($shiftRecs as $rec) {
            foreach ($rec->payments as $p) {
                $pm = strtoupper(trim($p->payment_method ?? 'CASH'));
                $kbSettlementMethods[$pm] = ($kbSettlementMethods[$pm] ?? 0.0) + (float)$p->amount;
            }
        }

        $rpCollections = [
            'total'    => (float)$shiftRecPayments->sum('amount'),
            'cash'     => 0.0,
            'qris'     => 0.0,
            'transfer' => 0.0,
            'debit'    => 0.0,
            'other'    => 0.0,
        ];
        foreach ($shiftRecPayments as $rp) {
            $amt = (float)$rp->amount;
            $m = strtoupper(trim($rp->payment_method ?? 'CASH'));
            if ($m === 'CASH' || $m === 'TUNAI') $rpCollections['cash'] += $amt;
            elseif (str_contains($m, 'QRIS')) $rpCollections['qris'] += $amt;
            elseif (str_contains($m, 'TRANSFER')) $rpCollections['transfer'] += $amt;
            elseif (str_contains($m, 'DEBIT') || str_contains($m, 'EDC') || str_contains($m, 'CREDIT')) $rpCollections['debit'] += $amt;
            else $rpCollections['other'] += $amt;
        }

        if ($kasbonSales == 0 && $kbTotal > 0) {
            $kasbonSales = $kbTotal;
        }

        return response()->json([
            'shift'              => $shift,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'cash_sales'         => $cashSales,
            'qris_sales'         => $qrisSales,
            'grab_sales'         => $grabSales,
            'gofood_sales'       => $gofoodSales,
            'shopee_sales'       => $shopeeSales,
            'transfer_sales'     => $transferSales,
            'debit_sales'        => $debitSales,
            'other_sales'        => $otherSales,
            'kasbon_sales'       => $kasbonSales,
            'kasbon_settled'     => $kbPaid,
            'kasbon_remaining'   => $kbRemaining,
            'kasbon_settlement_methods' => $kbSettlementMethods,
            'receivable_collections'    => $rpCollections,
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
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        $query = Shift::with(['user', 'closedByUser', 'outlet', 'shiftSchedule'])
            ->where('status', 'CLOSED');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        $lastShift = $query->orderByDesc('closed_at')->orderByDesc('id')->first();

        if (!$lastShift) {
            return response()->json(null);
        }

        $depositTxs = \App\Models\CashTransaction::where('shift_id', $lastShift->id)
            ->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
            ->whereIn('status', ['APPROVED', 'PENDING'])
            ->get();
        $totalDeposited = (float)$depositTxs->sum('amount');
        $lastDeposit = $depositTxs->last();

        $depositStatus = $lastShift->deposit_status ?: ($lastDeposit ? ($lastDeposit->status ?: 'APPROVED') : 'NONE');
        $depositAmount = ($depositStatus === 'APPROVED' || $depositStatus === 'PENDING')
            ? ($lastShift->deposit_amount !== null ? (float)$lastShift->deposit_amount : $totalDeposited)
            : 0.0;

        // Hitung sisa kas fisik di laci setelah setoran kasir:
        $depositAmt = ($depositStatus === 'APPROVED' || $depositStatus === 'PENDING') ? (float)$depositAmount : 0.0;
        $remainingCashInDrawer = $lastShift->closing_cash !== null ? max(0, round((float)$lastShift->closing_cash - $depositAmt, 2)) : 0.0;

        // Ambil saldo riil Kas Besar saat ini untuk outlet ini:
        $kasBesarBalance = 0.0;
        $jl = \App\Models\JournalLine::where('account_code', '1-11001')
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->selectRaw('SUM(debit) - SUM(credit) as bal')
            ->first();
        if ($jl && $jl->bal !== null) {
            $kasBesarBalance = (float)$jl->bal;
        } else {
            $ctBal = \App\Models\CashTransaction::where('account', 'KAS_BESAR')
                ->where('status', 'APPROVED')
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->selectRaw('SUM(CASE WHEN type = "IN" THEN amount ELSE -amount END) as bal')
                ->value('bal');
            $kasBesarBalance = (float)($ctBal ?? 0);
        }

        return response()->json([
            'id'                         => $lastShift->id,
            'shift_name'                 => $lastShift->shift_name,
            'outlet_id'                  => $lastShift->outlet_id,
            'outlet_name'                => $lastShift->outlet?->name,
            'user_id'                    => $lastShift->user_id,
            'cashier_name'               => $lastShift->user?->name,
            'closed_by'                  => $lastShift->closed_by,
            'closed_by_name'             => $lastShift->closedByUser?->name ?? $lastShift->user?->name,
            'opened_at'                  => $lastShift->opened_at,
            'closed_at'                  => $lastShift->closed_at,
            'initial_cash'               => (float)$lastShift->initial_cash,
            'closing_cash'               => (float)$lastShift->closing_cash,
            'remaining_cash_in_drawer'   => $remainingCashInDrawer,
            'kas_besar_balance'          => $kasBesarBalance,
            'system_cash'                => (float)$lastShift->system_cash,
            'cash_difference'            => (float)$lastShift->cash_difference,
            'deposit_status'             => $depositStatus,
            'is_deposited'               => $depositStatus === 'APPROVED' && $depositAmount > 0,
            'is_deposit_pending'         => $depositStatus === 'PENDING',
            'deposit_amount'             => $depositAmount,
            'deposit_account'            => $lastShift->deposit_account ?: ($lastDeposit?->account ?? ($depositAmount > 0 ? 'KAS_BESAR' : null)),
            'deposit_account_label'      => $lastShift->deposit_account_label ?: ($lastDeposit?->account_label ?? ($depositAmount > 0 ? 'Kas Besar / Brankas' : null)),
            'deposit_diff'               => ($depositAmount > 0 && $lastShift->closing_cash !== null) ? round($depositAmount - (float)$lastShift->closing_cash, 2) : 0.0,
            'deposit_submitted_at'       => $lastShift->deposit_submitted_at,
            'deposit_submitted_by_name'  => $lastShift->deposit_submitted_by_name ?: ($lastDeposit?->user_name ?? $lastShift->user?->name),
            'deposit_approved_at'        => $lastShift->deposit_approved_at,
            'deposit_approved_by_name'   => $lastShift->deposit_approved_by_name ?: ($lastDeposit?->approved_by_name),
            'deposit_rejection_reason'   => $lastShift->deposit_rejection_reason ?: ($lastDeposit?->rejection_reason),
            'has_next_shift'             => (function () use ($lastShift) {
                $next = Shift::where('outlet_id', $lastShift->outlet_id)
                    ->where(function ($q) use ($lastShift) {
                        if ($lastShift->closed_at) {
                            $q->where('opened_at', '>=', $lastShift->closed_at)->orWhere('id', '>', $lastShift->id);
                        } else {
                            $q->where('id', '>', $lastShift->id);
                        }
                    })
                    ->where('id', '!=', $lastShift->id)
                    ->exists();
                $active = Shift::where('outlet_id', $lastShift->outlet_id)->where('status', 'OPEN')->where('id', '!=', $lastShift->id)->exists();
                return $next || $active;
            })(),
            'can_deposit'                => (function () use ($lastShift, $depositStatus, $depositAmount) {
                if ($depositStatus === 'APPROVED' || ($depositAmount > 0 && $depositStatus !== 'REJECTED')) return false;
                if ($depositStatus === 'PENDING') return false;
                $next = Shift::where('outlet_id', $lastShift->outlet_id)
                    ->where(function ($q) use ($lastShift) {
                        if ($lastShift->closed_at) {
                            $q->where('opened_at', '>=', $lastShift->closed_at)->orWhere('id', '>', $lastShift->id);
                        } else {
                            $q->where('id', '>', $lastShift->id);
                        }
                    })
                    ->where('id', '!=', $lastShift->id)
                    ->exists();
                $active = Shift::where('outlet_id', $lastShift->outlet_id)->where('status', 'OPEN')->where('id', '!=', $lastShift->id)->exists();
                return !$next && !$active;
            })(),
            'notes'                      => $lastShift->notes,
        ]);
    }

    /**
     * Get list of eligible Managers and Owners for supervisor approval PIN/Password.
     */
    public function getSupervisors(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $query = \App\Models\User::where('business_id', $businessId);

        $supervisors = $query->get()->filter(function ($u) {
            return $u->isOwnerOrManager();
        })->values()->map(function ($u) {
            return [
                'id'         => $u->id,
                'name'       => $u->name,
                'username'   => $u->username ?? $u->email,
                'role'       => $u->role,
                'role_label' => $u->role_label,
                'outlet_id'  => $u->outlet_id,
            ];
        });

        return response()->json($supervisors);
    }

    /**
     * Open a new shift.
     */
    public function open(Request $request)
    {
        $data = $request->validate([
            'shift_name'          => 'nullable|string|max:100',
            'client_shift_id'     => 'nullable|string|max:100',
            'opened_at'           => 'nullable|date',
            'shift_schedule_id'   => 'nullable|integer',
            'initial_cash'        => 'nullable|numeric|min:0',
            'initial_cash_source' => 'nullable|string|in:DRAWER,KAS_BESAR',
            'kas_besar_amount'    => 'nullable|numeric|min:0',
            'supervisor_id'       => 'nullable|integer',
            'supervisor_password' => 'nullable|string|max:100',
            'supervisor_pin'      => 'nullable|string|max:20',
            'notes'               => 'nullable|string|max:500',
            'outlet_id'           => 'nullable|integer',
        ]);

        $user = $request->user();
        $businessId = (int) $user->business_id;

        $outletId = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : ($user->outlet_id ?: Outlet::where('business_id', $businessId)->value('id'));

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

        // Idempotency check for offline shift synchronization
        $clientShiftId = $data['client_shift_id'] ?? $request->input('client_shift_id');
        if (!empty($clientShiftId)) {
            $existingClientShift = Shift::where('business_id', $businessId)
                ->where('client_shift_id', $clientShiftId)
                ->first();

            if ($existingClientShift) {
                $existingClientShift->load(['user', 'outlet', 'creator', 'updater', 'shiftSchedule']);
                return response()->json([
                    'message'            => 'Shift sudah terdaftar (idempotent sync).',
                    'data'               => $existingClientShift,
                    'is_idempotent_sync' => true,
                ], 200);
            }
        }

        // Check if there is already an open shift for this outlet
        $existing = Shift::where('status', 'OPEN')
            ->where('business_id', $businessId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($existing) {
            // If offline shift sync arrives and user already has an active open shift without client_shift_id, link them
            if (!empty($clientShiftId) && empty($existing->client_shift_id) && $existing->user_id === $user->id) {
                $existing->update(['client_shift_id' => $clientShiftId]);
                $existing->load(['user', 'outlet', 'creator', 'updater', 'shiftSchedule']);
                return response()->json([
                    'message'            => 'Shift aktif berhasil ditautkan dengan shift offline kasir.',
                    'data'               => $existing,
                    'is_idempotent_sync' => true,
                ], 200);
            }

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

        $initialCashSource = strtoupper(trim($data['initial_cash_source'] ?? 'DRAWER'));
        $initialCash = (float)($data['initial_cash'] ?? 0);
        $kasBesarAmount = (float)($data['kas_besar_amount'] ?? 0);

        if ($initialCashSource === 'KAS_BESAR') {
            $initialCash = $kasBesarAmount > 0 ? $kasBesarAmount : $initialCash;
        }

        // VALIDASI PERSETUJUAN MANAGER ATAU OWNER JIKA MENGAMBIL DARI KAS BESAR
        $approverUser = null;
        if ($initialCashSource === 'KAS_BESAR' && $initialCash > 0) {
            $isCallerOwnerOrManager = $user->isOwnerOrManager();

            if ($isCallerOwnerOrManager) {
                // User yang membuka shift adalah Owner atau Manager sendiri
                $approverUser = $user;
            } else {
                // User adalah Pegawai / Kasir -> WAJIB otorisasi Supervisor (Manager atau Owner)
                if (empty($data['supervisor_id']) || (empty($data['supervisor_password']) && empty($data['supervisor_pin']))) {
                    return response()->json([
                        'message' => 'Pengambilan uang dari Kas Besar harus disetujui oleh Manajer atau Owner. Silakan pilih akun Manajer/Owner dan masukkan Password/PIN otorisasi.'
                    ], 422);
                }

                $supervisor = \App\Models\User::where('business_id', $businessId)->find((int)$data['supervisor_id']);
                if (!$supervisor || !$supervisor->isOwnerOrManager()) {
                    return response()->json([
                        'message' => 'Akun Manajer / Owner yang dipilih tidak valid atau tidak memiliki wewenang otorisasi Kas Besar.'
                    ], 422);
                }

                $pass = $data['supervisor_password'] ?? $data['supervisor_pin'];
                $passValid = \Illuminate\Support\Facades\Hash::check($pass, $supervisor->password) || (isset($supervisor->pin) && $supervisor->pin === $pass);

                if (!$passValid) {
                    return response()->json([
                        'message' => "Otorisasi gagal: Password atau PIN untuk Manajer/Owner '{$supervisor->name}' salah."
                    ], 422);
                }

                $approverUser = $supervisor;
            }
        }

        // Cek sisa kas fisik di laci dari shift sebelumnya untuk validasi serah-terima laci
        $prevClosedShift = Shift::where('outlet_id', $outletId)
            ->where('status', 'CLOSED')
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first();

        $expectedDrawerCash = null;
        $handoverDiff = 0.0;
        if ($prevClosedShift && $prevClosedShift->closing_cash !== null) {
            $prevClosing = (float)$prevClosedShift->closing_cash;
            $depositStatus = $prevClosedShift->deposit_status;
            $prevDepAmt = ($depositStatus === 'APPROVED' || $depositStatus === 'PENDING')
                ? (float)($prevClosedShift->deposit_amount ?? 0)
                : 0.0;
            if ($prevDepAmt == 0.0) {
                $depTx = \App\Models\CashTransaction::where('shift_id', $prevClosedShift->id)
                    ->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                    ->whereIn('status', ['APPROVED', 'PENDING'])
                    ->sum('amount');
                if ($depTx > 0) {
                    $prevDepAmt = (float)$depTx;
                }
            }
            $expectedDrawerCash = max(0.0, round($prevClosing - $prevDepAmt, 2));
            if ($initialCashSource === 'DRAWER') {
                $handoverDiff = round($initialCash - $expectedDrawerCash, 2);
            }
        }

        $shiftNotes = $data['notes'] ?? null;
        if ($initialCashSource === 'KAS_BESAR' && $initialCash > 0 && $approverUser) {
            $kbNote = "Modal awal Rp " . number_format($initialCash, 0, ',', '.') . " diambil dari Kas Besar (Disetujui: {$approverUser->name} - {$approverUser->role_label}).";
            $shiftNotes = $shiftNotes ? ($shiftNotes . " | " . $kbNote) : $kbNote;
        } elseif ($initialCashSource === 'DRAWER' && $expectedDrawerCash !== null && abs($handoverDiff) >= 0.01) {
            $diffFormatted = $handoverDiff > 0
                ? ("+Rp " . number_format($handoverDiff, 0, ',', '.') . " (Lebih)")
                : ("-Rp " . number_format(abs($handoverDiff), 0, ',', '.') . " (Kurang)");
            $hoNote = "Selisih Serah Terima Kas Laci: {$diffFormatted} [Sisa Shift Lalu #{$prevClosedShift->id}: Rp " . number_format($expectedDrawerCash, 0, ',', '.') . ", Diterima Kasir: Rp " . number_format($initialCash, 0, ',', '.') . "].";
            $shiftNotes = $shiftNotes ? ($shiftNotes . " | " . $hoNote) : $hoNote;
        }

        $openedAt = !empty($data['opened_at']) ? \Carbon\Carbon::parse($data['opened_at']) : now();

        $shift = Shift::create([
            'business_id'       => $businessId,
            'outlet_id'         => $outletId,
            'shift_schedule_id' => $shiftScheduleId,
            'shift_name'        => $shiftName,
            'client_shift_id'   => $clientShiftId,
            'user_id'           => $user->id,
            'created_by'        => $user->id,
            'opened_at'         => $openedAt,
            'initial_cash'      => $initialCash,
            'system_cash'       => 0,
            'status'            => 'OPEN',
            'notes'             => $shiftNotes,
        ]);

        // 1. Jika shift pertama sekali di outlet ini (belum ada shift sebelumnya), catat sebagai Saldo Awal Kasir (Modal Pemilik)
        $isFirstShiftEver = !Shift::where('outlet_id', $outletId)->where('id', '!=', $shift->id)->exists();
        if ($isFirstShiftEver && $initialCash > 0) {
            try {
                $ct = \App\Models\CashTransaction::create([
                    'business_id'    => $businessId,
                    'outlet_id'      => $outletId,
                    'shift_id'       => $shift->id,
                    'transaction_no' => 'CT-SA-' . date('YmdHis') . '-' . rand(100, 999),
                    'name'           => "Saldo Awal Kasir Shift #{$shift->id} ({$shift->shift_name})",
                    'category'       => 'SALDO_AWAL',
                    'type'           => 'IN',
                    'activity_type'  => 'OPERATING',
                    'account'        => 'KAS_KECIL',
                    'payment_method' => 'CASH',
                    'amount'         => $initialCash,
                    'date'           => now()->toDateString(),
                    'status'         => 'APPROVED',
                    'notes'          => "Saldo modal awal kasir pertama kali untuk {$shift->shift_name} (Modal Awal Usaha, bukan dari Kas Besar).",
                    'user_id'        => $user->id,
                    'created_by'     => $user->id,
                    'approved_by'    => $user->id,
                    'approved_at'    => now(),
                ]);

                \App\Services\AccountingService::postCashTransaction($ct);
            } catch (\Throwable $e) {
                \Log::error("Failed to post journal for SALDO_AWAL shift #{$shift->id}: " . $e->getMessage());
            }
        } elseif ($initialCashSource === 'KAS_BESAR' && $initialCash > 0 && $approverUser) {
            // 2. Shift selanjutnya: Jika memilih ambil uang dari Kas Besar, buat transaksi kas & jurnal disetujui Manager/Owner
            try {
                $ct = \App\Models\CashTransaction::create([
                    'business_id'    => $businessId,
                    'outlet_id'      => $outletId,
                    'shift_id'       => $shift->id,
                    'transaction_no' => 'CT-KB-' . date('YmdHis') . '-' . rand(100, 999),
                    'name'           => "Ambil Modal Kasir Shift #{$shift->id} ({$shift->shift_name})",
                    'category'       => 'TARIK_MODAL_KASIR',
                    'type'           => 'OUT',
                    'activity_type'  => 'OPERATING',
                    'account'        => 'KAS_BESAR',
                    'payment_method' => 'CASH',
                    'amount'         => $initialCash,
                    'date'           => now()->toDateString(),
                    'status'         => 'APPROVED',
                    'notes'          => "Pengambilan modal awal kasir dari Kas Besar untuk {$shift->shift_name}. Disetujui oleh: {$approverUser->name} ({$approverUser->role_label})",
                    'user_id'        => $user->id,
                    'created_by'     => $user->id,
                    'approved_by'    => $approverUser->id,
                    'approved_at'    => now(),
                ]);

                \App\Services\AccountingService::postCashTransaction($ct);
            } catch (\Throwable $e) {
                \Log::error("Failed to post journal for TARIK_MODAL_KASIR shift #{$shift->id}: " . $e->getMessage());
            }
        } elseif (!$isFirstShiftEver && $initialCashSource === 'DRAWER' && $expectedDrawerCash !== null && abs($handoverDiff) >= 0.01) {
            // 3. Shift selanjutnya (Laci): Jika kas fisik laci yang diterima berbeda dengan sisa laci shift sebelumnya,
            // buat CashTransaction & jurnal penyesuaian selisih serah terima (Handover Discrepancy) secara eksplisit.
            try {
                $isShortage = $handoverDiff < 0;
                $diffAmt = abs($handoverDiff);
                $txType = $isShortage ? 'OUT' : 'IN';
                $trxNo = 'CT-ST-' . date('YmdHis') . '-' . rand(100, 999);
                $txName = $isShortage
                    ? "Selisih Kurang Serah Terima Kas Laci Shift #{$shift->id}"
                    : "Selisih Lebih Serah Terima Kas Laci Shift #{$shift->id}";

                $prevShiftId = $prevClosedShift ? $prevClosedShift->id : '-';
                $ct = \App\Models\CashTransaction::create([
                    'business_id'    => $businessId,
                    'outlet_id'      => $outletId,
                    'shift_id'       => $shift->id,
                    'transaction_no' => $trxNo,
                    'name'           => $txName,
                    'category'       => 'SELISIH_SERAH_TERIMA',
                    'type'           => $txType,
                    'activity_type'  => 'OPERATING',
                    'account'        => 'PETTY_CASH',
                    'payment_method' => 'CASH',
                    'amount'         => $diffAmt,
                    'date'           => now()->toDateString(),
                    'status'         => 'APPROVED',
                    'notes'          => "Penyesuaian selisih fisik kas laci saat serah terima pergantian shift dari Shift #{$prevShiftId} ke Shift #{$shift->id}. Sisa laci shift lalu: Rp " . number_format($expectedDrawerCash, 0, ',', '.') . ", kas fisik diterima kasir baru: Rp " . number_format($initialCash, 0, ',', '.') . " (" . ($isShortage ? 'Kurang' : 'Lebih') . " Rp " . number_format($diffAmt, 0, ',', '.') . ").",
                    'user_id'        => $user->id,
                    'created_by'     => $user->id,
                    'approved_by'    => $user->id,
                    'approved_at'    => now(),
                ]);

                \App\Services\AccountingService::postCashTransaction($ct);
            } catch (\Throwable $e) {
                \Log::error("Failed to post journal for SELISIH_SERAH_TERIMA shift #{$shift->id}: " . $e->getMessage());
            }
        }

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
     * Uses centralized AccountingService::calculateShiftExpenses with intelligent de-duplication.
     */
    protected function getShiftExpenses(Shift $shift): array
    {
        return \App\Services\AccountingService::calculateShiftExpenses($shift);
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
        $gofoodSales = 0.0;
        $shopeeSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $kasbonSales = 0.0;
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
            } elseif (str_contains($method, 'GOFOOD') || str_contains($method, 'GOJEK')) {
                $gofoodSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'SHOPEE')) {
                $shopeeSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'GRAB')) {
                $grabSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'TIKTOK')) {
                $tiktokSales = ($tiktokSales ?? 0.0) + $price;
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
            } elseif (in_array($method, ['KASBON', 'PIUTANG']) || str_contains($method, 'KASBON') || str_contains($method, 'PIUTANG')) {
                $kasbonSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
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

        // Pending Urgent Notes in this outlet
        $pendingUrgentNotes = UrgentNote::where('outlet_id', $shift->outlet_id)
            ->where('status', 'PENDING')
            ->with(['ingredient', 'menu'])
            ->get();
        $pendingUrgentCount = $pendingUrgentNotes->count();
        $pendingUrgentOrdersCount = $pendingUrgentNotes->pluck('order_number')->filter()->unique()->count();
        $pendingUrgentList = $pendingUrgentNotes->map(function ($un) {
            return [
                'id'           => $un->id,
                'order_number' => $un->order_number,
                'item_name'    => $un->item_name ?: ($un->ingredient?->name ?? $un->menu?->name ?? 'Bahan'),
                'pending_qty'  => (float)$un->pending_qty,
                'unit'         => $un->unit ?: ($un->ingredient?->unit_pakai ?? 'satuan'),
                'notes'        => $un->notes,
                'created_at'   => $un->created_at ? $un->created_at->format('Y-m-d H:i') : null,
            ];
        });

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

        $next = Shift::where('outlet_id', $shift->outlet_id)
            ->where(function ($q) use ($shift) {
                if ($shift->closed_at) {
                    $q->where('opened_at', '>=', $shift->closed_at)->orWhere('id', '>', $shift->id);
                } else {
                    $q->where('id', '>', $shift->id);
                }
            })
            ->where('id', '!=', $shift->id)
            ->first();
        $active = Shift::where('outlet_id', $shift->outlet_id)->where('status', 'OPEN')->where('id', '!=', $shift->id)->first();
        $hasNextShift = ($next !== null) || ($active !== null);
        $targetNext = $next ?: $active;

        $canDeposit = true;
        $cannotDepositReason = null;
        if ($shift->status !== 'CLOSED') {
            $canDeposit = false;
            $cannotDepositReason = 'Shift masih berjalan (belum ditutup).';
        } elseif ($shift->deposit_status === 'APPROVED' || ($shift->deposit_amount > 0 && $shift->deposit_status !== 'REJECTED')) {
            $canDeposit = false;
            $cannotDepositReason = 'Setoran sudah disetujui / sudah disetor.';
        } elseif ($shift->deposit_status === 'PENDING') {
            $canDeposit = false;
            $cannotDepositReason = 'Setoran sedang menunggu persetujuan Owner.';
        } elseif ($hasNextShift) {
            $canDeposit = false;
            $statusLabel = $targetNext->status === 'OPEN' ? 'sedang berjalan' : 'sudah dibuka';
            $nextTitle = $targetNext->shift_name ?: "Shift #{$targetNext->id}";
            $cannotDepositReason = "Shift selanjutnya ({$nextTitle}) {$statusLabel}.";
        }

        $shift->has_next_shift = $hasNextShift;
        $shift->next_shift_id = $targetNext?->id;
        $shift->next_shift_name = $targetNext?->shift_name;
        $shift->can_deposit = $canDeposit;
        $shift->cannot_deposit_reason = $cannotDepositReason;

        $shiftRecs = \App\Models\Receivable::where('shift_id', $shift->id)->with('payments')->get();
        $kbTotal = (float)$shiftRecs->sum('total_amount');
        $kbPaid = (float)$shiftRecs->sum('paid_amount');
        $kbRemaining = (float)$shiftRecs->sum('remaining_amount');
        $kbSettlementMethods = [];
        foreach ($shiftRecs as $rec) {
            foreach ($rec->payments as $p) {
                $pm = strtoupper(trim($p->payment_method ?? 'CASH'));
                $kbSettlementMethods[$pm] = ($kbSettlementMethods[$pm] ?? 0.0) + (float)$p->amount;
            }
        }

        $rpCollections = [
            'total'    => (float)$shiftRecPayments->sum('amount'),
            'cash'     => 0.0,
            'qris'     => 0.0,
            'transfer' => 0.0,
            'debit'    => 0.0,
            'other'    => 0.0,
        ];
        foreach ($shiftRecPayments as $rp) {
            $amt = (float)$rp->amount;
            $m = strtoupper(trim($rp->payment_method ?? 'CASH'));
            if ($m === 'CASH' || $m === 'TUNAI') $rpCollections['cash'] += $amt;
            elseif (str_contains($m, 'QRIS')) $rpCollections['qris'] += $amt;
            elseif (str_contains($m, 'TRANSFER')) $rpCollections['transfer'] += $amt;
            elseif (str_contains($m, 'DEBIT') || str_contains($m, 'EDC') || str_contains($m, 'CREDIT')) $rpCollections['debit'] += $amt;
            else $rpCollections['other'] += $amt;
        }

        if ($kasbonSales == 0 && $kbTotal > 0) {
            $kasbonSales = $kbTotal;
        }

        return response()->json([
            'shift'              => $shift,
            'has_next_shift'     => $hasNextShift,
            'next_shift_id'      => $targetNext?->id,
            'next_shift_name'    => $targetNext?->shift_name,
            'can_deposit'        => $canDeposit,
            'cannot_deposit_reason' => $cannotDepositReason,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'cash_sales'         => $cashSales,
            'qris_sales'         => $qrisSales,
            'grab_sales'         => $grabSales,
            'gofood_sales'       => $gofoodSales,
            'shopee_sales'       => $shopeeSales,
            'transfer_sales'     => $transferSales,
            'debit_sales'        => $debitSales,
            'other_sales'        => $otherSales,
            'kasbon_sales'       => $kasbonSales,
            'kasbon_settled'     => $kbPaid,
            'kasbon_remaining'   => $kbRemaining,
            'kasbon_settlement_methods' => $kbSettlementMethods,
            'receivable_collections'    => $rpCollections,
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
            'pending_urgent_count'        => $pendingUrgentCount,
            'pending_urgent_orders_count' => $pendingUrgentOrdersCount,
            'pending_urgent_notes'        => $pendingUrgentList,
        ]);
    }

    /**
     * Get thermal receipt format & financial summary for a shift (Struk Rekap Kas Closing Shift).
     */
    public function receipt(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk melihat struk shift di cabang outlet lain.'], 403);
            }
        }

        $shift->load(['outlet.business', 'user', 'closedByUser', 'depositSubmittedByUser', 'depositApprovedByUser', 'shiftSchedule']);

        // Transactions within this shift (protect against equal split duplicate counting)
        $paidTransactions = $shift->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->with(['menu', 'customer'])
            ->get();

        $shiftRecPayments = $this->getShiftReceivablePayments($shift);
        $hasShiftRecPayments = $shiftRecPayments->isNotEmpty();

        $cashSales = 0.0;
        $qrisSales = 0.0;
        $grabSales = 0.0;
        $gofoodSales = 0.0;
        $shopeeSales = 0.0;
        $tiktokSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $kasbonSales = 0.0;
        $otherSales = 0.0;
        $nonCashSales = 0.0;
        $totalSales = (float)$paidTransactions->sum('total_price');

        $paymentBreakdown = [];
        $ordersGrouped = [];

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
            } elseif (str_contains($method, 'GOFOOD') || str_contains($method, 'GOJEK')) {
                $gofoodSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'SHOPEE')) {
                $shopeeSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'GRAB')) {
                $grabSales += $price;
                $nonCashSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
            } elseif (str_contains($method, 'TIKTOK')) {
                $tiktokSales += $price;
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
            } elseif (in_array($method, ['KASBON', 'PIUTANG']) || str_contains($method, 'KASBON') || str_contains($method, 'PIUTANG')) {
                $kasbonSales += $price;
                $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;
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

            $ord = $t->order_number;
            if (!isset($ordersGrouped[$ord])) {
                $ordersGrouped[$ord] = [
                    'order_number'    => $ord,
                    'time'            => $t->created_at ? $t->created_at->format('H:i') : '-',
                    'total_price'     => 0.0,
                    'total_items'     => 0,
                    'payment_method'  => $method,
                    'customer_name'   => $t->customer_name ?: ($t->customer?->name ?? null),
                ];
            }
            $ordersGrouped[$ord]['total_price'] += $price;
            $ordersGrouped[$ord]['total_items'] += (int)$t->qty;
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

            $rec = $rp->receivable;
            $payNo = $rp->payment_no ?: ('PAY-' . $rp->id);
            $ordersGrouped[$payNo] = [
                'order_number'    => $payNo . ' (' . ($rec?->order_number ?: $rec?->receivable_no ?: 'Kasbon') . ')',
                'time'            => $rp->created_at ? $rp->created_at->format('H:i') : '-',
                'total_price'     => (float)$rp->amount,
                'total_items'     => 1,
                'payment_method'  => $rpMethod,
                'customer_name'   => 'Pelunasan: ' . ($rec?->customer_name ?: 'Pelanggan'),
            ];
        }

        $totalPaymentsReceived = $cashSales + $nonCashSales;
        if ($totalSales < $totalPaymentsReceived) {
            $totalSales = $totalPaymentsReceived;
        }

        $expData = $this->getShiftExpenses($shift);
        $expectedCash = max(0, (float)$shift->initial_cash + $cashSales - $expData['cash_expenses']);

        // Group actual menus sold
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

        $ordersList = array_values($ordersGrouped);
        $orderNumbers = array_keys($ordersGrouped);
        $orderRange = '-';
        if (count($orderNumbers) === 1) {
            $orderRange = $orderNumbers[0];
        } elseif (count($orderNumbers) > 1) {
            $orderRange = reset($orderNumbers) . ' s/d ' . end($orderNumbers);
        }

        $outlet = $shift->outlet;
        $business = $outlet?->business;

        $nonCashDetails = [
            'QRIS'        => $qrisSales,
            'TRANSFER'    => $transferSales,
            'DEBIT / EDC' => $debitSales,
        ];
        if ($grabSales > 0) $nonCashDetails['GRAB'] = $grabSales;
        if ($tiktokSales > 0) $nonCashDetails['TIKTOK'] = $tiktokSales;
        if ($gofoodSales > 0) $nonCashDetails['GOFOOD'] = $gofoodSales;
        if ($shopeeSales > 0) $nonCashDetails['SHOPEEFOOD'] = $shopeeSales;
        if ($kasbonSales > 0) $nonCashDetails['KASBON'] = $kasbonSales;
        if ($otherSales > 0) $nonCashDetails['LAINNYA'] = $otherSales;

        $closingCash = $shift->closing_cash !== null ? (float)$shift->closing_cash : null;
        $cashDiff = $shift->cash_difference !== null ? (float)$shift->cash_difference : ($closingCash !== null ? ($closingCash - $expectedCash) : 0);

        // Fetch any existing cash deposit transaction for this shift
        $lastDeposit = \App\Models\CashTransaction::where('shift_id', $shift->id)
            ->where('category', 'SETORAN_KASIR')
            ->latest('id')
            ->first();

        $depositAmount = (float)($shift->deposit_amount ?: ($lastDeposit ? $lastDeposit->amount : 0));
        $depositStatus = $shift->deposit_status ?: ($lastDeposit ? $lastDeposit->status : null);
        $depositDiff = $depositAmount > 0 && $closingCash !== null ? round($depositAmount - $closingCash, 2) : 0;

        return response()->json([
            'outlet_name'        => $outlet?->name ?? 'Outlet',
            'outlet_address'     => $outlet?->address ?? null,
            'outlet_phone'       => $outlet?->phone ?? null,
            'business_name'      => $business?->name ?? 'MOVA POS',
            'shift'              => $shift,
            'kasir_name'         => $shift->user?->name ?? '-',
            'closed_by_name'     => $shift->closedByUser?->name ?? null,
            'order_number_range' => $orderRange,
            'initial_cash'       => (float)$shift->initial_cash,
            'starting_cash'      => (float)$shift->initial_cash,
            'cash_total'         => $cashSales,
            'cash_sales'         => $cashSales,
            'total_expenses'     => $expData['cash_expenses'],
            'cash_out'           => $expData['cash_expenses'],
            'expected_cash'      => $expectedCash,
            'expected_ending_cash'=> $expectedCash,
            'closing_cash'       => $closingCash,
            'actual_ending_cash' => $closingCash,
            'cash_difference'    => $cashDiff,
            'difference'         => $cashDiff,
            'deposit_status'     => $depositStatus,
            'is_deposited'       => $depositStatus === 'APPROVED' || $depositAmount > 0,
            'is_deposit_pending' => $depositStatus === 'PENDING',
            'is_deposit_rejected'=> $depositStatus === 'REJECTED',
            'deposit_amount'     => $depositAmount,
            'deposit_account'    => $shift->deposit_account_label ?: ($shift->deposit_account ?: ($lastDeposit?->account === 'BANK_MAIN' ? 'Rekening Bank' : 'Kas Besar / Brankas')),
            'deposit_diff'       => $depositDiff,
            'last_deposit'       => $lastDeposit,
            'total_transactions' => count($ordersList),
            'total_sales'        => $totalSales,
            'kasbon_sales'       => $kasbonSales,
            'non_cash_total'     => $nonCashSales,
            'non_cash_details'   => $nonCashDetails,
            'orders'             => $ordersList,
            'items_sold'         => array_values($menuSummary),
            'expenses'           => $expData['expenses'],
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

            $drawerData = \App\Services\AccountingService::calculateShiftDrawerCash($shift);
            $expData = $drawerData['exp_data'];
            $closingCash = (float)$data['closing_cash'];
            $expectedCash = $drawerData['expected_cash'];
            $cashDiff = round($closingCash - $expectedCash, 2);

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
                    'outlet_id'     => $shift->outlet_id ?? $request->user()->outlet_id ?? Outlet::when($shift->business_id, fn($q) => $q->where('business_id', $shift->business_id))->value('id'),
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
            $pendingUrgentCount = UrgentNote::where('outlet_id', $shift->outlet_id)->where('status', 'PENDING')->count();
            if ($pendingUrgentCount > 0) {
                $carryUrgentNote = "Nota Urgent Dialihkan ke Shift Berikutnya: {$pendingUrgentCount} bahan/pesanan pending.";
                $combinedNotes = $combinedNotes ? $combinedNotes . "\n" . $carryUrgentNote : $carryUrgentNote;
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

            // Post double-entry journal for shift closing (cashier shortage/surplus & handover difference)
            $journalSynced = true;
            try {
                \App\Services\AccountingService::postShiftClosing($shift);
            } catch (\Throwable $e) {
                $journalSynced = false;
                \Illuminate\Support\Facades\Log::error("Gagal posting jurnal shift closing #{$shift->id}: " . $e->getMessage(), [
                    'shift_id'    => $shift->id,
                    'business_id' => $shift->business_id,
                    'trace'       => $e->getTraceAsString(),
                ]);
            }

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
                'pending_urgent_count' => $pendingUrgentCount,
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
            ->with(['menu.recipes.items.ingredient', 'user', 'outlet', 'shift', 'creator', 'updater', 'cancelledByUser', 'voidRequestedByUser', 'voidApprovedByUser', 'voidRejectedByUser', 'modifiers.ingredient', 'discount', 'urgentNotes.ingredient', 'customer', 'receivable.payments.receiver'])
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
            'receivable_payments'        => $shiftRecPayments,
        ]);
    }

    /**
     * Cashier submits cash deposit from closing shift (Status: PENDING approval by Owner/Manager).
     */
    public function deposit(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk mengelola shift di cabang outlet lain.'], 403);
            }
        }

        if ($shift->status !== 'CLOSED') {
            return response()->json(['message' => 'Setoran hanya dapat diajukan untuk shift yang telah ditutup.'], 422);
        }

        // Validasi: Jika shift selanjutnya sudah dibuka atau sedang berjalan di outlet ini, shift sebelumnya TIDAK BISA menyetor
        $nextShift = Shift::where('outlet_id', $shift->outlet_id)
            ->where(function ($q) use ($shift) {
                if ($shift->closed_at) {
                    $q->where('opened_at', '>=', $shift->closed_at)
                      ->orWhere('id', '>', $shift->id);
                } else {
                    $q->where('id', '>', $shift->id);
                }
            })
            ->where('id', '!=', $shift->id)
            ->orderBy('id', 'asc')
            ->first();

        $activeShift = Shift::where('outlet_id', $shift->outlet_id)
            ->where('status', 'OPEN')
            ->where('id', '!=', $shift->id)
            ->first();

        if ($nextShift || $activeShift) {
            $targetNext = $nextShift ?: $activeShift;
            $statusLabel = $targetNext->status === 'OPEN' ? 'sedang berjalan' : 'sudah dibuka';
            $nextTitle = $targetNext->shift_name ?: "Shift #{$targetNext->id}";
            return response()->json([
                'message' => "Shift #{$shift->id} tidak dapat menyetor uang kasir karena shift selanjutnya ({$nextTitle}) {$statusLabel}."
            ], 422);
        }

        $data = $request->validate([
            'amount'  => 'required|numeric|min:0.01',
            'account' => 'nullable|string|in:KAS_BESAR,BANK_MAIN',
            'notes'   => 'nullable|string|max:500',
            'date'    => 'nullable|date',
        ]);

        $businessId = (int)($shift->business_id ?: ($user?->business_id ?: $shift->outlet?->business_id));
        $depositDate = $data['date'] ?? ($shift->closed_at ? $shift->closed_at->toDateString() : now()->toDateString());
        $account = $data['account'] ?? 'KAS_BESAR';
        $amount = (float)$data['amount'];
        $notes = $data['notes'] ?? "Setoran Closing Shift #{$shift->id} ({$shift->shift_name}) ke " . ($account === 'BANK_MAIN' ? 'Rekening Bank' : 'Kas Besar');

        $result = DB::transaction(function () use ($businessId, $shift, $depositDate, $account, $amount, $notes, $user) {
            $transactionNo = \App\Models\CashTransaction::generateTransactionNo($businessId, $depositDate);

            // Find existing transaction for this shift if resubmitted
            $trx = \App\Models\CashTransaction::where('shift_id', $shift->id)
                ->where('category', 'SETORAN_KASIR')
                ->first();

            $accountLabel = $account === 'BANK_MAIN' ? 'Rekening Bank' : 'Kas Besar';

            if ($trx) {
                $trx->update([
                    'business_id'      => $businessId,
                    'outlet_id'        => $shift->outlet_id,
                    'date'             => $depositDate,
                    'type'             => 'IN',
                    'activity_type'    => 'OPERATING',
                    'category'         => 'SETORAN_KASIR',
                    'status'           => 'PENDING',
                    'name'             => "Setoran Kasir Shift #{$shift->id} ke {$accountLabel}",
                    'amount'           => $amount,
                    'account'          => $account,
                    'payment_method'   => 'CASH',
                    'notes'            => $notes,
                    'user_id'          => $user->id,
                    'approved_by'      => null,
                    'approved_at'      => null,
                    'rejection_reason' => null,
                    'updated_by'       => $user->id,
                ]);
            } else {
                $trx = \App\Models\CashTransaction::create([
                    'business_id'      => $businessId,
                    'outlet_id'        => $shift->outlet_id,
                    'shift_id'         => $shift->id,
                    'transaction_no'   => $transactionNo,
                    'date'             => $depositDate,
                    'type'             => 'IN',
                    'activity_type'    => 'OPERATING',
                    'category'         => 'SETORAN_KASIR',
                    'status'           => 'PENDING',
                    'name'             => "Setoran Kasir Shift #{$shift->id} ke {$accountLabel}",
                    'amount'           => $amount,
                    'account'          => $account,
                    'payment_method'   => 'CASH',
                    'notes'            => $notes,
                    'user_id'          => $user->id,
                    'created_by'       => $user->id,
                ]);
            }

            // Append audit note to shift
            $depositNote = "Pengajuan Setor: Rp " . number_format($amount, 0, ',', '.') . " ke {$accountLabel} (Menunggu Approval Owner)";
            $combinedNotes = $shift->notes ? $shift->notes . "\n" . $depositNote : $depositNote;

            $shift->update([
                'deposit_status'           => 'PENDING',
                'deposit_amount'           => $amount,
                'deposit_account'          => $account,
                'deposit_notes'            => $notes,
                'deposit_submitted_at'     => now(),
                'deposit_submitted_by'     => $user->id,
                'deposit_approved_at'      => null,
                'deposit_approved_by'      => null,
                'deposit_rejection_reason' => null,
                'notes'                    => $combinedNotes,
            ]);

            // Ensure no journal is recorded yet while PENDING
            \App\Services\AccountingService::deleteReferenceEntry('CASH_TRANSACTION', $trx->id, $businessId);

            return $trx;
        });

        $depositDiff = $shift->closing_cash !== null ? round($amount - (float)$shift->closing_cash, 2) : 0.0;

        return response()->json([
            'message'            => 'Setoran kasir sebesar Rp ' . number_format($amount, 0, ',', '.') . ' berhasil diajukan dan menunggu persetujuan Owner / Manager.',
            'cash_transaction'   => $result,
            'deposit_amount'     => $amount,
            'deposit_status'     => 'PENDING',
            'deposit_diff'       => $depositDiff,
            'is_deposited'       => false,
            'is_deposit_pending' => true,
            'shift'              => $shift->fresh(['user', 'closedByUser', 'outlet', 'depositSubmittedByUser', 'depositApprovedByUser']),
        ], 201);
    }

    /**
     * Owner / Outlet Manager approves the cashier cash deposit.
     * Posts double-entry journal transfer to Kas Besar / Bank at this moment.
     */
    public function approveDeposit(Request $request, Shift $shift)
    {
        $user = $request->user();
        $isOwnerOrManager = $user && ($user->isPlatformAdmin() || $user->isOwnerBisnis() || $user->isOwnerOutlet());

        if (!$isOwnerOrManager) {
            return response()->json(['message' => 'Akses ditolak: Hanya Owner Bisnis atau Manager Outlet yang berhak menyetujui setoran kasir.'], 403);
        }

        if ($user->isOwnerOutlet() && (int)$shift->outlet_id !== (int)$user->outlet_id) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses untuk menyetujui setoran shift di cabang lain.'], 403);
        }

        if ($shift->deposit_status === 'APPROVED') {
            return response()->json(['message' => 'Setoran shift ini sudah disetujui sebelumnya.'], 422);
        }

        $businessId = (int)($shift->business_id ?: ($user?->business_id ?: $shift->outlet?->business_id));
        $amount = (float)($shift->deposit_amount ?: $shift->closing_cash ?: 0);
        $account = $shift->deposit_account ?: 'KAS_BESAR';
        $depositDate = $shift->closed_at ? $shift->closed_at->toDateString() : now()->toDateString();

        $result = DB::transaction(function () use ($businessId, $shift, $amount, $account, $depositDate, $user) {
            $accountLabel = $account === 'BANK_MAIN' ? 'Rekening Bank' : 'Kas Besar';

            // Find or create the CashTransaction
            $trx = \App\Models\CashTransaction::where('shift_id', $shift->id)
                ->where('category', 'SETORAN_KASIR')
                ->first();

            if (!$trx) {
                $transactionNo = \App\Models\CashTransaction::generateTransactionNo($businessId, $depositDate);
                $trx = \App\Models\CashTransaction::create([
                    'business_id'      => $businessId,
                    'outlet_id'        => $shift->outlet_id,
                    'shift_id'         => $shift->id,
                    'transaction_no'   => $transactionNo,
                    'date'             => $depositDate,
                    'type'             => 'IN',
                    'activity_type'    => 'OPERATING',
                    'category'         => 'SETORAN_KASIR',
                    'status'           => 'APPROVED',
                    'name'             => "Setoran Kasir Shift #{$shift->id} ke {$accountLabel}",
                    'amount'           => $amount,
                    'account'          => $account,
                    'payment_method'   => 'CASH',
                    'notes'            => $shift->deposit_notes ?: "Setoran Shift #{$shift->id} disetujui oleh {$user->name}",
                    'user_id'          => $shift->user_id,
                    'approved_by'      => $user->id,
                    'approved_at'      => now(),
                    'rejection_reason' => null,
                    'created_by'       => $user->id,
                ]);
            } else {
                $trx->update([
                    'status'           => 'APPROVED',
                    'amount'           => $amount,
                    'account'          => $account,
                    'approved_by'      => $user->id,
                    'approved_at'      => now(),
                    'rejection_reason' => null,
                    'updated_by'       => $user->id,
                ]);
            }

            // Update shift status
            $approvalNote = "Setoran DISETUJUI oleh {$user->name} pada " . now()->format('d/m/Y H:i') . " (Rp " . number_format($amount, 0, ',', '.') . " ke {$accountLabel})";
            $combinedNotes = $shift->notes ? $shift->notes . "\n" . $approvalNote : $approvalNote;

            $shift->update([
                'deposit_status'           => 'APPROVED',
                'deposit_amount'           => $amount,
                'deposit_account'          => $account,
                'deposit_approved_at'      => now(),
                'deposit_approved_by'      => $user->id,
                'deposit_rejection_reason' => null,
                'notes'                    => $combinedNotes,
            ]);

            // Now post double-entry journal (transfer from Kas Kecil to Kas Besar / Bank)
            try {
                \App\Services\AccountingService::postCashTransaction($trx);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Gagal posting jurnal deposit approval: " . $e->getMessage());
            }

            return $trx;
        });

        return response()->json([
            'message'            => 'Setoran Kasir Shift #' . $shift->id . ' senilai Rp ' . number_format($amount, 0, ',', '.') . ' telah berhasil disetujui. Dana resmi dibukukan ke Kas Besar/Bank.',
            'cash_transaction'   => $result,
            'deposit_status'     => 'APPROVED',
            'is_deposited'       => true,
            'is_deposit_pending' => false,
            'shift'              => $shift->fresh(['user', 'closedByUser', 'outlet', 'depositSubmittedByUser', 'depositApprovedByUser']),
        ]);
    }

    /**
     * Owner / Outlet Manager rejects the cashier cash deposit.
     */
    public function rejectDeposit(Request $request, Shift $shift)
    {
        $user = $request->user();
        $isOwnerOrManager = $user && ($user->isPlatformAdmin() || $user->isOwnerBisnis() || $user->isOwnerOutlet());

        if (!$isOwnerOrManager) {
            return response()->json(['message' => 'Akses ditolak: Hanya Owner Bisnis atau Manager Outlet yang berhak menolak setoran kasir.'], 403);
        }

        if ($user->isOwnerOutlet() && (int)$shift->outlet_id !== (int)$user->outlet_id) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses untuk mengelola shift di cabang lain.'], 403);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $reason = $data['reason'];
        $businessId = (int)($shift->business_id ?: ($user?->business_id ?: $shift->outlet?->business_id));

        DB::transaction(function () use ($businessId, $shift, $reason, $user) {
            // Find the CashTransaction if exists
            $trx = \App\Models\CashTransaction::where('shift_id', $shift->id)
                ->where('category', 'SETORAN_KASIR')
                ->first();

            if ($trx) {
                $trx->update([
                    'status'           => 'REJECTED',
                    'rejection_reason' => $reason,
                    'approved_by'      => $user->id,
                    'approved_at'      => now(),
                    'updated_by'       => $user->id,
                ]);

                // Delete any journal entry if it had one
                \App\Services\AccountingService::deleteReferenceEntry('CASH_TRANSACTION', $trx->id, $businessId);
            }

            // Update shift
            $rejectionNote = "Setoran DITOLAK oleh {$user->name} pada " . now()->format('d/m/Y H:i') . ". Alasan: {$reason}";
            $combinedNotes = $shift->notes ? $shift->notes . "\n" . $rejectionNote : $rejectionNote;

            $shift->update([
                'deposit_status'           => 'REJECTED',
                'deposit_rejection_reason' => $reason,
                'notes'                    => $combinedNotes,
            ]);
        });

        return response()->json([
            'message'            => "Setoran Kasir Shift #{$shift->id} ditolak dengan catatan: {$reason}",
            'deposit_status'     => 'REJECTED',
            'is_deposited'       => false,
            'is_deposit_pending' => false,
            'shift'              => $shift->fresh(['user', 'closedByUser', 'outlet', 'depositSubmittedByUser', 'depositApprovedByUser']),
        ]);
    }

    /**
     * Sinkronkan jurnal penarikan modal awal kasir dari Kas Besar untuk shift-shift yang belum tercatat.
     */
    public function syncInitialCashJournals(Request $request)
    {
        $user = $request->user();
        $businessId = $user ? (int)($user->business_id ?: 1) : 1;

        $shifts = Shift::where('business_id', $businessId)
            ->where('initial_cash', '>', 0)
            ->orderBy('id', 'asc')
            ->get();

        $synced = 0;
        foreach ($shifts as $shift) {
            // Cek shift sebelumnya pada outlet yang sama
            $prev = Shift::where('outlet_id', $shift->outlet_id)
                ->where('status', 'CLOSED')
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

            $existing = \App\Models\CashTransaction::where('shift_id', $shift->id)
                ->whereIn('category', ['TARIK_MODAL_KASIR', 'SALDO_AWAL', 'INITIAL_BALANCE'])
                ->first();

            // 1. KASUS SHIFT PERTAMA SEKALI (Tidak ada shift sebelumnya)
            // Saldo awal kasir shift pertama SUDAH dibukukan otomatis oleh sistem sebagai Modal Kasir Awal (INITIAL_BALANCE_CASH).
            // Hapus duplikasi CashTransaction jika ada agar saldo awal tidak terhitung ganda (double).
            if (!$prev) {
                if ($existing) {
                    \App\Services\AccountingService::deleteReferenceEntry('CASH_TRANSACTION', $existing->id, $shift->business_id ?: $businessId);
                    $existing->delete();
                }
                continue;
            }

            // 2. KASUS SHIFT SELANJUTNYA ($prev ada)
            if ($existing) {
                \App\Services\AccountingService::postCashTransaction($existing);
                continue;
            }

            // Hitung sisa kas laci shift lalu
            $prevRemaining = 0.0;
            if ($prev && $prev->closing_cash !== null) {
                $prevDeposit = $prev->deposit_amount !== null ? (float)$prev->deposit_amount : 0.0;
                $prevRemaining = max(0.0, (float)$prev->closing_cash - $prevDeposit);
            }

            // Jika laci shift lalu kosong (Rp 0) atau ada catatan Kas Besar:
            if ($prevRemaining == 0.0 || str_contains($shift->notes ?? '', 'Kas Besar')) {
                $amount = (float)$shift->initial_cash;
                $approverId = $shift->created_by ?: ($shift->user_id ?: 1);
                $txDate = $shift->opened_at ? substr($shift->opened_at, 0, 10) : now()->toDateString();

                $ct = \App\Models\CashTransaction::create([
                    'business_id'    => $shift->business_id ?: $businessId,
                    'outlet_id'      => $shift->outlet_id,
                    'shift_id'       => $shift->id,
                    'transaction_no' => 'CT-KB-' . date('YmdHis', strtotime($shift->opened_at ?: now())) . '-' . rand(100, 999),
                    'name'           => "Ambil Modal Kasir Shift #{$shift->id} ({$shift->shift_name})",
                    'category'       => 'TARIK_MODAL_KASIR',
                    'type'           => 'OUT',
                    'activity_type'  => 'OPERATING',
                    'account'        => 'KAS_BESAR',
                    'payment_method' => 'CASH',
                    'amount'         => $amount,
                    'date'           => $txDate,
                    'status'         => 'APPROVED',
                    'notes'          => "Penarikan modal awal kasir dari Kas Besar untuk {$shift->shift_name}.",
                    'user_id'        => $shift->user_id,
                    'created_by'     => $approverId,
                    'approved_by'    => $approverId,
                    'approved_at'    => $shift->opened_at ?: now(),
                ]);

                \App\Services\AccountingService::postCashTransaction($ct);
                $synced++;
            } elseif ($prevRemaining > 0 && !str_contains($shift->notes ?? '', 'Kas Besar')) {
                // 3. KASUS SELISIH SERAH TERIMA LACI ($prevRemaining > 0 dan sisa laci berbeda)
                $hoDiff = round((float)$shift->initial_cash - $prevRemaining, 2);
                if (abs($hoDiff) >= 0.01) {
                    $existingHo = \App\Models\CashTransaction::where('shift_id', $shift->id)
                        ->where('category', 'SELISIH_SERAH_TERIMA')
                        ->first();
                    if ($existingHo) {
                        \App\Services\AccountingService::postCashTransaction($existingHo);
                    } else {
                        $isShortage = $hoDiff < 0;
                        $diffAmt = abs($hoDiff);
                        $txType = $isShortage ? 'OUT' : 'IN';
                        $trxNo = 'CT-ST-' . date('YmdHis', strtotime($shift->opened_at ?: now())) . '-' . rand(100, 999);
                        $txName = $isShortage
                            ? "Selisih Kurang Serah Terima Kas Laci Shift #{$shift->id}"
                            : "Selisih Lebih Serah Terima Kas Laci Shift #{$shift->id}";

                        $approverId = $shift->created_by ?: ($shift->user_id ?: 1);
                        $txDate = $shift->opened_at ? substr($shift->opened_at, 0, 10) : now()->toDateString();

                        $ct = \App\Models\CashTransaction::create([
                            'business_id'    => $shift->business_id ?: $businessId,
                            'outlet_id'      => $shift->outlet_id,
                            'shift_id'       => $shift->id,
                            'transaction_no' => $trxNo,
                            'name'           => $txName,
                            'category'       => 'SELISIH_SERAH_TERIMA',
                            'type'           => $txType,
                            'activity_type'  => 'OPERATING',
                            'account'        => 'PETTY_CASH',
                            'payment_method' => 'CASH',
                            'amount'         => $diffAmt,
                            'date'           => $txDate,
                            'status'         => 'APPROVED',
                            'notes'          => "Penyesuaian selisih fisik kas laci saat serah terima pergantian shift dari Shift #{$prev->id} ke Shift #{$shift->id}. Sisa laci shift lalu: Rp " . number_format($prevRemaining, 0, ',', '.') . ", kas diterima kasir: Rp " . number_format($shift->initial_cash, 0, ',', '.') . " (" . ($isShortage ? 'Kurang' : 'Lebih') . " Rp " . number_format($diffAmt, 0, ',', '.') . ").",
                            'user_id'        => $shift->user_id,
                            'created_by'     => $approverId,
                            'approved_by'    => $approverId,
                            'approved_at'    => $shift->opened_at ?: now(),
                        ]);

                        \App\Services\AccountingService::postCashTransaction($ct);
                        $synced++;
                    }
                }
            }
        }

        return response()->json([
            'message' => "Berhasil menyinkronkan {$synced} jurnal modal & serah-terima kasir.",
            'synced_count' => $synced,
        ]);
    }
}
