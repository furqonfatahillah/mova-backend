<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperatingExpense;
use App\Models\Shift;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class KasBesarController extends Controller
{
    /**
     * Get summary & mutasi history of Kas Besar (1-11001)
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        // Date filter
        $from = $request->input('from');
        $to = $request->input('to');
        $today = now()->toDateString();
        $thisMonthStart = now()->startOfMonth()->toDateString();
        $thisMonthEnd = now()->endOfMonth()->toDateString();

        // 1. Base query for JournalLines (Supports both 1-11001 and sub-accounts 1-11001.01, etc.)
        $jlBase = JournalLine::where(function ($q) {
                $q->where('account_code', '1-11001')
                  ->orWhere('account_code', 'like', '1-11001.%');
            })
            ->whereHas('journalEntry', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            });

        if ($outletId) {
            $jlBase->where(function ($q) use ($outletId) {
                $q->where('journal_lines.outlet_id', $outletId)
                  ->orWhereNull('journal_lines.outlet_id');
            });
        }

        // 2. Base query for CashTransactions linked strictly to Kas Besar (Brankas Toko)
        $ctBase = CashTransaction::where('business_id', $businessId)
            ->where(function ($q) {
                $q->where('account', 'KAS_BESAR')
                  ->orWhere('account', 'like', '%KAS BESAR%')
                  ->orWhere('account', 'like', '%BRANKAS%');
            })
            ->where(function ($q) {
                // Strictly exclude bank, drawer cash, and app cash accounts
                $q->where('account', '!=', 'BANK_MAIN')
                  ->where('account', 'not like', '%BANK%')
                  ->where('account', '!=', 'KAS_KECIL')
                  ->where('account', '!=', 'PETTY_CASH')
                  ->where('account', '!=', 'KAS_APLIKASI');
            })
            ->where(function ($q) {
                $q->where('status', 'APPROVED')->orWhereNull('status');
            });

        if ($outletId) {
            $ctBase->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        // 3. Find CashTransactions that are already linked to JournalLines
        $linkedCashIds = JournalEntry::where('business_id', $businessId)
            ->where('reference_type', 'CASH_TRANSACTION')
            ->whereNotNull('reference_id')
            ->pluck('reference_id')
            ->toArray();

        // 4. Calculate All-time Inflow and Outflow
        $allTimeInflowJl = (float) (clone $jlBase)->sum('debit');
        $allTimeOutflowJl = (float) (clone $jlBase)->sum('credit');

        // Unposted CTs (not in linkedCashIds)
        $unpostedCtQuery = (clone $ctBase);
        if (!empty($linkedCashIds)) {
            $unpostedCtQuery->whereNotIn('id', $linkedCashIds);
        }

        $allTimeInflowCt = (float) (clone $unpostedCtQuery)->where('type', 'IN')->sum('amount');
        $allTimeOutflowCt = (float) (clone $unpostedCtQuery)->where('type', 'OUT')->sum('amount');

        $allTimeInflow = round($allTimeInflowJl + $allTimeInflowCt, 2);
        $allTimeOutflow = round($allTimeOutflowJl + $allTimeOutflowCt, 2);
        $currentBalance = round($allTimeInflow - $allTimeOutflow, 2);

        // 5. Calculate Period Inflow and Outflow
        $periodJl = clone $jlBase;
        if ($from) $periodJl->where('entry_date', '>=', $from);
        if ($to)   $periodJl->where('entry_date', '<=', $to);

        $periodInflowJl = (float) (clone $periodJl)->sum('debit');
        $periodOutflowJl = (float) (clone $periodJl)->sum('credit');

        $periodCt = clone $unpostedCtQuery;
        if ($from) {
            $periodCt->where(function ($q) use ($from) {
                $q->whereDate('date', '>=', $from)
                  ->orWhere(function ($sq) use ($from) {
                      $sq->whereNull('date')->whereDate('created_at', '>=', $from);
                  });
            });
        }
        if ($to) {
            $periodCt->where(function ($q) use ($to) {
                $q->whereDate('date', '<=', $to)
                  ->orWhere(function ($sq) use ($to) {
                      $sq->whereNull('date')->whereDate('created_at', '<=', $to);
                  });
            });
        }

        $periodInflowCt = (float) (clone $periodCt)->where('type', 'IN')->sum('amount');
        $periodOutflowCt = (float) (clone $periodCt)->where('type', 'OUT')->sum('amount');

        $periodInflow = round($periodInflowJl + $periodInflowCt, 2);
        $periodOutflow = round($periodOutflowJl + $periodOutflowCt, 2);

        // Period setoran to bank
        $periodToBankJl = (float) (clone $periodJl)
            ->where('credit', '>', 0)
            ->whereHas('journalEntry', function ($q) {
                $q->where('entry_type', 'SETOR_KAS_BESAR_KE_BANK')
                  ->orWhereHas('lines', function ($lq) {
                      $lq->where(function ($bq) {
                          $bq->where('account_code', '1-11003')
                             ->orWhere('account_code', 'like', '1-11003.%');
                      })->where('debit', '>', 0);
                  });
            })
            ->sum('credit');

        $periodToBankCt = (float) (clone $periodCt)
            ->where('type', 'OUT')
            ->where('category', 'SETOR_KAS_BESAR_KE_BANK')
            ->sum('amount');

        $periodToBank = round($periodToBankJl + $periodToBankCt, 2);

        // Today & Month stats
        $todayInflow = round(
            (float) (clone $jlBase)->where('entry_date', $today)->sum('debit') +
            (float) (clone $unpostedCtQuery)->whereDate('date', $today)->where('type', 'IN')->sum('amount'),
            2
        );
        $todayOutflow = round(
            (float) (clone $jlBase)->where('entry_date', $today)->sum('credit') +
            (float) (clone $unpostedCtQuery)->whereDate('date', $today)->where('type', 'OUT')->sum('amount'),
            2
        );

        $monthInflow = round(
            (float) (clone $jlBase)->whereBetween('entry_date', [$thisMonthStart, $thisMonthEnd])->sum('debit') +
            (float) (clone $unpostedCtQuery)->whereBetween('date', [$thisMonthStart, $thisMonthEnd])->where('type', 'IN')->sum('amount'),
            2
        );
        $monthOutflow = round(
            (float) (clone $jlBase)->whereBetween('entry_date', [$thisMonthStart, $thisMonthEnd])->sum('credit') +
            (float) (clone $unpostedCtQuery)->whereBetween('date', [$thisMonthStart, $thisMonthEnd])->where('type', 'OUT')->sum('amount'),
            2
        );

        // 6. Bank Accounts for Deposit destination
        $bankAccountsQuery = BankAccount::where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($q) use ($outletId) {
                if ($outletId) {
                    $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                }
            });

        if ($outletId) {
            $bankAccountsQuery->orderByRaw('CASE WHEN outlet_id = ? THEN 0 ELSE 1 END', [$outletId]);
        }

        $bankAccounts = $bankAccountsQuery
            ->orderBy('is_primary', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        $primaryBankAccount = $bankAccounts->firstWhere('is_primary', true) ?: $bankAccounts->first();

        // 7. Opening balance before $from date
        $openingBalance = 0.0;
        if ($from) {
            $openingInJl = (float) (clone $jlBase)->where('entry_date', '<', $from)->sum('debit');
            $openingOutJl = (float) (clone $jlBase)->where('entry_date', '<', $from)->sum('credit');

            $openingInCt = (float) (clone $unpostedCtQuery)->whereDate('date', '<', $from)->where('type', 'IN')->sum('amount');
            $openingOutCt = (float) (clone $unpostedCtQuery)->whereDate('date', '<', $from)->where('type', 'OUT')->sum('amount');

            $openingBalance = round(($openingInJl + $openingInCt) - ($openingOutJl + $openingOutCt), 2);
        }

        // 8. Fetch Mutasi Lines in period from JournalLine
        $lines = (clone $periodJl)
            ->with([
                'journalEntry.lines.account',
                'journalEntry.creator:id,name',
                'outlet:id,name',
            ])
            ->orderBy('entry_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Preload associated CashTransactions and OperatingExpenses for rich audit trail
        $refCashIds = [];
        $refOpexIds = [];
        foreach ($lines as $line) {
            $entry = $line->journalEntry;
            if ($entry) {
                if ($entry->reference_type === 'CASH_TRANSACTION' && $entry->reference_id) {
                    $refCashIds[] = $entry->reference_id;
                } elseif ($entry->reference_type === 'OPERATING_EXPENSE' && $entry->reference_id) {
                    $refOpexIds[] = $entry->reference_id;
                }
            }
        }

        $cashTransactions = empty($refCashIds) ? collect() : CashTransaction::with(['shift.user:id,name', 'user:id,name', 'approvedBy:id,name'])
            ->whereIn('id', array_unique($refCashIds))
            ->get()
            ->keyBy('id');

        $operatingExpenses = empty($refOpexIds) ? collect() : OperatingExpense::with(['category', 'user:id,name'])
            ->whereIn('id', array_unique($refOpexIds))
            ->get()
            ->keyBy('id');

        // Build history items
        $rawHistory = [];

        foreach ($lines as $line) {
            $entry = $line->journalEntry;
            $isIn = (float)$line->debit > 0;
            $amt = $isIn ? (float)$line->debit : (float)$line->credit;

            // Find opposite lines in the same journal entry
            $oppositeLines = $entry ? $entry->lines->where('id', '!=', $line->id) : collect();
            $mainOpposite = $oppositeLines->first();

            $oppCode = $mainOpposite?->account_code ?? '-';
            $oppName = $mainOpposite?->account?->name ?? $oppCode;

            $entryType = $entry?->entry_type ?? 'MUTASI';
            $refType = $entry?->reference_type;
            $refId = $entry?->reference_id;

            $sourceText = '-';
            $destinationText = '-';
            $categoryLabel = 'Mutasi Kas Besar';
            $categoryKey = $entryType;
            $receiptUrl = null;
            $notes = $line->memo ?: ($entry?->description ?? '-');
            $refNo = $entry?->entry_no ?? ('KB-' . $line->id);
            $userName = $entry?->creator?->name ?? '-';

            // Enrich from CashTransaction if available
            if ($refType === 'CASH_TRANSACTION' && isset($cashTransactions[$refId])) {
                $ct = $cashTransactions[$refId];
                $refNo = $ct->transaction_no ?: $refNo;
                $categoryKey = $ct->category;
                $receiptUrl = $ct->receipt_img ? (str_starts_with($ct->receipt_img, 'http') ? $ct->receipt_img : Storage::url($ct->receipt_img)) : null;
                $notes = $ct->notes ?: $notes;
                $userName = $ct->user?->name ?: $userName;

                if ($ct->category === 'SETORAN_KASIR' || $ct->category === 'SETORAN_SHIFT') {
                    $categoryLabel = 'Setoran Kasir Shift';
                    $shiftCashier = $ct->shift?->user?->name ?? 'Kasir';
                    $shiftName = $ct->shift?->shift_name ?? ("Shift #" . $ct->shift_id);
                    $sourceText = "Kasir {$shiftCashier} ({$shiftName})";
                    $destinationText = "Kas Besar (Brankas)";
                } elseif ($ct->category === 'TARIK_MODAL_KASIR') {
                    $categoryLabel = 'Modal Awal Kasir Shift';
                    $shiftCashier = $ct->shift?->user?->name ?? 'Kasir';
                    $shiftName = $ct->shift?->shift_name ?? ("Shift #" . $ct->shift_id);
                    $sourceText = "Kas Besar (Brankas)";
                    $destinationText = "Kasir {$shiftCashier} ({$shiftName})";
                } elseif ($ct->category === 'SETOR_KAS_BESAR_KE_BANK') {
                    $categoryLabel = 'Setoran Kas Besar ke Bank';
                    $sourceText = "Kas Besar (Brankas)";
                    $destinationText = $ct->name ?: "Rekening Bank";
                } elseif ($ct->category === 'CAPITAL_INJECTION' || $ct->category === 'MODAL_MASUK' || $ct->category === 'MODAL_KAS_BESAR') {
                    $categoryLabel = 'Injeksi Modal Pemilik';
                    $sourceText = "Setoran Modal Pemilik / Investor";
                    $destinationText = "Kas Besar (Brankas)";
                } elseif ($ct->category === 'OWNER_WITHDRAWAL') {
                    $categoryLabel = 'Penarikan Dana Owner (Prive)';
                    $sourceText = "Kas Besar (Brankas)";
                    $destinationText = "Penarikan Owner (Prive)";
                } elseif ($ct->category === 'SUPPLIER_PURCHASE') {
                    $categoryLabel = 'Belanja Bahan Baku Langsung';
                    $sourceText = "Kas Besar (Brankas)";
                    $destinationText = "Supplier Bahan / Belanja Pasar";
                } else {
                    $categoryLabel = $ct->category_label ?? $ct->category;
                    if ($isIn) {
                        $sourceText = $ct->name ?: $oppName;
                        $destinationText = "Kas Besar (Brankas)";
                    } else {
                        $sourceText = "Kas Besar (Brankas)";
                        $destinationText = $ct->name ?: $oppName;
                    }
                }
            } elseif ($refType === 'OPERATING_EXPENSE' && isset($operatingExpenses[$refId])) {
                $oe = $operatingExpenses[$refId];
                $refNo = $oe->expense_no ?: $refNo;
                $categoryKey = 'OPEX';
                $categoryLabel = 'Beban Operasional (OPEX)';
                $sourceText = "Kas Besar (Brankas)";
                $destinationText = ($oe->category?->name ?? 'OPEX') . " ({$oe->name})";
                $notes = $oe->notes ?: $notes;
                $userName = $oe->user?->name ?: $userName;
                $receiptUrl = $oe->receipt_image ? (str_starts_with($oe->receipt_image, 'http') ? $oe->receipt_image : Storage::url($oe->receipt_image)) : null;
            } else {
                if ($entryType === 'SETOR_KAS_BESAR_KE_BANK' || str_starts_with($oppCode, '1-11003')) {
                    $categoryLabel = 'Setoran Kas Besar ke Bank';
                    $sourceText = "Kas Besar (Brankas)";
                    $destinationText = "Rekening Bank ({$oppName})";
                } elseif ($entryType === 'SETORAN_KASIR' || str_starts_with($oppCode, '1-11002')) {
                    if ($isIn) {
                        $categoryLabel = 'Setoran Kasir Shift';
                        $sourceText = "Kasir ({$oppName})";
                        $destinationText = "Kas Besar (Brankas)";
                    } else {
                        $categoryLabel = 'Modal Awal Kasir Shift';
                        $sourceText = "Kas Besar (Brankas)";
                        $destinationText = "Kasir ({$oppName})";
                    }
                } elseif (str_starts_with($oppCode, '6-')) {
                    $categoryLabel = 'Beban Operasional';
                    $sourceText = "Kas Besar (Brankas)";
                    $destinationText = "Beban ({$oppName})";
                } elseif (str_starts_with($oppCode, '5-')) {
                    $categoryLabel = 'Pembelian Bahan Baku';
                    $sourceText = "Kas Besar (Brankas)";
                    $destinationText = "HPP / Bahan Baku ({$oppName})";
                } elseif (str_starts_with($oppCode, '4-')) {
                    $categoryLabel = 'Pendapatan Lain-Lain';
                    $sourceText = "Pendapatan ({$oppName})";
                    $destinationText = "Kas Besar (Brankas)";
                } else {
                    if ($isIn) {
                        $sourceText = $oppName;
                        $destinationText = "Kas Besar (Brankas)";
                    } else {
                        $sourceText = "Kas Besar (Brankas)";
                        $destinationText = $oppName;
                    }
                }
            }

            $entryDateStr = $line->entry_date ? $line->entry_date->toDateString() : ($entry?->entry_date ? $entry->entry_date->toDateString() : $today);

            $rawHistory[] = [
                'id'                     => 'JL-' . $line->id,
                'date'                   => $entryDateStr,
                'time'                   => $line->created_at ? $line->created_at->format('H:i') : '-',
                'created_at'             => $line->created_at ? $line->created_at->toIso8601String() : $entryDateStr,
                'sort_timestamp'         => $line->created_at ? $line->created_at->timestamp : strtotime($entryDateStr),
                'ref_no'                 => $refNo,
                'journal_entry_id'       => $entry?->id,
                'entry_no'               => $entry?->entry_no,
                'type'                   => $isIn ? 'IN' : 'OUT',
                'category'               => $categoryKey,
                'category_label'         => $categoryLabel,
                'source'                 => $sourceText,
                'destination'            => $destinationText,
                'flow_label'             => $isIn ? "Dari: {$sourceText}" : "Ke: {$destinationText}",
                'amount'                 => $amt,
                'opposite_account_code'  => $oppCode,
                'opposite_account_name'  => $oppName,
                'notes'                  => $notes,
                'receipt_url'            => $receiptUrl,
                'user_name'              => $userName,
                'outlet_name'            => $line->outlet?->name ?? 'Semua Cabang',
            ];
        }

        // 9. Also add unposted CashTransactions to history
        $unpostedCtList = (clone $periodCt)
            ->with(['shift.user:id,name', 'user:id,name', 'approvedBy:id,name', 'outlet:id,name'])
            ->get();

        foreach ($unpostedCtList as $ct) {
            $isIn = strtoupper($ct->type) === 'IN';
            $amt = (float)$ct->amount;

            $sourceText = '-';
            $destinationText = '-';
            $categoryLabel = 'Mutasi Kas Besar';

            if ($ct->category === 'SETORAN_KASIR' || $ct->category === 'SETORAN_SHIFT') {
                $categoryLabel = 'Setoran Kasir Shift';
                $shiftCashier = $ct->shift?->user?->name ?? 'Kasir';
                $shiftName = $ct->shift?->shift_name ?? ("Shift #" . $ct->shift_id);
                $sourceText = "Kasir {$shiftCashier} ({$shiftName})";
                $destinationText = "Kas Besar (Brankas)";
            } elseif ($ct->category === 'TARIK_MODAL_KASIR') {
                $categoryLabel = 'Modal Awal Kasir Shift';
                $shiftCashier = $ct->shift?->user?->name ?? 'Kasir';
                $shiftName = $ct->shift?->shift_name ?? ("Shift #" . $ct->shift_id);
                $sourceText = "Kas Besar (Brankas)";
                $destinationText = "Kasir {$shiftCashier} ({$shiftName})";
            } elseif ($ct->category === 'SETOR_KAS_BESAR_KE_BANK') {
                $categoryLabel = 'Setoran Kas Besar ke Bank';
                $sourceText = "Kas Besar (Brankas)";
                $destinationText = $ct->name ?: "Rekening Bank";
            } elseif ($ct->category === 'CAPITAL_INJECTION' || $ct->category === 'MODAL_MASUK' || $ct->category === 'MODAL_KAS_BESAR') {
                $categoryLabel = 'Injeksi Modal Pemilik';
                $sourceText = "Setoran Modal Pemilik / Investor";
                $destinationText = "Kas Besar (Brankas)";
            } elseif ($ct->category === 'OWNER_WITHDRAWAL') {
                $categoryLabel = 'Penarikan Dana Owner (Prive)';
                $sourceText = "Kas Besar (Brankas)";
                $destinationText = "Penarikan Owner (Prive)";
            } else {
                $categoryLabel = $ct->category_label ?? $ct->category;
                if ($isIn) {
                    $sourceText = $ct->name ?: "Sumber Dana";
                    $destinationText = "Kas Besar (Brankas)";
                } else {
                    $sourceText = "Kas Besar (Brankas)";
                    $destinationText = $ct->name ?: "Tujuan Dana";
                }
            }

            $receiptUrl = $ct->receipt_img ? (str_starts_with($ct->receipt_img, 'http') ? $ct->receipt_img : Storage::url($ct->receipt_img)) : null;
            $ctDateStr = $ct->date ? \Carbon\Carbon::parse($ct->date)->toDateString() : ($ct->created_at ? $ct->created_at->toDateString() : $today);

            $rawHistory[] = [
                'id'                     => 'CT-' . $ct->id,
                'date'                   => $ctDateStr,
                'time'                   => $ct->created_at ? $ct->created_at->format('H:i') : '-',
                'created_at'             => $ct->created_at ? $ct->created_at->toIso8601String() : $ctDateStr,
                'sort_timestamp'         => $ct->created_at ? $ct->created_at->timestamp : strtotime($ctDateStr),
                'ref_no'                 => $ct->transaction_no ?: ('TRX-KB-' . $ct->id),
                'journal_entry_id'       => null,
                'entry_no'               => null,
                'type'                   => $isIn ? 'IN' : 'OUT',
                'category'               => $ct->category,
                'category_label'         => $categoryLabel,
                'source'                 => $sourceText,
                'destination'            => $destinationText,
                'flow_label'             => $isIn ? "Dari: {$sourceText}" : "Ke: {$destinationText}",
                'amount'                 => $amt,
                'opposite_account_code'  => '-',
                'opposite_account_name'  => '-',
                'notes'                  => $ct->notes ?: ($ct->description ?: '-'),
                'receipt_url'            => $receiptUrl,
                'user_name'              => $ct->user?->name ?? ($ct->shift?->user?->name ?? '-'),
                'outlet_name'            => $ct->outlet?->name ?? 'Semua Cabang',
            ];
        }

        // 10. Sort all combined history items chronologically to compute running balance
        usort($rawHistory, function ($a, $b) {
            $cmp = strcmp($a['date'], $b['date']);
            if ($cmp !== 0) return $cmp;
            return ($a['sort_timestamp'] ?? 0) <=> ($b['sort_timestamp'] ?? 0);
        });

        $running = $openingBalance;
        $history = [];
        foreach ($rawHistory as $item) {
            if ($item['type'] === 'IN') {
                $running += (float)$item['amount'];
            } else {
                $running -= (float)$item['amount'];
            }
            $item['running_balance'] = round($running, 2);
            $history[] = $item;
        }

        // Return latest first for table view
        $historySorted = array_reverse($history);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'current_balance'      => $currentBalance,
                'all_time_inflow'      => $allTimeInflow,
                'all_time_outflow'     => $allTimeOutflow,
                'period_inflow'        => $periodInflow,
                'period_outflow'       => $periodOutflow,
                'period_to_bank'       => $periodToBank,
                'today_inflow'         => $todayInflow,
                'today_outflow'        => $todayOutflow,
                'month_inflow'         => $monthInflow,
                'month_outflow'        => $monthOutflow,
                'opening_balance'      => $openingBalance,
                'primary_bank_account' => $primaryBankAccount,
                'bank_accounts'        => $bankAccounts,
                'history'              => $historySorted,
            ],
        ]);
    }

    /**
     * Setor Kas Besar ke Rekening Bank (STRICTLY ONE-WAY: Kas Besar -> Bank only)
     */
    public function depositToBank(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        // Validation
        $request->validate([
            'amount'          => 'required|numeric|min:1000',
            'bank_account_id' => 'nullable',
            'date'            => 'required|date',
            'notes'           => 'nullable|string|max:500',
            'receipt_img'     => 'nullable|file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
        ]);

        $amount = round((float)$request->amount, 2);
        $date = $request->date;

        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        // 1. Verify Destination Bank Account if provided
        $bankAccount = null;
        if ($request->filled('bank_account_id')) {
            $bankAccount = BankAccount::where('id', $request->bank_account_id)
                ->where('business_id', $businessId)
                ->first();
        }

        // 2. Strict Check: Verify Current Kas Besar balance is sufficient
        $jlBase = JournalLine::where(function ($q) {
                $q->where('account_code', '1-11001')
                  ->orWhere('account_code', 'like', '1-11001.%');
            })
            ->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));

        if ($outletId) {
            $jlBase->where(fn($q) => $q->where('outlet_id', $outletId)->orWhereNull('outlet_id'));
        }

        $allTimeInflowJl = (float) (clone $jlBase)->sum('debit');
        $allTimeOutflowJl = (float) (clone $jlBase)->sum('credit');

        // Check unposted CashTransactions
        $linkedCashIds = JournalEntry::where('business_id', $businessId)
            ->where('reference_type', 'CASH_TRANSACTION')
            ->whereNotNull('reference_id')
            ->pluck('reference_id')
            ->toArray();

        // Base query for CashTransactions linked strictly to Kas Besar (Brankas Toko)
        $ctBase = CashTransaction::where('business_id', $businessId)
            ->where(function ($q) {
                $q->where('account', 'KAS_BESAR')
                  ->orWhere('account', 'like', '%KAS BESAR%')
                  ->orWhere('account', 'like', '%BRANKAS%');
            })
            ->where(function ($q) {
                // Strictly exclude bank, drawer cash, and app cash accounts
                $q->where('account', '!=', 'BANK_MAIN')
                  ->where('account', 'not like', '%BANK%')
                  ->where('account', '!=', 'KAS_KECIL')
                  ->where('account', '!=', 'PETTY_CASH')
                  ->where('account', '!=', 'KAS_APLIKASI');
            })
            ->where(function ($q) {
                $q->where('status', 'APPROVED')->orWhereNull('status');
            });

        if ($outletId) {
            $ctBase->where(fn($q) => $q->where('outlet_id', $outletId)->orWhereNull('outlet_id'));
        }

        if (!empty($linkedCashIds)) {
            $ctBase->whereNotIn('id', $linkedCashIds);
        }

        $allTimeInflowCt = (float) (clone $ctBase)->where('type', 'IN')->sum('amount');
        $allTimeOutflowCt = (float) (clone $ctBase)->where('type', 'OUT')->sum('amount');

        $currentBalance = round(($allTimeInflowJl + $allTimeInflowCt) - ($allTimeOutflowJl + $allTimeOutflowCt), 2);

        if ($amount > $currentBalance) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Saldo Kas Besar tidak mencukupi untuk melakukan setoran ini. Saldo saat ini: Rp ' . number_format($currentBalance, 0, ',', '.') . ', diminta setor: Rp ' . number_format($amount, 0, ',', '.'),
            ], 422);
        }

        // 3. Handle Receipt Upload if provided
        $receiptPath = null;
        if ($request->hasFile('receipt_img')) {
            $file = $request->file('receipt_img');
            $filename = 'kas_besar_setor_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $receiptPath = $file->storeAs('kas_besar_receipts', $filename, 'public');
        }

        // 4. Create CashTransaction (Strictly OUT from Kas Besar to Bank)
        $transactionNo = CashTransaction::generateTransactionNo($businessId, $date);
        $bankNameString = $bankAccount
            ? "{$bankAccount->bank_name} ({$bankAccount->account_number} a.n. {$bankAccount->account_holder})"
            : "Rekening Bank";
        $txName = $bankAccount
            ? "Setoran Kas Besar ke Rekening {$bankAccount->bank_name}"
            : "Setoran Kas Besar ke Rekening Bank";

        DB::beginTransaction();
        try {
            $ct = CashTransaction::create([
                'business_id'      => $businessId,
                'outlet_id'        => $outletId,
                'transaction_no'   => $transactionNo,
                'date'             => $date,
                'type'             => 'OUT',
                'activity_type'    => 'OPERATING',
                'category'         => 'SETOR_KAS_BESAR_KE_BANK',
                'status'           => 'APPROVED',
                'name'             => $txName,
                'amount'           => $amount,
                'account'          => 'KAS_BESAR',
                'payment_method'   => 'BANK_TRANSFER',
                'notes'            => "Penyetoran Kas Besar ke {$bankNameString}. " . ($request->notes ?? ''),
                'receipt_img'      => $receiptPath,
                'user_id'          => $user->id,
                'approved_by'      => $user->id,
                'approved_at'      => now(),
                'rejection_reason' => null,
                'created_by'       => $user->id,
            ]);

            // Post double-entry journal (Debit: 1-11003 Bank, Credit: 1-11001 Kas Besar)
            AccountingService::postCashTransaction($ct);

            DB::commit();

            return response()->json([
                'status'           => 'success',
                'message'          => 'Penyetoran Kas Besar ke Rekening Bank senilai Rp ' . number_format($amount, 0, ',', '.') . ' berhasil dibukukan!',
                'cash_transaction' => $ct,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal memproses setoran kas besar ke bank: ' . $e->getMessage(),
            ], 500);
        }
    }
}
