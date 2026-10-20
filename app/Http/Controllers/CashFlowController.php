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
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Account;
use App\Models\Shift;
use App\Services\AccountingService;
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
                $prevDeposit = (float)($prevClosed->deposit_amount ?? 0);
                $expectedHandover = max(0, $prevClosing - $prevDeposit);
                $diffAntar = round((float)$cs->initial_cash - $expectedHandover, 2);
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

        $rawAvailableShifts = $availableShiftsQuery->get();
        $shiftIds = $rawAvailableShifts->pluck('id')->filter()->all();

        $depositTransactions = \App\Models\CashTransaction::where(function ($q) use ($shiftIds) {
            $q->whereIn('shift_id', $shiftIds)
              ->orWhere(function ($sub) use ($shiftIds) {
                  $sub->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                      ->where(function ($inner) use ($shiftIds) {
                          foreach ($shiftIds as $sId) {
                              $inner->orWhere('name', 'like', "%Shift #{$sId}%")
                                    ->orWhere('notes', 'like', "%Shift #{$sId}%");
                          }
                      });
              });
        })->get();

        $depositsByShift = [];
        foreach ($depositTransactions as $dtx) {
            $sId = $dtx->shift_id;
            if (!$sId) {
                if (preg_match('/Shift #(\d+)/i', ($dtx->name ?? '') . ' ' . ($dtx->notes ?? ''), $m)) {
                    $sId = (int)$m[1];
                }
            }
            if ($sId) {
                if (!isset($depositsByShift[$sId])) {
                    $depositsByShift[$sId] = collect();
                }
                $depositsByShift[$sId]->push($dtx);
            }
        }

        $availableShifts = $rawAvailableShifts->map(function($s) use ($interShiftDiffMap, $depositsByShift) {
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

            // Deposit data
            $sDepositTxs = $depositsByShift[$s->id] ?? collect();
            $lastDepositTx = $sDepositTxs->last();
            $depositStatus = $s->deposit_status ?: ($lastDepositTx ? ($lastDepositTx->status ?: 'APPROVED') : 'NONE');
            $depositAmount = $s->deposit_amount !== null ? (float)$s->deposit_amount : (float)$sDepositTxs->sum('amount');
            $depositDiff = ($s->status === 'CLOSED' && $depositAmount > 0 && $s->closing_cash !== null)
                ? round($depositAmount - (float)$s->closing_cash, 2)
                : 0.0;

            return [
                'id'                     => $s->id,
                'shift_name'             => $s->shift_name,
                'opened_at'              => $s->opened_at,
                'closed_at'              => $s->closed_at,
                'status'                 => $s->status,
                'cashier_name'           => $s->user?->name ?: 'Kasir',
                'initial_cash'           => (float)$s->initial_cash,
                'closing_cash'           => (float)$s->closing_cash,
                'system_cash'            => (float)$expectedCash,
                'system_sales'           => $sysSalesRaw,
                'cash_difference'        => $discrepancy,
                'inter_shift_diff'       => $interData['inter_shift_diff'],
                'prev_shift_name'        => $interData['prev_shift_name'],
                'prev_closing_cash'      => $interData['prev_closing_cash'],
                'deposit_status'         => $depositStatus,
                'deposit_amount'         => $depositAmount,
                'is_deposited'           => $depositStatus === 'APPROVED' && $depositAmount > 0,
                'is_deposit_pending'     => $depositStatus === 'PENDING',
                'is_deposit_rejected'    => $depositStatus === 'REJECTED',
                'deposit_account'        => $s->deposit_account ?: ($lastDepositTx?->account ?? ($depositAmount > 0 ? 'KAS_BESAR' : null)),
                'deposit_account_label'  => $s->deposit_account_label ?: ($lastDepositTx?->account === 'BANK_MAIN' ? 'Rekening Bank Utama' : ($depositAmount > 0 ? 'Kas Besar / Brankas' : null)),
                'deposit_diff'           => $depositDiff,
                'deposit_notes'          => $s->deposit_notes ?: $lastDepositTx?->notes,
                'deposit_transaction_no' => $lastDepositTx?->transaction_no,
                'notes'                  => $s->notes,
                'date'                   => $s->opened_at ? substr($s->opened_at, 0, 10) : null,
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
        // Auto-seed COA & auto-sync if journal lines/entries are empty
        $businessId = $user ? $user->business_id : null;
        if ($businessId) {
            Account::seedForBusiness($businessId);
            $hasJournalLines = JournalLine::whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId))->exists();
            if (!$hasJournalLines) {
                AccountingService::syncAllHistoricalData($businessId);
            }
        }

        // =========================================================================
        // 1. QUERY CASH & BANK ACCOUNTS (Akun Kas & Setara Kas: 1-11xxx / CASH & BANK)
        // =========================================================================
        $selectedAccountCode = $request->input('account_code', 'ALL');

        $allCashAccounts = Account::when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where(function($q) {
                $q->whereIn('category', ['CASH', 'BANK'])
                  ->orWhere('code', 'like', '1-11%');
            })
            ->where('is_active', true)
            ->orderBy('code', 'asc')
            ->get();

        if ($allCashAccounts->isEmpty()) {
            $defaultAccs = Account::defaultAccounts();
            foreach ($defaultAccs as $da) {
                if (str_starts_with($da['code'], '1-11')) {
                    $allCashAccounts->push((object)$da);
                }
            }
        }

        $cashAccountCodes = $allCashAccounts->pluck('code')->all();
        if (empty($cashAccountCodes)) {
            $cashAccountCodes = ['1-11001', '1-11002', '1-11003'];
        }

        // Hitung Saldo Awal, Debit Masuk, Kredit Keluar, & Saldo Akhir untuk SETIAP akun kas (Sesuai Neraca & rollup akun induk)
        $cashAccountsOverview = [];
        $totalInitialAll = 0.0;
        $totalDebitAll = 0.0;
        $totalCreditAll = 0.0;
        $totalEndingAll = 0.0;

        foreach ($allCashAccounts as $cAcc) {
            $cCode = $cAcc->code;
            $cName = $cAcc->name;
            $cCat  = $cAcc->category ?? 'CASH';

            // Check if this is a parent account (has children or no dot in code and has parent_code null)
            $isParent = empty($cAcc->parent_code) && !str_contains($cCode, '.');
            $childAccountCodes = $allCashAccounts->filter(function($a) use ($cCode) {
                return ($a->parent_code ?? null) === $cCode 
                    || (str_starts_with($a->code, "{$cCode}.") && $a->code !== $cCode);
            })->pluck('code')->all();

            $hasChildren = !empty($childAccountCodes);

            // Determine matching journal codes for this account
            if ($hasChildren) {
                $matchingCodes = array_values(array_unique(array_merge([$cCode], $childAccountCodes)));
            } else {
                $matchingCodes = [$cCode];
            }

            // Saldo Awal (< $from)
            $pQ = JournalLine::where('entry_date', '<', $from)->whereIn('account_code', $matchingCodes);
            if ($outletId) $pQ->where('outlet_id', $outletId);
            if ($businessId) $pQ->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
            $pSums = $pQ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')->first();
            $initBal = round((float)($pSums?->total_debit ?? 0) - (float)($pSums?->total_credit ?? 0), 2);

            // Mutasi Periode Ini ($from s/d $to)
            $curQ = JournalLine::whereBetween('entry_date', [$from, $to])->whereIn('account_code', $matchingCodes);
            if ($outletId) $curQ->where('outlet_id', $outletId);
            if ($businessId) $curQ->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
            $curSums = $curQ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')->first();
            $totDeb = round((float)($curSums?->total_debit ?? 0), 2);
            $totCrd = round((float)($curSums?->total_credit ?? 0), 2);
            $netChg = round($totDeb - $totCrd, 2);
            $endBal = round($initBal + $netChg, 2);

            $cashAccountsOverview[] = [
                'code'            => $cCode,
                'name'            => $cName,
                'category'        => $cCat,
                'is_parent'       => $isParent || $hasChildren,
                'has_children'    => $hasChildren,
                'initial_balance' => $initBal,
                'total_debit'     => $totDeb,
                'total_credit'    => $totCrd,
                'net_change'      => $netChg,
                'ending_balance'  => $endBal,
            ];

            // Only sum leaf/child accounts to total_accounts_overview to prevent double counting
            if (!$hasChildren) {
                $totalInitialAll += $initBal;
                $totalDebitAll   += $totDeb;
                $totalCreditAll  += $totCrd;
                $totalEndingAll  += $endBal;
            }
        }

        // Tentukan akun kas aktif yang dihitung untuk Laporan Arus Kas
        if ($selectedAccountCode !== 'ALL') {
            $activeCashCodes = $allCashAccounts->filter(function($a) use ($selectedAccountCode) {
                return $a->code === $selectedAccountCode 
                    || str_starts_with($a->code, "{$selectedAccountCode}.") 
                    || ($a->parent_code ?? null) === $selectedAccountCode;
            })->pluck('code')->unique()->values()->all();

            if (empty($activeCashCodes)) {
                $activeCashCodes = [$selectedAccountCode];
            }
        } else {
            $activeCashCodes = $cashAccountCodes;
        }

        $targetAccountName = 'SEMUA KAS & BANK';
        if ($selectedAccountCode !== 'ALL') {
            $matchedAcc = $allCashAccounts->firstWhere('code', $selectedAccountCode);
            if ($matchedAcc) {
                $targetAccountName = "{$matchedAcc->name} ({$selectedAccountCode})";
            }
        }

        // A. Saldo Awal Kas Akun Terpilih (Prior to $from)
        $priorCashQuery = JournalLine::where('entry_date', '<', $from)->whereIn('account_code', $activeCashCodes);
        if ($outletId) {
            $priorCashQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $priorCashQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }
        $priorCashSums = $priorCashQuery->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')->first();
        $initialCashBalance = round((float)($priorCashSums?->total_debit ?? 0) - (float)($priorCashSums?->total_credit ?? 0), 2);

        // B. In-Period Journal Lines for Selected Cash Account(s) ($from to $to)
        $periodCashLinesQuery = JournalLine::with(['journalEntry.lines', 'outlet'])
            ->whereBetween('entry_date', [$from, $to])
            ->whereIn('account_code', $activeCashCodes);
        if ($outletId) {
            $periodCashLinesQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $periodCashLinesQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }
        $periodCashLines = $periodCashLinesQuery->orderBy('entry_date', 'asc')->orderBy('id', 'asc')->get();

        // =========================================================================
        // 2. CLASSIFY CASH FLOWS BASED ON GENERAL LEDGER JOURNAL ENTRIES
        // =========================================================================
        $cashSales = 0.0;
        $qrisSales = 0.0;
        $grabSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $otherSales = 0.0;
        $receivableCashIn = 0.0;
        $extraOpIn = 0.0;
        $shiftCashOver = 0.0;
        $internalTransferIn = 0.0;

        $stockPurchasesCashTotal = 0.0;
        $supplierDebtCashOut = 0.0;
        $totalOpexCashOut = 0.0;
        $shiftCashShort = 0.0;
        $internalTransferOut = 0.0;

        $investingInflows = 0.0;
        $investingOutflows = 0.0;
        $capexBreakdown = [];

        $financingInflows = 0.0;
        $financingOutflows = 0.0;
        $financingBreakdown = [];

        $recPayCash = 0.0; $recPayTransfer = 0.0; $recPayQris = 0.0; $recPayDebit = 0.0; $recPayOther = 0.0;

        $runningMutationBalance = $initialCashBalance;
        $journalLinesAuditTrail = [];

        $detailedInflows = [];
        $detailedOutflows = [];

        foreach ($periodCashLines as $line) {
            $entry = $line->journalEntry;
            if (!$entry) continue;

            $entryType = strtoupper((string)($entry->entry_type ?: $entry->reference_type));
            $counterLines = $entry->lines ? $entry->lines->where('id', '!=', $line->id) : collect();

            $deb = (float)$line->debit;
            $crd = (float)$line->credit;
            $runningMutationBalance += ($deb - $crd);

            $counterAccountNames = $counterLines->map(fn($cl) => $cl->account_code)->implode(', ');
            $memo = $line->memo ?: ($entry->description ?: '-');
            $dateStr = is_string($line->entry_date) ? substr($line->entry_date, 0, 10) : ($line->entry_date ? $line->entry_date->format('Y-m-d') : '-');
            $refNo = $entry->entry_no ?: "JE-{$line->journal_entry_id}";

            $auditItem = [
                'id'               => $line->id,
                'journal_entry_id' => $line->journal_entry_id,
                'journal_id'       => $entry->id,
                'date'             => $dateStr,
                'ref_no'           => $refNo,
                'account_code'     => $line->account_code,
                'counter_accounts' => $counterAccountNames ?: '-',
                'category'         => $entryType ?: 'JURNAL',
                'description'      => $memo,
                'debit'            => $deb,
                'credit'           => $crd,
                'net_amount'       => $deb > 0 ? $deb : -$crd,
                'running_balance'  => round($runningMutationBalance, 2),
                'outlet_name'      => $line->outlet?->name ?: 'Pusat',
            ];
            $journalLinesAuditTrail[] = $auditItem;

            // Uang Kas Masuk (Debit to Cash Account)
            if ($deb > 0) {
                $amount = $deb;

                // Check if internal cash transfer between cash accounts
                $isInternalCashTransfer = $counterLines->contains(fn($cl) => in_array($cl->account_code, $cashAccountCodes) && (float)$cl->credit > 0);
                if ($isInternalCashTransfer) {
                    $internalTransferIn += $amount;
                    $transferTitle = 'Mutasi Masuk Antar Kas';
                    if (str_contains(strtoupper($memo), 'SETORAN') || str_contains($entryType, 'DEPOSIT')) {
                        $transferTitle = 'Penerimaan Setoran Kasir';
                    }
                    $detailedInflows[] = array_merge($auditItem, ['flow_type' => 'INTERNAL_TRANSFER', 'title' => $transferTitle]);
                    continue;
                }

                if ($entryType === 'SALES' || $counterLines->contains(fn($cl) => str_starts_with($cl->account_code, '4-41001') || in_array($cl->account_code, ['1-12003', '1-12004', '1-12005', '1-12006']))) {
                    if ($line->account_code === '1-11002') {
                        $cashSales += $amount;
                    } elseif ($line->account_code === '1-11003') {
                        $transferSales += $amount;
                    } elseif ($line->account_code === '1-11004' || str_contains($line->account_code, '11004') || str_contains($line->account_code, '12003')) {
                        $qrisSales += $amount;
                    } elseif (str_contains($line->account_code, '12004') || str_contains($line->account_code, '12005') || str_contains($line->account_code, '12006')) {
                        $grabSales += $amount;
                    } else {
                        $otherSales += $amount;
                    }
                    $detailedInflows[] = array_merge($auditItem, ['flow_type' => 'SALES', 'title' => 'Penjualan Kasir POS']);
                } elseif ($entryType === 'RECEIVABLE_PAYMENT' || $counterLines->contains(fn($cl) => $cl->account_code === '1-12001')) {
                    $receivableCashIn += $amount;
                    if ($line->account_code === '1-11002') $recPayCash += $amount;
                    elseif ($line->account_code === '1-11003') $recPayTransfer += $amount;
                    elseif (str_contains($line->account_code, '12003')) $recPayQris += $amount;
                    else $recPayOther += $amount;
                    $detailedInflows[] = array_merge($auditItem, ['flow_type' => 'RECEIVABLE', 'title' => 'Pelunasan Kasbon Pelanggan']);
                } elseif ($entryType === 'SHIFT_CLOSING' && $counterLines->contains(fn($cl) => $cl->account_code === '4-41002')) {
                    $shiftCashOver += $amount;
                    $detailedInflows[] = array_merge($auditItem, ['flow_type' => 'CASH_OVER', 'title' => 'Surplus / Lebih Kas Kasir']);
                } elseif ($counterLines->contains(fn($cl) => str_starts_with($cl->account_code, '1-2'))) {
                    $investingInflows += $amount;
                    $detailedInflows[] = array_merge($auditItem, ['flow_type' => 'INVESTING', 'title' => 'Penjualan Aset Tetap']);
                } elseif ($counterLines->contains(fn($cl) => $cl->account_code === '3-31001' || str_starts_with($cl->account_code, '2-'))) {
                    $financingInflows += $amount;
                    $financingBreakdown[] = [
                        'category' => 'CAPITAL_INJECTION',
                        'label'    => $memo ?: 'Setoran Modal / Injeksi Dana',
                        'type'     => 'IN',
                        'amount'   => $amount,
                    ];
                    $detailedInflows[] = array_merge($auditItem, ['flow_type' => 'CAPITAL_INJECTION', 'title' => 'Setoran Modal / Modal Awal Kasir']);
                } else {
                    $extraOpIn += $amount;
                    $detailedInflows[] = array_merge($auditItem, ['flow_type' => 'OTHER_INCOME', 'title' => 'Penerimaan Kas Operasi Lain']);
                }
            }

            // Uang Kas Keluar (Credit to Cash Account)
            if ($crd > 0) {
                $amount = $crd;

                // Check if internal cash transfer between cash accounts
                $isInternalCashTransfer = $counterLines->contains(fn($cl) => in_array($cl->account_code, $cashAccountCodes) && (float)$cl->debit > 0);
                if ($isInternalCashTransfer) {
                    $internalTransferOut += $amount;
                    $transferTitle = 'Mutasi Keluar Antar Kas';
                    if (str_contains(strtoupper($memo), 'SETORAN') || str_contains($entryType, 'DEPOSIT')) {
                        $transferTitle = 'Setoran Kasir ke Kas Besar';
                    }
                    $detailedOutflows[] = array_merge($auditItem, ['flow_type' => 'INTERNAL_TRANSFER', 'title' => $transferTitle]);
                    continue;
                }

                if ($entryType === 'PURCHASE' || $counterLines->contains(fn($cl) => str_starts_with($cl->account_code, '1-13'))) {
                    $stockPurchasesCashTotal += $amount;
                    $detailedOutflows[] = array_merge($auditItem, ['flow_type' => 'PURCHASE', 'title' => 'Pembelian Stok Bahan Baku']);
                } elseif ($entryType === 'PAYABLE_PAYMENT' || $counterLines->contains(fn($cl) => $cl->account_code === '2-21001')) {
                    $supplierDebtCashOut += $amount;
                    $detailedOutflows[] = array_merge($auditItem, ['flow_type' => 'PAYABLE', 'title' => 'Pelunasan Hutang Supplier']);
                } elseif ($entryType === 'SHIFT_CLOSING' || $counterLines->contains(fn($cl) => $cl->account_code === '6-61008')) {
                    $shiftCashShort += $amount;
                    $detailedOutflows[] = array_merge($auditItem, ['flow_type' => 'CASH_SHORT', 'title' => 'Selisih Kurang / Tekor Kasir']);
                } elseif ($entryType === 'OPEX' || $counterLines->contains(fn($cl) => str_starts_with($cl->account_code, '6-'))) {
                    $totalOpexCashOut += $amount;
                    $detailedOutflows[] = array_merge($auditItem, ['flow_type' => 'OPEX', 'title' => 'Beban Operasional Toko (OPEX)']);
                } elseif ($counterLines->contains(fn($cl) => str_starts_with($cl->account_code, '1-2'))) {
                    $investingOutflows += $amount;
                    $capexBreakdown[] = [
                        'category' => 'EQUIPMENT',
                        'label'    => $memo ?: 'Belanja Aset & Peralatan',
                        'type'     => 'OUT',
                        'amount'   => $amount,
                    ];
                    $detailedOutflows[] = array_merge($auditItem, ['flow_type' => 'CAPEX', 'title' => 'Belanja Modal & Peralatan (CapEx)']);
                } elseif ($counterLines->contains(fn($cl) => $cl->account_code === '3-31002')) {
                    $financingOutflows += $amount;
                    $financingBreakdown[] = [
                        'category' => 'OWNER_WITHDRAWAL',
                        'label'    => $memo ?: 'Prive Penarikan Owner',
                        'type'     => 'OUT',
                        'amount'   => $amount,
                    ];
                    $detailedOutflows[] = array_merge($auditItem, ['flow_type' => 'PRIVE', 'title' => 'Prive / Penarikan Owner']);
                } else {
                    $totalOpexCashOut += $amount;
                    $detailedOutflows[] = array_merge($auditItem, ['flow_type' => 'OTHER_EXPENSE', 'title' => 'Pengeluaran Kas Operasional Lain']);
                }
            }
        }

        $totalDirectSalesReceipts = round($cashSales + $qrisSales + $grabSales + $transferSales + $debitSales + $otherSales, 2);
        $totalOperatingInflows = round($totalDirectSalesReceipts + $receivableCashIn + $extraOpIn + $shiftCashOver + $internalTransferIn, 2);

        $totalInventoryCashOut = round($stockPurchasesCashTotal + $supplierDebtCashOut, 2);
        $totalOperatingExpensesCashOut = round($totalOpexCashOut + $shiftCashShort + $internalTransferOut, 2);
        $totalOperatingOutflows = round($totalInventoryCashOut + $totalOperatingExpensesCashOut, 2);

        $netOperatingCashFlow = round($totalOperatingInflows - $totalOperatingOutflows, 2);
        $netInvestingCashFlow = round($investingInflows - $investingOutflows, 2);
        $netFinancingCashFlow = round($financingInflows - $financingOutflows, 2);
        $netCashFlow = round($netOperatingCashFlow + $netInvestingCashFlow + $netFinancingCashFlow, 2);
        $closingCashTotal = round($initialCashBalance + $netCashFlow, 2);

        // Supporting Details & Reconciliation Bridge
        $recPayQuery = ReceivablePayment::with(['receivable', 'outlet', 'receiver'])
            ->whereBetween('payment_date', [$from, $to])
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId));
        $receivablePayments = $recPayQuery->get();

        $recListCash = 0.0; $recListQris = 0.0; $recListTransfer = 0.0; $recListDebit = 0.0; $recListOther = 0.0;
        foreach ($receivablePayments as $rp) {
            $amt = (float)$rp->amount;
            $pm = strtoupper(trim((string)$rp->payment_method));
            if ($pm === 'CASH' || $pm === 'TUNAI' || $pm === 'PETTY_CASH' || $pm === 'KAS') {
                $recListCash += $amt;
            } elseif (str_contains($pm, 'QRIS')) {
                $recListQris += $amt;
            } elseif (str_contains($pm, 'TRANSFER') || str_contains($pm, 'BANK') || str_contains($pm, 'BCA') || str_contains($pm, 'BRI') || str_contains($pm, 'MANDIRI')) {
                $recListTransfer += $amt;
            } elseif (str_contains($pm, 'DEBIT') || str_contains($pm, 'EDC') || str_contains($pm, 'KARTU') || str_contains($pm, 'CREDIT')) {
                $recListDebit += $amt;
            } else {
                $recListOther += $amt;
            }
        }
        $totalRecPayments = round($receivablePayments->sum('amount'), 2);
        if ($totalRecPayments > 0 && $receivableCashIn <= 0) {
            $receivableCashIn = $totalRecPayments;
        }

        $movQuery = StockMovement::with(['ingredient'])
            ->where('type', 'PURCHASE')
            ->whereBetween('date', [$from, $to])
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId));
        $purchaseMovements = $movQuery->get();

        $purchaseItemsSummary = [];
        foreach ($purchaseMovements as $m) {
            $ing = $m->ingredient;
            if ($ing) {
                $ingId = $ing->id;
                if (!isset($purchaseItemsSummary[$ingId])) {
                    $purchaseItemsSummary[$ingId] = ['name' => $ing->name, 'unit' => $ing->unit_pakai, 'qty' => 0, 'total' => 0];
                }
                $purchaseItemsSummary[$ingId]['qty'] += (float)$m->qty;
                $purchaseItemsSummary[$ingId]['total'] += (float)$m->total_price;
            }
        }
        usort($purchaseItemsSummary, fn($a, $b) => $b['total'] <=> $a['total']);
        $topPurchases = array_slice($purchaseItemsSummary, 0, 5);

        // Reconciliation with Journal P&L
        $reportCtrl = new ReportController();
        $plRequest = new Request(['from' => $from, 'to' => $to, 'outlet_id' => $outletId]);
        if ($user) { $plRequest->setUserResolver(fn() => $user); }
        $plResponse = $reportCtrl->profitAndLoss($plRequest);
        $plData = json_decode($plResponse->getContent(), true);

        $accrualNetProfit = (float)($plData['bottom_line']['net_profit'] ?? 0);
        $cogsTheoretical = (float)($plData['cogs']['total_cogs'] ?? 0);
        $wasteLossNonCash = (float)($plData['waste']['total_waste_loss'] ?? 0);
        $inventoryCapitalChange = round($totalInventoryCashOut - $cogsTheoretical, 2);

        $reconciliationSteps = [
            [
                'step'        => 'accrual_profit',
                'title'       => 'Laba Bersih Usaha (P&L Jurnal Akrual)',
                'description' => 'Keuntungan operasional toko berdasarkan pencatatan jurnal laba rugi',
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
                'description' => 'Seluruh uang kas keluar untuk beli stok bahan mentah (termasuk yang tersimpan di gudang)',
                'amount'      => -$totalInventoryCashOut,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'waste_addback',
                'title'       => '(+) Penyesuaian Kerugian Waste Non-Kas',
                'description' => 'Bahan rusak mengurangi laba di P&L, tetapi tidak ada uang kas keluar saat bahan dibuang',
                'amount'      => $wasteLossNonCash,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'receivable_payment_add',
                'title'       => '(+) Penerimaan Kas dari Pembayaran Kasbon Pelanggan',
                'description' => 'Uang kas riil yang masuk dari pelunasan piutang customer',
                'amount'      => $receivableCashIn,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'capex_deduct',
                'title'       => '(-) Belanja Modal / Aset Resto (CapEx)',
                'description' => 'Pengeluaran kas untuk peralatan dan renovasi',
                'amount'      => -$investingOutflows,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'owner_withdrawal_deduct',
                'title'       => '(-) Prive / Penarikan Dana Tunai oleh Owner',
                'description' => 'Pengambilan kas pribadi oleh pemilik',
                'amount'      => -$financingOutflows,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'capital_injection_add',
                'title'       => '(+) Setoran Modal Tambahan / Pinjaman',
                'description' => 'Injeksi dana segar dari owner / pinjaman',
                'amount'      => $financingInflows,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'net_cash_result',
                'title'       => '(=) Perubahan Bersih Kas Riil (Net Cash Flow)',
                'description' => 'Total kas yang bertambah/berkurang di rekening & kasir dari jurnal',
                'amount'      => $netCashFlow,
                'effect'      => 'TOTAL',
            ],
        ];

        return response()->json([
            'period' => [
                'from'                     => $from,
                'to'                       => $to,
                'outlet_id'                => $outletId,
                'selected_account_code'    => $selectedAccountCode,
                'selected_account_name'    => $targetAccountName,
                'selected_payment_method'  => $pmFilter,
                'initial_cash_total'       => $initialCashBalance,
                'closing_cash_total'       => $closingCashTotal,
                'net_cash_flow'            => $netCashFlow,
            ],
            'selected_account_code'        => $selectedAccountCode,
            'selected_account_name'        => $targetAccountName,
            'cash_accounts_overview'       => $cashAccountsOverview,
            'total_accounts_overview'      => [
                'initial_balance' => round($totalInitialAll, 2),
                'total_debit'     => round($totalDebitAll, 2),
                'total_credit'    => round($totalCreditAll, 2),
                'net_change'      => round($totalDebitAll - $totalCreditAll, 2),
                'ending_balance'  => round($totalEndingAll, 2),
            ],
            'inflow_items'                 => array_values(array_reverse($detailedInflows)),
            'outflow_items'                => array_values(array_reverse($detailedOutflows)),
            'total_inflows'                => round($totalOperatingInflows + $investingInflows + $financingInflows, 2),
            'total_outflows'               => round($totalOperatingOutflows + $investingOutflows + $financingOutflows, 2),
            'journal_lines'                => array_values($journalLinesAuditTrail),
            'source'                       => 'GENERAL_LEDGER_JOURNALS',
            'available_shifts'             => $availableShifts,
            'summary' => [
                'initial_cash_total'       => $initialCashBalance,
                'closing_cash_total'       => $closingCashTotal,
                'net_operating_cash_flow'  => $netOperatingCashFlow,
                'net_investing_cash_flow'  => $netInvestingCashFlow,
                'net_financing_cash_flow'  => $netFinancingCashFlow,
                'net_cash_flow'            => $netCashFlow,
                'accrual_net_profit'       => $accrualNetProfit,
                'inventory_cash_trapped'   => $inventoryCapitalChange,
                'liquidity_status'         => $netCashFlow >= 0 ? 'SURPLUS' : 'DEFICIT',
                'liquidity_label'          => $netCashFlow >= 0 ? 'Kas Surplus (Likuid Prima)' : 'Kas Defisit (Ketat / Waspada)',
                'liquidity_color'          => $netCashFlow >= 0 ? '#10B981' : '#EF4444',
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
                    'receivable_collections' => round(count($receivablePayments) > 0 ? $totalRecPayments : $receivableCashIn, 2),
                    'receivable_breakdown'   => [
                        'cash'     => round(count($receivablePayments) > 0 ? $recListCash : $recPayCash, 2),
                        'transfer' => round(count($receivablePayments) > 0 ? $recListTransfer : $recPayTransfer, 2),
                        'qris'     => round(count($receivablePayments) > 0 ? $recListQris : $recPayQris, 2),
                        'debit'    => round(count($receivablePayments) > 0 ? $recListDebit : $recPayDebit, 2),
                        'other'    => round(count($receivablePayments) > 0 ? $recListOther : $recPayOther, 2),
                        'count'    => $receivablePayments->count(),
                    ],
                    'combined_breakdown'     => [
                        'cash'     => round($cashSales + (count($receivablePayments) > 0 ? $recListCash : $recPayCash), 2),
                        'qris'     => round($qrisSales + (count($receivablePayments) > 0 ? $recListQris : $recPayQris), 2),
                        'grab'     => round($grabSales, 2),
                        'transfer' => round($transferSales + (count($receivablePayments) > 0 ? $recListTransfer : $recPayTransfer), 2),
                        'debit'    => round($debitSales + (count($receivablePayments) > 0 ? $recListDebit : $recPayDebit), 2),
                        'other'    => round($otherSales + (count($receivablePayments) > 0 ? $recListOther : $recPayOther), 2),
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
                    ])->values(),
                    'unpaid_kasbon_omzet'    => 0.0,
                    'extra_income'           => round($extraOpIn, 2),
                    'shift_cash_over'        => round($shiftCashOver, 2),
                    'total_inflows'          => round($totalOperatingInflows, 2),
                ],
                'outflows' => [
                    'stock_purchases'        => round($totalInventoryCashOut, 2),
                    'stock_purchases_direct' => round($stockPurchasesCashTotal, 2),
                    'supplier_debt_payments' => round($supplierDebtCashOut, 2),
                    'opex_expenses'          => round($totalOpexCashOut, 2),
                    'shift_cash_short'       => round($shiftCashShort, 2),
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
                    ? "Terdapat kas sebesar Rp " . number_format($inventoryCapitalChange, 0, ',', '.') . " yang berada dalam persediaan stok bahan baku."
                    : "Pembelian kas bahan baku lebih hemat dibandingkan pemakaian menu terjual.",
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
            ->orderByDesc('created_at')
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

        $shiftId = $request->input('shift_id');
        if (!$shiftId && $outletId) {
            $shiftId = \App\Models\Shift::where('status', 'OPEN')
                ->where('outlet_id', $outletId)
                ->orderByDesc('opened_at')
                ->value('id');
        }

        $trx = CashTransaction::create([
            'business_id'    => $businessId,
            'outlet_id'      => $outletId,
            'shift_id'       => $shiftId,
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

        // Auto-post double-entry journal ledger
        AccountingService::postCashTransaction($trx);

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

        // Auto-post double-entry journal ledger
        AccountingService::postCashTransaction($trx);

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

        JournalEntry::where('reference_type', 'CASH_TRANSACTION')
            ->where('reference_id', $trx->id)
            ->delete();

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
