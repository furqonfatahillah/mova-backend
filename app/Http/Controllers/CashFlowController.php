<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\Transaction;
use App\Models\StockMovement;
use App\Models\OperatingExpense;
use App\Models\WasteLog;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashFlowController extends Controller
{
    /**
     * Comprehensive Real Cash Flow Statement (Arus Kas Nyata: Kas Fisik vs Laba Akrual)
     */
    public function statement(Request $request)
    {
        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-d'));
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        // Payment method parsing (supports preset combinations, comma-separated lists, and individual methods)
        $rawPm = $request->input('payment_method', 'ALL');
        $pmList = [];
        if (is_array($rawPm)) {
            $pmList = array_map(fn($v) => strtoupper(trim($v)), $rawPm);
        } elseif (is_string($rawPm) && !empty($rawPm) && strtoupper($rawPm) !== 'ALL') {
            $pmList = array_map(fn($v) => strtoupper(trim($v)), explode(',', $rawPm));
        }
        $isPmFiltered = count($pmList) > 0 && !in_array('ALL', $pmList);
        $pmFilter = $isPmFiltered ? implode(',', $pmList) : 'ALL';

        // Shift filter parsing (supports 'ALL', single ID, array of IDs, comma-separated IDs, or shift names)
        $rawShift = $request->input('shift_id', $request->input('shift_ids', 'ALL'));
        $shiftFilterIds = [];
        $isShiftFiltered = false;

        if (is_array($rawShift)) {
            $shiftFilterIds = array_values(array_filter(array_map('intval', $rawShift)));
            $isShiftFiltered = count($shiftFilterIds) > 0;
        } elseif (is_string($rawShift) && !empty($rawShift) && strtoupper($rawShift) !== 'ALL') {
            $parts = explode(',', $rawShift);
            $numericIds = [];
            $nameFilters = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if (is_numeric($p)) {
                    $numericIds[] = (int)$p;
                } elseif (!empty($p)) {
                    $nameFilters[] = $p;
                }
            }

            if (!empty($nameFilters)) {
                $matchedShiftIds = \App\Models\Shift::where(function($q) use ($nameFilters) {
                    foreach ($nameFilters as $nf) {
                        $q->orWhere('shift_name', 'like', "%{$nf}%");
                    }
                })
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->whereBetween('opened_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
                ->pluck('id')
                ->all();
                $numericIds = array_unique(array_merge($numericIds, $matchedShiftIds));
            }

            $shiftFilterIds = array_values(array_unique($numericIds));
            $isShiftFiltered = count($shiftFilterIds) > 0;
        }

        // Chronological shifts for inter-shift handover discrepancies (selisih antar kasir / shift)
        $chronologicalShifts = \App\Models\Shift::with('user')
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->whereDate('opened_at', '<=', $to)
            ->orderBy('opened_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $interShiftDiffMap = [];
        $prevClosed = null;
        foreach ($chronologicalShifts as $cs) {
            $diffAntar = 0.0;
            $prevClosing = null;
            $prevName = null;
            if ($prevClosed && $prevClosed->closing_cash !== null) {
                $prevClosing = (float)$prevClosed->closing_cash;
                $prevName = $prevClosed->shift_name;
                $diffAntar = round((float)$cs->initial_cash - $prevClosing, 2);
            }
            $interShiftDiffMap[$cs->id] = [
                'inter_shift_diff'    => $diffAntar,
                'prev_shift_name'     => $prevName,
                'prev_closing_cash'   => $prevClosing,
            ];
            if ($cs->status === 'CLOSED' && $cs->closing_cash !== null) {
                $prevClosed = $cs;
            }
        }

        // Available shifts in this period & outlet for frontend filter selection
        $availableShiftsQuery = \App\Models\Shift::with('user')
            ->where(function($q) use ($from, $to) {
                $q->where(function ($sub) use ($from, $to) {
                    $sub->whereDate('opened_at', '>=', $from)
                        ->whereDate('opened_at', '<=', $to);
                })->orWhere(function ($sub) use ($from, $to) {
                    $sub->whereDate('closed_at', '>=', $from)
                        ->whereDate('closed_at', '<=', $to);
                });
            })
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->orderBy('opened_at', 'desc');

        $availableShifts = $availableShiftsQuery->get()->map(function($s) use ($interShiftDiffMap) {
            $discrepancy = $s->cash_difference !== null 
                ? (float)$s->cash_difference 
                : ($s->closing_cash !== null && $s->system_cash !== null ? round((float)$s->closing_cash - (float)$s->system_cash, 2) : 0);
            $interData = $interShiftDiffMap[$s->id] ?? [
                'inter_shift_diff'  => 0.0,
                'prev_shift_name'   => null,
                'prev_closing_cash' => null,
            ];

            $sysSalesRaw = (float)$s->system_cash;
            // Expected drawer cash: if closed with discrepancy recorded, expected cash = closing_cash - discrepancy; otherwise initial + sales
            $expectedCash = ($s->status === 'CLOSED' && $s->closing_cash !== null && $s->cash_difference !== null)
                ? round((float)$s->closing_cash - (float)$s->cash_difference, 2)
                : ((float)$s->initial_cash + $sysSalesRaw);

            return [
                'id'                 => $s->id,
                'shift_name'         => $s->shift_name,
                'opened_at'          => $s->opened_at,
                'closed_at'          => $s->closed_at,
                'status'             => $s->status,
                'cashier_name'       => $s->user?->name ?: 'Kasir',
                'initial_cash'       => (float)$s->initial_cash,
                'closing_cash'       => (float)$s->closing_cash,
                'system_cash'        => (float)$expectedCash,
                'system_sales'       => $sysSalesRaw,
                'cash_difference'    => $discrepancy,
                'inter_shift_diff'   => $interData['inter_shift_diff'],
                'prev_shift_name'    => $interData['prev_shift_name'],
                'prev_closing_cash'  => $interData['prev_closing_cash'],
                'notes'              => $s->notes,
                'date'               => $s->opened_at ? substr($s->opened_at, 0, 10) : null,
            ];
        })->values();

        // Helper closures for payment method matching (including combined presets)
        $applyPmFilter = function($query, $column = 'payment_method') use ($pmList, $isPmFiltered) {
            if (!$isPmFiltered) return;

            $query->where(function($q) use ($column, $pmList) {
                foreach ($pmList as $idx => $pm) {
                    $clause = function($subQ) use ($column, $pm) {
                        if ($pm === 'NON_CASH' || $pm === 'ALL_NON_CASH') {
                            $subQ->where(function($inner) use ($column) {
                                $inner->where($column, 'like', '%QRIS%')
                                      ->orWhere($column, 'like', '%GRAB%')
                                      ->orWhere($column, 'like', '%GOFOOD%')
                                      ->orWhere($column, 'like', '%SHOPEE%')
                                      ->orWhere($column, 'like', '%TIKTOK%')
                                      ->orWhere($column, 'like', '%TRANSFER%')
                                      ->orWhere($column, 'like', '%DEBIT%')
                                      ->orWhere($column, 'like', '%EDC%')
                                      ->orWhere($column, 'like', '%CREDIT%')
                                      ->orWhere($column, 'like', '%KARTU%')
                                      ->orWhere($column, 'like', '%DIGITAL%')
                                      ->orWhere($column, 'like', '%ECOMMERCE%');
                            });
                        } elseif ($pm === 'CASH_ALL' || $pm === 'CASH_AND_PETTY') {
                            $subQ->where(function($inner) use ($column) {
                                $inner->whereIn($column, ['CASH', 'TUNAI', 'PETTY_CASH'])
                                      ->orWhere($column, 'like', '%TUNAI%')
                                      ->orWhere($column, 'like', '%CASH%')
                                      ->orWhere($column, 'like', '%PETTY%');
                            });
                        } elseif ($pm === 'CASH' || $pm === 'TUNAI') {
                            $subQ->where(function($inner) use ($column) {
                                $inner->whereIn($column, ['CASH', 'TUNAI'])
                                      ->orWhere($column, 'like', '%TUNAI%')
                                      ->orWhere($column, 'like', '%CASH%');
                            });
                        } elseif ($pm === 'QRIS') {
                            $subQ->where($column, 'like', '%QRIS%');
                        } elseif ($pm === 'GRAB' || $pm === 'ECOMMERCE' || $pm === 'ECOMMERCE_ALL') {
                            $subQ->where(function($inner) use ($column) {
                                $inner->where($column, 'like', '%GRAB%')
                                      ->orWhere($column, 'like', '%GOFOOD%')
                                      ->orWhere($column, 'like', '%SHOPEE%')
                                      ->orWhere($column, 'like', '%TIKTOK%')
                                      ->orWhere($column, 'like', '%ECOMMERCE%')
                                      ->orWhere($column, 'like', '%DELIVERY%')
                                      ->orWhere($column, 'like', '%ONLINE%');
                            });
                        } elseif ($pm === 'TRANSFER') {
                            $subQ->where(function($inner) use ($column) {
                                $inner->where($column, 'like', '%TRANSFER%')
                                      ->orWhere($column, 'like', '%BANK%')
                                      ->orWhere($column, 'like', '%BCA%')
                                      ->orWhere($column, 'like', '%BRI%')
                                      ->orWhere($column, 'like', '%MANDIRI%');
                            });
                        } elseif ($pm === 'DEBIT' || $pm === 'EDC') {
                            $subQ->where(function($inner) use ($column) {
                                $inner->where($column, 'like', '%DEBIT%')
                                      ->orWhere($column, 'like', '%EDC%')
                                      ->orWhere($column, 'like', '%CREDIT%')
                                      ->orWhere($column, 'like', '%KARTU%');
                            });
                        } elseif ($pm === 'PETTY_CASH') {
                            $subQ->where(function($inner) use ($column) {
                                $inner->where($column, 'PETTY_CASH')
                                      ->orWhere($column, 'like', '%PETTY%')
                                      ->orWhere($column, 'like', '%KECIL%');
                            });
                        } else {
                            $subQ->where($column, $pm);
                        }
                    };

                    if ($idx === 0) {
                        $q->where($clause);
                    } else {
                        $q->orWhere($clause);
                    }
                }
            });
        };

        // Helper closures for shift filtering
        $applyShiftFilter = function($query, $column = 'shift_id') use ($shiftFilterIds, $isShiftFiltered) {
            if (!$isShiftFiltered) return;
            $query->whereIn($column, $shiftFilterIds);
        };

        // =========================================================================
        // 1. ARUS KAS DARI AKTIVITAS OPERASI (OPERATING CASH FLOW / OCF)
        // =========================================================================
        
        // A. Penerimaan Kas dari Penjualan Kasir Langsung (Direct Cash/Instant Sales Inflows)
        $trxQuery = Transaction::where('status', 'PAID')
            ->whereBetween('date', [$from, $to])
            ->select(['payment_method', 'total_price', 'shift_id']);
        if ($outletId) {
            $trxQuery->where('outlet_id', $outletId);
        }
        $applyPmFilter($trxQuery, 'payment_method');
        $applyShiftFilter($trxQuery, 'shift_id');
        $transactions = $trxQuery->get();

        $cashSales = 0.0;
        $qrisSales = 0.0;
        $grabSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $otherSales = 0.0;
        $newKasbonTotal = 0.0;

        foreach ($transactions as $t) {
            $amt = (float)$t->total_price;
            $method = strtoupper(trim($t->payment_method ?: 'CASH'));

            if (in_array($method, ['KASBON', 'PIUTANG'])) {
                $newKasbonTotal += $amt;
                continue;
            }

            if ($method === 'CASH' || $method === 'TUNAI') {
                $cashSales += $amt;
            } elseif (str_contains($method, 'QRIS')) {
                $qrisSales += $amt;
            } elseif (str_contains($method, 'GRAB') || str_contains($method, 'GOFOOD') || str_contains($method, 'SHOPEE') || str_contains($method, 'TIKTOK')) {
                $grabSales += $amt;
            } elseif (str_contains($method, 'TRANSFER')) {
                $transferSales += $amt;
            } elseif (str_contains($method, 'DEBIT') || str_contains($method, 'EDC') || str_contains($method, 'CREDIT')) {
                $debitSales += $amt;
            } else {
                $otherSales += $amt;
            }
        }

        $totalDirectSalesReceipts = $cashSales + $qrisSales + $grabSales + $transferSales + $debitSales + $otherSales;

        // B. Penerimaan Kas dari Pembayaran Kasbon Pelanggan (Receivable Collections)
        $recPayQuery = ReceivablePayment::with(['receivable', 'outlet', 'receiver'])
            ->whereBetween('payment_date', [$from, $to])
            ->select(['id', 'payment_no', 'receivable_id', 'business_id', 'outlet_id', 'shift_id', 'payment_date', 'amount', 'payment_method', 'reference_no', 'notes', 'received_by']);
        if ($outletId) {
            $recPayQuery->where('outlet_id', $outletId);
        }
        $applyPmFilter($recPayQuery, 'payment_method');
        $applyShiftFilter($recPayQuery, 'shift_id');
        $receivablePayments = $recPayQuery->get();

        $receivableCashIn = 0.0;
        $recPayCash = 0.0;
        $recPayTransfer = 0.0;
        $recPayQris = 0.0;
        $recPayDebit = 0.0;
        $recPayOther = 0.0;

        foreach ($receivablePayments as $rp) {
            $rpAmt = (float)$rp->amount;
            $receivableCashIn += $rpAmt;
            $rpMethod = strtoupper(trim($rp->payment_method ?: 'CASH'));

            if ($rpMethod === 'CASH' || $rpMethod === 'TUNAI') {
                $recPayCash += $rpAmt;
            } elseif (str_contains($rpMethod, 'TRANSFER')) {
                $recPayTransfer += $rpAmt;
            } elseif (str_contains($rpMethod, 'QRIS')) {
                $recPayQris += $rpAmt;
            } elseif (str_contains($rpMethod, 'DEBIT') || str_contains($rpMethod, 'EDC')) {
                $recPayDebit += $rpAmt;
            } else {
                $recPayOther += $rpAmt;
            }
        }

        // Penerimaan Operasional Lainnya dari buku kas manual
        $extraOpInQuery = CashTransaction::where('activity_type', 'OPERATING')
            ->where('type', 'IN')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $extraOpInQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $applyPmFilter($extraOpInQuery, 'payment_method');
        $extraOpIn = (float)$extraOpInQuery->sum('amount');
        $totalOperatingInflows = $totalDirectSalesReceipts + $receivableCashIn + $extraOpIn;

        // B. Pengeluaran Kas untuk Belanja Persediaan Bahan Baku (Cash Paid for Inventory Purchases)
        $movQuery = StockMovement::with(['ingredient' => function($q) {
            $q->select(['id', 'name', 'unit_pakai', 'konversi', 'harga']);
        }])
            ->where('type', 'PURCHASE')
            ->whereBetween('date', [$from, $to])
            ->select(['id', 'ingredient_id', 'outlet_id', 'type', 'date', 'unit_price', 'total_price', 'qty', 'payment_type']);
        if ($outletId) {
            $movQuery->where('outlet_id', $outletId);
        }
        if ($isPmFiltered) {
            if ($pmFilter === 'CASH' || $pmFilter === 'TUNAI') {
                $movQuery->where(fn($q) => $q->whereIn('payment_type', ['CASH', 'TUNAI'])->orWhereNull('payment_type'));
            } elseif ($pmFilter === 'TRANSFER') {
                $movQuery->where('payment_type', 'like', '%TRANSFER%');
            } elseif ($pmFilter === 'PETTY_CASH') {
                $movQuery->where('payment_type', 'PETTY_CASH');
            } elseif ($pmFilter === 'QRIS') {
                $movQuery->where('payment_type', 'like', '%QRIS%');
            } else {
                $movQuery->whereRaw('0 = 1');
            }
        }
        $purchaseMovements = $movQuery->get();

        $stockPurchasesCashTotal = 0.0;
        $unpaidCreditPurchasesTotal = 0.0;
        $purchaseItemsSummary = [];
        foreach ($purchaseMovements as $m) {
            $ing = $m->ingredient;
            $konversi = $ing ? max((float)$ing->konversi, 1) : 1;
            $unitCost = $m->unit_price > 0 ? (float)$m->unit_price : ($ing ? (float)$ing->harga / $konversi : 0);
            $totalCost = $m->total_price > 0 ? (float)$m->total_price : round((float)$m->qty * $unitCost, 2);

            $isCredit = (strtoupper($m->payment_type ?? '') === 'HUTANG');
            if ($isCredit) {
                $unpaidCreditPurchasesTotal += $totalCost;
            } else {
                $stockPurchasesCashTotal += $totalCost;
            }

            if ($ing) {
                $ingId = $ing->id;
                if (!isset($purchaseItemsSummary[$ingId])) {
                    $purchaseItemsSummary[$ingId] = [
                        'name'  => $ing->name,
                        'unit'  => $ing->unit_pakai,
                        'qty'   => 0,
                        'total' => 0,
                    ];
                }
                $purchaseItemsSummary[$ingId]['qty'] += (float)$m->qty;
                $purchaseItemsSummary[$ingId]['total'] += $totalCost;
            }
        }
        usort($purchaseItemsSummary, fn($a, $b) => $b['total'] <=> $a['total']);
        $topPurchases = array_slice($purchaseItemsSummary, 0, 5);

        // Kas keluar untuk pembayaran hutang supplier (Cicilan & Pelunasan Hutang + DP)
        $payablePaymentQuery = PayablePayment::whereBetween('payment_date', [$from, $to]);
        if ($outletId) {
            $payablePaymentQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $applyPmFilter($payablePaymentQuery, 'payment_method');
        $supplierDebtCashOut = (float)$payablePaymentQuery->sum('amount');

        // Tambahan pembelian bahan langsung dari buku kas jika ada
        $extraSuppQuery = CashTransaction::where('category', 'SUPPLIER_PURCHASE')
            ->where('type', 'OUT')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $extraSuppQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $applyPmFilter($extraSuppQuery, 'payment_method');
        $extraSupplierCash = (float)$extraSuppQuery->sum('amount');

        // Total arus kas keluar untuk persediaan: Belanja Tunai + Pembayaran Hutang Supplier + Kas Keluar Supplier Langsung
        $totalInventoryCashOut = $stockPurchasesCashTotal + $supplierDebtCashOut + $extraSupplierCash;

        // C. Pengeluaran Kas untuk Beban Operasional Toko (Cash Paid for OPEX)
        $opexQuery = OperatingExpense::whereBetween('date', [$from, $to]);
        if ($outletId) {
            $opexQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $applyPmFilter($opexQuery, 'payment_method');
        $opexRecords = $opexQuery->get();
        $totalOpexCashOut = (float)$opexRecords->sum('amount');

        // Tambahan biaya operasional lain dari buku kas
        $extraOpExQuery = CashTransaction::where('category', 'OTHER_EXPENSE')
            ->where('type', 'OUT')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $extraOpExQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $applyPmFilter($extraOpExQuery, 'payment_method');
        $extraOpExCash = (float)$extraOpExQuery->sum('amount');
        $totalOperatingExpensesCashOut = $totalOpexCashOut + $extraOpExCash;

        $totalOperatingOutflows = $totalInventoryCashOut + $totalOperatingExpensesCashOut;
        $netOperatingCashFlow = round($totalOperatingInflows - $totalOperatingOutflows, 2);

        // =========================================================================
        // 2. ARUS KAS DARI AKTIVITAS INVESTASI / CAPEX (INVESTING CASH FLOW)
        // =========================================================================
        $invQuery = CashTransaction::where('activity_type', 'INVESTING')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $invQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $applyPmFilter($invQuery, 'payment_method');
        $investingTransactions = $invQuery->get();

        $investingInflows = (float)$investingTransactions->where('type', 'IN')->sum('amount');
        $investingOutflows = (float)$investingTransactions->where('type', 'OUT')->sum('amount');
        $netInvestingCashFlow = round($investingInflows - $investingOutflows, 2);

        // Rincian CapEx per kategori
        $capexBreakdown = [];
        $investingCategories = ['EQUIPMENT', 'RENOVATION', 'FURNITURE', 'TECH_POS', 'ASSET_SALE'];
        $catMeta = CashTransaction::categories();
        foreach ($investingCategories as $cKey) {
            $items = $investingTransactions->where('category', $cKey);
            $cSum = (float)$items->sum('amount');
            if ($cSum > 0) {
                $capexBreakdown[] = [
                    'category' => $cKey,
                    'label'    => $catMeta[$cKey]['label'] ?? $cKey,
                    'type'     => $catMeta[$cKey]['default_type'] ?? 'OUT',
                    'amount'   => $cSum,
                    'count'    => $items->count(),
                ];
            }
        }

        // =========================================================================
        // 3. ARUS KAS DARI AKTIVITAS PENDANAAN (FINANCING CASH FLOW / FCF)
        // =========================================================================
        $finQuery = CashTransaction::where('activity_type', 'FINANCING')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $finQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $applyPmFilter($finQuery, 'payment_method');
        $financingTransactions = $finQuery->get();

        $financingInflows = (float)$financingTransactions->where('type', 'IN')->sum('amount');
        $financingOutflows = (float)$financingTransactions->where('type', 'OUT')->sum('amount');
        $netFinancingCashFlow = round($financingInflows - $financingOutflows, 2);

        // Rincian Pendanaan per kategori
        $financingBreakdown = [];
        $financingCategories = ['CAPITAL_INJECTION', 'OWNER_WITHDRAWAL', 'LOAN_RECEIPT', 'LOAN_REPAYMENT'];
        foreach ($financingCategories as $fKey) {
            $fItems = $financingTransactions->where('category', $fKey);
            $fSum = (float)$fItems->sum('amount');
            if ($fSum > 0) {
                $financingBreakdown[] = [
                    'category' => $fKey,
                    'label'    => $catMeta[$fKey]['label'] ?? $fKey,
                    'type'     => $catMeta[$fKey]['default_type'] ?? 'IN',
                    'amount'   => $fSum,
                    'count'    => $fItems->count(),
                ];
            }
        }

        // =========================================================================
        // 4. TOTAL PERUBAHAN KAS BERSIH (NET CHANGE IN CASH)
        // =========================================================================
        $netCashFlow = round($netOperatingCashFlow + $netInvestingCashFlow + $netFinancingCashFlow, 2);

        // =========================================================================
        // 5. JEMBATAN REKONSILIASI: LABA AKRUAL (P&L) VS ARUS KAS NYATA
        // =========================================================================
        $reportCtrl = new ReportController();
        $plRequest = new Request([
            'from'      => $from,
            'to'        => $to,
            'outlet_id' => $outletId,
        ]);
        if ($user) {
            $plRequest->setUserResolver(fn() => $user);
        }
        $plResponse = $reportCtrl->profitAndLoss($plRequest);
        $plData = json_decode($plResponse->getContent(), true);

        $accrualNetProfit = (float)($plData['bottom_line']['net_profit'] ?? 0);
        $cogsTheoretical = (float)($plData['cogs']['total_cogs'] ?? 0);
        $wasteLossNonCash = (float)($plData['waste']['total_waste_loss'] ?? 0);

        // Selisih antara Belanja Stok Riil vs HPP Teoretis (Working Capital Inventory Build-up)
        // Jika Belanja Stok > HPP Resep -> Kas tertahan di chiller/gudang!
        $inventoryCapitalChange = round($totalInventoryCashOut - $cogsTheoretical, 2);

        $reconciliationSteps = [
            [
                'step'        => 'accrual_profit',
                'title'       => 'Laba Bersih Usaha (P&L Akrual)',
                'description' => 'Keuntungan di atas kertas operasional toko berdasarkan transaksi penjualan',
                'amount'      => $accrualNetProfit,
                'effect'      => 'START',
            ],
            [
                'step'        => 'cogs_addback',
                'title'       => '(+) Penyesuaian HPP Resep Non-Kas',
                'description' => 'Bahan baku yang dimasak adalah beban di P&L, namun uang kas tidak keluar saat makanan dimasak',
                'amount'      => $cogsTheoretical,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'actual_purchases',
                'title'       => '(-) Uang Kas Dibelanjakan untuk Stok Bahan Baku Riil',
                'description' => 'Seluruh uang kas keluar untuk beli stok bahan mentah (termasuk yang masih menumpuk di chiller/gudang)',
                'amount'      => -$totalInventoryCashOut,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'waste_addback',
                'title'       => '(+) Penyesuaian Kerugian Waste Non-Kas',
                'description' => 'Bahan basi/rusak mengurangi laba di P&L, tetapi tidak ada uang kas keluar saat bahan dibuang',
                'amount'      => $wasteLossNonCash,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'receivable_unpaid_deduct',
                'title'       => '(-) Penjualan Kasbon Baru Belum Diterima Kasnya',
                'description' => 'Omzet kasbon diakui di laba P&L, namun uang kasnya belum masuk ke kasir/bank',
                'amount'      => -$newKasbonTotal,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'receivable_payment_add',
                'title'       => '(+) Penerimaan Kas dari Pembayaran Kasbon Pelanggan',
                'description' => 'Uang kas riil yang masuk dari pelunasan atau cicilan kasbon oleh customer',
                'amount'      => $receivableCashIn,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'capex_deduct',
                'title'       => '(-) Belanja Modal / Aset Resto (CapEx)',
                'description' => 'Pengeluaran kas untuk beli kulkas, chiller, renovasi toko, dan mesin baru',
                'amount'      => -$investingOutflows,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'asset_sale_add',
                'title'       => '(+) Penerimaan Penjualan Aset Bekas',
                'description' => 'Kas masuk dari penjualan peralatan atau aset bekas',
                'amount'      => $investingInflows,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'owner_withdrawal_deduct',
                'title'       => '(-) Prive / Penarikan Dana Tunai oleh Owner',
                'description' => 'Pengambilan kas pribadi atau dividen berjalan oleh pemilik resto',
                'amount'      => -$financingOutflows,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'capital_injection_add',
                'title'       => '(+) Setoran Modal Tambahan / Pinjaman',
                'description' => 'Injeksi dana segar dari owner, partner bisnis, atau pinjaman modal',
                'amount'      => $financingInflows,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'net_cash_result',
                'title'       => '(=) Perubahan Bersih Kas Riil (Net Cash Flow)',
                'description' => 'Kelebihan / kekurangan kas fisik riil yang benar-benar bertambah/berkurang di rekening & kasir',
                'amount'      => $netCashFlow,
                'effect'      => 'TOTAL',
            ],
        ];

        $selectedShifts = $isShiftFiltered 
            ? $availableShifts->whereIn('id', $shiftFilterIds)->values()
            : $availableShifts->values();
        $selectedShiftsInitialCashTotal = (float)$selectedShifts->sum('initial_cash');
        $closedShifts = $selectedShifts->where('status', 'CLOSED');
        $selectedShiftsClosingCashTotal = (float)$closedShifts->sum('closing_cash');
        $selectedShiftsSystemCashTotal = (float)$closedShifts->sum('system_cash');
        $selectedShiftsCashDifferenceTotal = (float)$closedShifts->sum('cash_difference');
        $selectedShiftsInterShiftDiffTotal = (float)$selectedShifts->sum('inter_shift_diff');
        $closedShiftsCount = $closedShifts->count();

        return response()->json([
            'period' => [
                'from'                          => $from,
                'to'                            => $to,
                'outlet_id'                     => $outletId,
                'selected_payment_method'       => $pmFilter,
                'selected_shift_ids'            => $shiftFilterIds,
                'is_shift_filtered'             => $isShiftFiltered,
                'is_pm_filtered'                => $isPmFiltered,
                'initial_cash_total'            => $selectedShiftsInitialCashTotal,
                'closing_cash_total'            => $selectedShiftsClosingCashTotal,
                'system_cash_total'             => $selectedShiftsSystemCashTotal,
                'cash_difference_total'         => $selectedShiftsCashDifferenceTotal,
                'inter_shift_difference_total'  => $selectedShiftsInterShiftDiffTotal,
                'closed_shifts_count'           => $closedShiftsCount,
                'selected_shifts_count'         => $selectedShifts->count(),
            ],
            'available_shifts' => $availableShifts,
            'summary' => [
                'initial_cash_total'             => $selectedShiftsInitialCashTotal,
                'closing_cash_total'             => $selectedShiftsClosingCashTotal,
                'system_cash_total'              => $selectedShiftsSystemCashTotal,
                'cash_difference_total'          => $selectedShiftsCashDifferenceTotal,
                'inter_shift_difference_total'   => $selectedShiftsInterShiftDiffTotal,
                'closed_shifts_count'            => $closedShiftsCount,
                'selected_shifts_count'          => $selectedShifts->count(),
                'net_operating_cash_flow'        => $netOperatingCashFlow,
                'net_investing_cash_flow'        => $netInvestingCashFlow,
                'net_financing_cash_flow'        => $netFinancingCashFlow,
                'net_cash_flow'                  => $netCashFlow,
                'accrual_net_profit'             => $accrualNetProfit,
                'inventory_cash_trapped'         => $inventoryCapitalChange,
                'liquidity_status'               => $netCashFlow >= 0 ? 'SURPLUS' : 'DEFICIT',
                'liquidity_label'                => $netCashFlow >= 0 ? 'Kas Surplus (Likuid Prima)' : 'Kas Defisit (Ketat / Waspada)',
                'liquidity_color'                => $netCashFlow >= 0 ? '#10B981' : '#EF4444',
            ],
            'operating' => [
                'inflows' => [
                    'cash_sales'             => round($cashSales, 2),
                    'qris_sales'             => round($qrisSales, 2),
                    'grab_sales'             => round($grabSales, 2),
                    'transfer_sales'         => round($transferSales, 2),
                    'debit_sales'            => round($debitSales, 2),
                    'other_sales'            => round($otherSales, 2),
                    'direct_sales_total'     => round($totalDirectSalesReceipts, 2),
                    'receivable_collections' => round($receivableCashIn, 2),
                    'receivable_breakdown'   => [
                        'cash'     => round($recPayCash, 2),
                        'transfer' => round($recPayTransfer, 2),
                        'qris'     => round($recPayQris, 2),
                        'debit'    => round($recPayDebit, 2),
                        'other'    => round($recPayOther, 2),
                        'count'    => $receivablePayments->count(),
                    ],
                    'combined_breakdown'     => [
                        'cash'     => round($cashSales + $recPayCash, 2),
                        'qris'     => round($qrisSales + $recPayQris, 2),
                        'grab'     => round($grabSales, 2),
                        'transfer' => round($transferSales + $recPayTransfer, 2),
                        'debit'    => round($debitSales + $recPayDebit, 2),
                        'other'    => round($otherSales + $recPayOther, 2),
                        'total'    => round($totalOperatingInflows, 2),
                    ],
                    'receivable_payments'    => $receivablePayments->map(fn($rp) => [
                        'id'             => $rp->id,
                        'payment_no'     => $rp->payment_no,
                        'receivable_no'  => $rp->receivable?->receivable_no,
                        'customer_name'  => $rp->receivable?->customer_name ?? 'Pelanggan Kasbon',
                        'payment_date'   => $rp->payment_date ? (is_string($rp->payment_date) ? substr($rp->payment_date, 0, 10) : $rp->payment_date->format('Y-m-d')) : null,
                        'amount'         => (float)$rp->amount,
                        'payment_method' => strtoupper($rp->payment_method ?: 'CASH'),
                        'notes'          => $rp->notes,
                        'outlet_name'    => $rp->outlet?->name ?? 'Cabang',
                        'receiver_name'  => $rp->receiver?->name ?? 'Kasir',
                    ])->values(),
                    'unpaid_kasbon_omzet'    => round($newKasbonTotal, 2),
                    'extra_income'           => round($extraOpIn, 2),
                    'total_inflows'          => round($totalOperatingInflows, 2),
                ],
                'outflows' => [
                    'stock_purchases'        => round($totalInventoryCashOut, 2),
                    'stock_purchases_direct' => round($stockPurchasesCashTotal, 2),
                    'supplier_debt_payments' => round($supplierDebtCashOut, 2),
                    'opex_expenses'          => round($totalOperatingExpensesCashOut, 2),
                    'total_outflows'         => round($totalOperatingOutflows, 2),
                ],
                'top_purchases' => $topPurchases,
                'net'           => $netOperatingCashFlow,
            ],
            'investing' => [
                'inflows'   => round($investingInflows, 2),
                'outflows'  => round($investingOutflows, 2),
                'breakdown' => $capexBreakdown,
                'net'       => $netInvestingCashFlow,
            ],
            'financing' => [
                'inflows'   => round($financingInflows, 2),
                'outflows'  => round($financingOutflows, 2),
                'breakdown' => $financingBreakdown,
                'net'       => $netFinancingCashFlow,
            ],
            'reconciliation' => [
                'steps'                    => $reconciliationSteps,
                'inventory_capital_change' => $inventoryCapitalChange,
                'accrual_profit'           => $accrualNetProfit,
                'cash_profit'              => $netCashFlow,
                'discrepancy_explanation'  => $inventoryCapitalChange > 0 
                    ? "Terdapat uang kas sebesar Rp " . number_format($inventoryCapitalChange, 0, ',', '.') . " yang terkunci dalam bentuk stok bahan baku di gudang/chiller (pembelian stok lebih besar dari bahan terpakai)."
                    : "Pembelian stok bahan baku lebih hemat dibandingkan pemakaian menu terjual.",
            ],
        ]);
    }

    /**
     * List manual cash transactions (CapEx, Financing, Extra Cash Entries)
     */
    public function index(Request $request)
    {
        $query = CashTransaction::with(['outlet', 'user'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        if ($isOutletBounded) {
            $query->where('outlet_id', (int)$user->outlet_id);
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }

        if ($request->filled('activity_type') && $request->activity_type !== 'ALL') {
            $query->where('activity_type', $request->activity_type);
        }

        if ($request->filled('category') && $request->category !== 'ALL') {
            $query->where('category', $request->category);
        }

        if ($request->filled('payment_method') && strtoupper($request->payment_method) !== 'ALL') {
            $rawPm = $request->payment_method;
            $pmList = is_array($rawPm) ? $rawPm : explode(',', $rawPm);
            $pmList = array_map(fn($v) => strtoupper(trim($v)), array_filter($pmList));

            if (count($pmList) > 0 && !in_array('ALL', $pmList)) {
                $query->where(function($q) use ($pmList) {
                    foreach ($pmList as $idx => $pm) {
                        $clause = function($subQ) use ($pm) {
                            if ($pm === 'NON_CASH' || $pm === 'ALL_NON_CASH') {
                                $subQ->where(function($inner) {
                                    $inner->where('payment_method', 'like', '%QRIS%')
                                          ->orWhere('payment_method', 'like', '%GRAB%')
                                          ->orWhere('payment_method', 'like', '%GOFOOD%')
                                          ->orWhere('payment_method', 'like', '%SHOPEE%')
                                          ->orWhere('payment_method', 'like', '%TIKTOK%')
                                          ->orWhere('payment_method', 'like', '%TRANSFER%')
                                          ->orWhere('payment_method', 'like', '%DEBIT%')
                                          ->orWhere('payment_method', 'like', '%EDC%')
                                          ->orWhere('payment_method', 'like', '%CREDIT%');
                                });
                            } elseif ($pm === 'CASH_ALL' || $pm === 'CASH_AND_PETTY' || $pm === 'CASH' || $pm === 'TUNAI' || $pm === 'PETTY_CASH') {
                                $subQ->where(function($inner) {
                                    $inner->whereIn('payment_method', ['CASH', 'TUNAI', 'PETTY_CASH'])
                                          ->orWhere('payment_method', 'like', '%TUNAI%')
                                          ->orWhere('payment_method', 'like', '%CASH%')
                                          ->orWhere('payment_method', 'like', '%PETTY%');
                                });
                            } elseif ($pm === 'QRIS') {
                                $subQ->where('payment_method', 'like', '%QRIS%');
                            } elseif ($pm === 'GRAB' || $pm === 'ECOMMERCE' || $pm === 'ECOMMERCE_ALL') {
                                $subQ->where(function($inner) {
                                    $inner->where('payment_method', 'like', '%GRAB%')
                                          ->orWhere('payment_method', 'like', '%GOFOOD%')
                                          ->orWhere('payment_method', 'like', '%SHOPEE%')
                                          ->orWhere('payment_method', 'like', '%TIKTOK%');
                                });
                            } elseif ($pm === 'TRANSFER') {
                                $subQ->where('payment_method', 'like', '%TRANSFER%');
                            } elseif ($pm === 'DEBIT' || $pm === 'EDC') {
                                $subQ->where(function($inner) {
                                    $inner->where('payment_method', 'like', '%DEBIT%')
                                          ->orWhere('payment_method', 'like', '%EDC%')
                                          ->orWhere('payment_method', 'like', '%CREDIT%');
                                });
                            } elseif ($pm === 'PETTY_CASH') {
                                $subQ->where('payment_method', 'PETTY_CASH');
                            } else {
                                $subQ->where('payment_method', $pm);
                            }
                        };

                        if ($idx === 0) {
                            $q->where($clause);
                        } else {
                            $q->orWhere($clause);
                        }
                    }
                });
            }
        }

        if ($request->filled('search')) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('transaction_no', 'like', $search)
                  ->orWhere('notes', 'like', $search);
            });
        }

        return response()->json($query->limit(300)->get());
    }

    /**
     * Store a new manual cash transaction
     */
    public function store(Request $request)
    {
        $validCategories = array_keys(CashTransaction::categories());
        $validActivities = array_keys(CashTransaction::activityTypes());
        $validAccounts = array_keys(CashTransaction::accounts());

        $data = $request->validate([
            'date'           => 'required|date',
            'activity_type'  => 'required|string|in:' . implode(',', $validActivities),
            'category'       => 'required|string|in:' . implode(',', $validCategories),
            'type'           => 'required|string|in:IN,OUT',
            'name'           => 'required|string|max:255',
            'amount'         => 'required|numeric|min:0.01',
            'account'        => 'nullable|string|in:' . implode(',', $validAccounts),
            'payment_method' => 'nullable|string|max:30',
            'outlet_id'      => 'nullable|exists:outlets,id',
            'notes'          => 'nullable|string|max:1000',
            'receipt_img'    => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $businessId = $user->business_id ?? null;
        $transactionNo = CashTransaction::generateTransactionNo($businessId, $data['date']);
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($data['outlet_id'] ?? null);

        $trx = CashTransaction::create([
            'business_id'    => $businessId,
            'outlet_id'      => $outletId,
            'transaction_no' => $transactionNo,
            'date'           => $data['date'],
            'type'           => $data['type'],
            'activity_type'  => $data['activity_type'],
            'category'       => $data['category'],
            'name'           => $data['name'],
            'amount'         => (float)$data['amount'],
            'account'        => $data['account'] ?? 'BANK_MAIN',
            'payment_method' => $data['payment_method'] ?? 'TRANSFER',
            'notes'          => $data['notes'] ?? null,
            'receipt_img'    => $data['receipt_img'] ?? null,
            'user_id'        => $user->id,
            'created_by'     => $user->id,
        ]);

        return response()->json([
            'message' => 'Transaksi kas berhasil dicatat',
            'data'    => $trx->fresh(['outlet', 'user']),
        ], 201);
    }

    /**
     * Show a single cash transaction
     */
    public function show($id)
    {
        $trx = CashTransaction::with(['outlet', 'user'])->findOrFail($id);
        return response()->json($trx);
    }

    /**
     * Update an existing cash transaction
     */
    public function update(Request $request, $id)
    {
        $trx = CashTransaction::findOrFail($id);

        $validCategories = array_keys(CashTransaction::categories());
        $validActivities = array_keys(CashTransaction::activityTypes());
        $validAccounts = array_keys(CashTransaction::accounts());

        $data = $request->validate([
            'date'           => 'sometimes|required|date',
            'activity_type'  => 'sometimes|required|string|in:' . implode(',', $validActivities),
            'category'       => 'sometimes|required|string|in:' . implode(',', $validCategories),
            'type'           => 'sometimes|required|string|in:IN,OUT',
            'name'           => 'sometimes|required|string|max:255',
            'amount'         => 'sometimes|required|numeric|min:0.01',
            'account'        => 'nullable|string|in:' . implode(',', $validAccounts),
            'payment_method' => 'nullable|string|max:30',
            'outlet_id'      => 'nullable|exists:outlets,id',
            'notes'          => 'nullable|string|max:1000',
            'receipt_img'    => 'nullable|string|max:255',
        ]);

        $trx->update([
            'date'           => $data['date'] ?? $trx->date,
            'activity_type'  => $data['activity_type'] ?? $trx->activity_type,
            'category'       => $data['category'] ?? $trx->category,
            'type'           => $data['type'] ?? $trx->type,
            'name'           => $data['name'] ?? $trx->name,
            'amount'         => isset($data['amount']) ? (float)$data['amount'] : $trx->amount,
            'account'        => $data['account'] ?? $trx->account,
            'payment_method' => $data['payment_method'] ?? $trx->payment_method,
            'outlet_id'      => array_key_exists('outlet_id', $data) ? $data['outlet_id'] : $trx->outlet_id,
            'notes'          => array_key_exists('notes', $data) ? $data['notes'] : $trx->notes,
            'receipt_img'    => array_key_exists('receipt_img', $data) ? $data['receipt_img'] : $trx->receipt_img,
            'updated_by'     => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Transaksi kas berhasil diperbarui',
            'data'    => $trx->fresh(['outlet', 'user']),
        ]);
    }

    /**
     * Delete a cash transaction
     */
    public function destroy($id)
    {
        $trx = CashTransaction::findOrFail($id);
        $trx->delete();

        return response()->json([
            'message' => 'Catatan transaksi kas berhasil dihapus',
        ]);
    }

    /**
     * Categories and activity types dictionary for frontend dropdowns
     */
    public function categories()
    {
        return response()->json([
            'categories'     => CashTransaction::categories(),
            'activity_types' => CashTransaction::activityTypes(),
            'accounts'       => CashTransaction::accounts(),
        ]);
    }
}
