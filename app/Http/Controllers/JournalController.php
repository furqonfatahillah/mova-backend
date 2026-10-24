<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalController extends Controller
{
    private function formatIndonesianDate(string $dateStr): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $ts = strtotime($dateStr);
        $d = date('d', $ts);
        $m = (int)date('m', $ts);
        $y = date('Y', $ts);

        return "{$d} " . ($months[$m] ?? date('F', $ts)) . " {$y}";
    }

    /**
     * List Chart of Accounts with Balances & Grouped Overview (Buku Besar Group By Akun)
     */
    public function index(Request $request)
    {
        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-d'));

        $user = $request->user();
        $businessId = $user ? $user->business_id : null;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        $outletName = 'Semua Cabang (Konsolidasi)';
        if ($outletId) {
            $outletObj = Outlet::find($outletId);
            if ($outletObj) {
                $outletName = $outletObj->name;
            }
        }

        // Auto-seed COA & auto-sync if journal lines/entries are empty
        if ($businessId) {
            Account::seedForBusiness($businessId);
            $hasJournalLines = JournalLine::whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId))->exists();
            if (!$hasJournalLines) {
                AccountingService::syncAllHistoricalData($businessId);
            }
        }

        // 1. Query all Head accounts & outlets for business
        $accounts = Account::when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->whereNull('parent_code')
            ->where('is_active', true)
            ->orderBy('code', 'asc')
            ->get();

        $allOutlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where('active', true)
            ->orderBy('id', 'asc')
            ->get();

        // 2. Query lines grouped by account_code & outlet_id (Include period lines and initial balance lines)
        $linesQuery = JournalLine::where(function ($q) use ($from, $to) {
            $q->whereBetween('entry_date', [$from, $to])
              ->orWhere(function ($sub) use ($to) {
                  $sub->where('entry_date', '<=', $to)
                      ->whereHas('journalEntry', fn($je) => $je->where('entry_type', 'INITIAL_BALANCE'));
              });
        });
        if ($outletId) {
            $linesQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $linesQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }

        $allLinesInPeriod = (clone $linesQuery)
            ->select(
                'account_code',
                'outlet_id',
                DB::raw('SUM(debit) as total_debit'),
                DB::raw('SUM(credit) as total_credit'),
                DB::raw('COUNT(*) as tx_count')
            )
            ->groupBy('account_code', 'outlet_id')
            ->get();

        // Cumulative saldo up to $to
        $cumulativeQuery = JournalLine::where('entry_date', '<=', $to);
        if ($outletId) {
            $cumulativeQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $cumulativeQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }

        $allLinesCumulative = (clone $cumulativeQuery)
            ->select(
                'account_code',
                'outlet_id',
                DB::raw('SUM(debit) as total_debit'),
                DB::raw('SUM(credit) as total_credit')
            )
            ->groupBy('account_code', 'outlet_id')
            ->get();

        $groups = [
            '1' => ['key' => '1', 'prefix' => '1', 'label' => '1 - Aset & Kas (Assets)', 'color' => '#10b981', 'accounts' => []],
            '2' => ['key' => '2', 'prefix' => '2', 'label' => '2 - Kewajiban & Hutang (Liabilities)', 'color' => '#f43f5e', 'accounts' => []],
            '3' => ['key' => '3', 'prefix' => '3', 'label' => '3 - Modal & Ekuitas (Equity)', 'color' => '#38bdf8', 'accounts' => []],
            '4' => ['key' => '4', 'prefix' => '4', 'label' => '4 - Pendapatan (Revenue)', 'color' => '#a78bfa', 'accounts' => []],
            '5' => ['key' => '5', 'prefix' => '5', 'label' => '5 - Biaya Pokok Penjualan (HPP)', 'color' => '#f59e0b', 'accounts' => []],
            '6' => ['key' => '6', 'prefix' => '6', 'label' => '6 - Biaya Operasional (OPEX)', 'color' => '#ec4899', 'accounts' => []],
        ];

        $totalDebitAll = 0.0;
        $totalCreditAll = 0.0;

        foreach ($accounts as $acc) {
            $code = $acc->code;
            $prefix = substr($code, 0, 1);

            $isNormalDebit = $acc->normal_balance === 'DEBIT' || in_array($prefix, ['1', '5', '6']) || $code === '3-31002';

            // Filter lines belonging to this head account or any of its child sub-accounts (e.g. 1-13001, 1-13001.01, 1-13001.02)
            $matchingPeriodLines = $allLinesInPeriod->filter(function ($l) use ($code) {
                return $l->account_code === $code || str_starts_with($l->account_code, "{$code}.");
            });

            $matchingCumLines = $allLinesCumulative->filter(function ($l) use ($code) {
                return $l->account_code === $code || str_starts_with($l->account_code, "{$code}.");
            });

            $periodDebit  = (float)$matchingPeriodLines->sum('total_debit');
            $periodCredit = (float)$matchingPeriodLines->sum('total_credit');
            $txCount      = (int)$matchingPeriodLines->sum('tx_count');

            $cumDebit  = (float)$matchingCumLines->sum('total_debit');
            $cumCredit = (float)$matchingCumLines->sum('total_credit');

            $totalDebitAll += $periodDebit;
            $totalCreditAll += $periodCredit;

            $saldoAkhir = $isNormalDebit
                ? round($cumDebit - $cumCredit, 2)
                : round($cumCredit - $cumDebit, 2);

            $outletList = [];
            if ($allOutlets->count() > 1 && !$outletId) {
                foreach ($allOutlets as $index => $out) {
                    $suffix = sprintf('%02d', $index + 1);
                    $subAccountCode = "{$code}.{$suffix}";
                    $subAccountName = "{$acc->name} ({$out->name})";

                    $oLines = $matchingPeriodLines->where('outlet_id', $out->id);
                    $oCum = $matchingCumLines->where('outlet_id', $out->id);

                    $oDeb = (float)$oLines->sum('total_debit');
                    $oCrd = (float)$oLines->sum('total_credit');
                    $oTx = (int)$oLines->sum('tx_count');

                    $oCumDeb = (float)$oCum->sum('total_debit');
                    $oCumCrd = (float)$oCum->sum('total_credit');
                    $oSaldo = $isNormalDebit ? round($oCumDeb - $oCumCrd, 2) : round($oCumCrd - $oCumDeb, 2);

                    $outletList[] = [
                        'outlet_id'        => $out->id,
                        'outlet_name'      => $out->name,
                        'sub_account_code' => $subAccountCode,
                        'sub_account_name' => $subAccountName,
                        'period_debit'     => $oDeb,
                        'period_credit'    => $oCrd,
                        'saldo_akhir'      => $oSaldo,
                        'tx_count'         => $oTx,
                    ];
                }
            }

            $accData = [
                'id'                => $acc->id,
                'code'              => $acc->code,
                'name'              => $acc->name,
                'type'              => $acc->type,
                'category'          => $acc->category,
                'normal_balance'    => $isNormalDebit ? 'DEBIT' : 'CREDIT',
                'description'       => $acc->description,
                'period_debit'      => $periodDebit,
                'period_credit'     => $periodCredit,
                'saldo_akhir'       => $saldoAkhir,
                'tx_count'          => $txCount,
                'outlets_breakdown' => $outletList,
            ];

            if (isset($groups[$prefix])) {
                $groups[$prefix]['accounts'][] = $accData;
            }
        }

        $diff = round($totalDebitAll - $totalCreditAll, 2);
        $isBalanced = abs($diff) < 0.01;

        return response()->json([
            'period' => [
                'from'           => $from,
                'to'             => $to,
                'from_formatted' => $this->formatIndonesianDate($from),
                'to_formatted'   => $this->formatIndonesianDate($to),
                'outlet_id'      => $outletId,
                'outlet_name'    => $outletName,
            ],
            'summary' => [
                'total_debit'       => round($totalDebitAll, 2),
                'total_credit'      => round($totalCreditAll, 2),
                'difference'        => $diff,
                'is_balanced'       => $isBalanced,
                'total_accounts'    => $accounts->count(),
                'total_entries'     => JournalEntry::when($businessId, fn($q) => $q->where('business_id', $businessId))->count(),
            ],
            'groups' => array_values($groups),
        ]);
    }

    /**
     * Get Detailed Transactions / Ledger Lines for a specific Account (Buku Besar Rincian Mutasi Akun)
     */
    public function accountTransactions(Request $request, $code)
    {
        $accountCode = trim((string)$code);
        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-d'));

        $user = $request->user();
        $businessId = $user ? $user->business_id : null;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        $account = Account::where('code', $accountCode)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->first();

        $isHeadAccount = !str_contains($accountCode, '.');

        // 1. Saldo Awal sebelum tanggal $from (transaksi reguler non-INITIAL_BALANCE sebelum $from)
        $priorQuery = JournalLine::where('entry_date', '<', $from)
            ->whereDoesntHave('journalEntry', fn($q) => $q->where('entry_type', 'INITIAL_BALANCE'));
        if ($isHeadAccount) {
            $priorQuery->where(function ($q) use ($accountCode) {
                $q->where('account_code', $accountCode)
                  ->orWhere('account_code', 'like', "{$accountCode}.%");
            });
        } else {
            $priorQuery->where('account_code', $accountCode);
        }

        if ($outletId) {
            $priorQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $priorQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }

        $priorTotals = $priorQuery->select(DB::raw('SUM(debit) as prior_debit'), DB::raw('SUM(credit) as prior_credit'))->first();
        $priorDebit = (float)($priorTotals?->prior_debit ?? 0);
        $priorCredit = (float)($priorTotals?->prior_credit ?? 0);

        $prefix = substr($accountCode, 0, 1);
        $isNormalDebit = ($account && $account->normal_balance === 'DEBIT') || in_array($prefix, ['1', '5', '6']) || $accountCode === '3-31002';

        $saldoAwal = $isNormalDebit
            ? round($priorDebit - $priorCredit, 2)
            : round($priorCredit - $priorDebit, 2);

        // 2. Query lines during the period + Initial Balance Journals on or before $to
        $linesQuery = JournalLine::with(['journalEntry.creator', 'journalEntry.outlet', 'outlet', 'account'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('entry_date', [$from, $to])
                  ->orWhere(function ($sub) use ($to) {
                      $sub->where('entry_date', '<=', $to)
                          ->whereHas('journalEntry', fn($je) => $je->where('entry_type', 'INITIAL_BALANCE'));
                  });
            });

        if ($isHeadAccount) {
            $linesQuery->where(function ($q) use ($accountCode) {
                $q->where('account_code', $accountCode)
                  ->orWhere('account_code', 'like', "{$accountCode}.%");
            });
        } else {
            $linesQuery->where('account_code', $accountCode);
        }

        if ($outletId) {
            $linesQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $linesQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }

        $lines = $linesQuery->orderBy('entry_date', 'asc')->orderBy('id', 'asc')->get();

        $trxIds = $lines->pluck('journalEntry.reference_id')->filter()->unique();
        $transactionsMap = !empty($trxIds) ? \App\Models\Transaction::whereIn('id', $trxIds)->get()->keyBy('id') : collect();

        $runningBalance = $saldoAwal;
        $totalDebitPeriod = 0.0;
        $totalCreditPeriod = 0.0;

        $items = [];
        foreach ($lines as $l) {
            $je = $l->journalEntry;
            $d = (float)$l->debit;
            $c = (float)$l->credit;

            $totalDebitPeriod += $d;
            $totalCreditPeriod += $c;

            if ($isNormalDebit) {
                $runningBalance += ($d - $c);
            } else {
                $runningBalance += ($c - $d);
            }

            $dateStr = is_string($l->entry_date) ? substr($l->entry_date, 0, 10) : ($l->entry_date ? $l->entry_date->format('Y-m-d') : '-');
            $createdAt = $je?->created_at ?: $l->created_at;
            if ($je && in_array($je->reference_type, ['TRANSACTION', 'OPEN_BILL_SETTLEMENT', 'VOID_TRANSACTION']) && !empty($je->reference_id)) {
                $origTrx = $transactionsMap->get($je->reference_id);
                if ($origTrx && $origTrx->created_at) {
                    $createdAt = $origTrx->created_at;
                }
            }
            $createdAtStr = $createdAt ? $createdAt->toDateTimeString() : null;
            $timeStr = $createdAt ? $createdAt->format('H:i') : null;

            $items[] = [
                'id'               => $l->id,
                'journal_entry_id' => $l->journal_entry_id,
                'entry_no'         => $je?->entry_no ?: "JE-{$l->journal_entry_id}",
                'date'             => $dateStr,
                'time'             => $timeStr,
                'created_at'       => $createdAtStr,
                'entry_type'       => $je?->entry_type ?: 'GENERAL',
                'account_code'     => $l->account_code,
                'account_name'     => $l->account?->name ?: "Akun {$l->account_code}",
                'reference_type'   => $je?->reference_type,
                'reference_id'     => $je?->reference_id,
                'description'      => $l->memo ?: ($je?->description ?: '-'),
                'debit'            => $d,
                'credit'           => $c,
                'running_balance'  => round($runningBalance, 2),
                'outlet_name'      => $l->outlet?->name ?: ($je?->outlet?->name ?: 'Maroa – Cabang Utama (Pusat)'),
                'creator_name'     => $je?->creator?->name ?: 'Sistem',
            ];
        }

        // 3. Compute Outlets Dimension Breakdown (Sub-Akun Per Cabang)
        $outlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where('active', true)
            ->orderBy('id', 'asc')
            ->get();
        if ($outlets->isEmpty()) {
            $outlets = Outlet::all();
        }

        $mainOutlet = $outlets->firstWhere('is_main', true) ?? $outlets->first();
        $mainOutletId = $mainOutlet ? $mainOutlet->id : 1;

        $outletLinesQuery = JournalLine::where('entry_date', '<=', $to);
        if ($isHeadAccount) {
            $outletLinesQuery->where(function ($q) use ($accountCode) {
                $q->where('account_code', $accountCode)
                  ->orWhere('account_code', 'like', "{$accountCode}.%");
            });
        } else {
            $outletLinesQuery->where('account_code', $accountCode);
        }
        if ($businessId) {
            $outletLinesQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }

        $outletSums = (clone $outletLinesQuery)
            ->select('outlet_id', DB::raw('SUM(debit) as total_debit'), DB::raw('SUM(credit) as total_credit'), DB::raw('COUNT(*) as tx_count'))
            ->groupBy('outlet_id')
            ->get()
            ->keyBy('outlet_id');

        $nullRow = $outletSums->get(null) ?? $outletSums->get(0);

        $outletsBreakdown = [];
        foreach ($outlets as $out) {
            $row = $outletSums->get($out->id);
            $deb = (float)($row?->total_debit ?? 0);
            $crd = (float)($row?->total_credit ?? 0);
            $txCount = (int)($row?->tx_count ?? 0);

            if ($out->id === $mainOutletId && $nullRow) {
                $deb += (float)$nullRow->total_debit;
                $crd += (float)$nullRow->total_credit;
                $txCount += (int)$nullRow->tx_count;
            }

            $bal = $isNormalDebit ? round($deb - $crd, 2) : round($crd - $deb, 2);
            $bizId = $businessId ?: ($out->business_id ?? 1);
            $subCode = Account::getSubAccountCode($accountCode, $bizId, $out->id);

            $outletsBreakdown[] = [
                'outlet_id'    => $out->id,
                'sub_code'     => $subCode,
                'outlet_name'  => $out->name,
                'address'      => $out->address,
                'total_debit'  => $deb,
                'total_credit' => $crd,
                'balance'      => $bal,
                'tx_count'     => $txCount,
            ];
        }

        return response()->json([
            'account' => [
                'code'           => $accountCode,
                'name'           => $account?->name ?: "Akun {$accountCode}",
                'type'           => $account?->type,
                'category'       => $account?->category,
                'normal_balance' => $isNormalDebit ? 'DEBIT' : 'CREDIT',
                'description'    => $account?->description,
            ],
            'period' => [
                'from' => $from,
                'to'   => $to,
            ],
            'summary' => [
                'saldo_awal'    => $saldoAwal,
                'total_debit'   => round($totalDebitPeriod, 2),
                'total_credit'  => round($totalCreditPeriod, 2),
                'saldo_akhir'   => round($runningBalance, 2),
                'total_records' => count($items),
            ],
            'outlets_breakdown' => $outletsBreakdown,
            'outlets'           => $outletsBreakdown,
            // Return descending for latest first viewing, or reverse in UI
            'items' => array_reverse($items),
        ]);
    }

    /**
     * Get Complete Double-Entry Journal Voucher Drilldown by Entry ID or Entry No (Modal Drilldown Jurnal)
     */
    public function journalDetail(Request $request, $id)
    {
        $query = JournalEntry::with(['lines.outlet', 'lines.account', 'creator', 'outlet']);

        if (is_numeric($id)) {
            $entry = $query->find((int)$id);
        } else {
            $entry = $query->where('entry_no', $id)->first();
        }

        if (!$entry) {
            return response()->json(['message' => 'Bukti jurnal tidak ditemukan.'], 404);
        }

        $lines = $entry->lines->map(function ($l) use ($entry) {
            return [
                'id'           => $l->id,
                'account_code' => $l->account_code,
                'account_name' => $l->account?->name ?: "Akun {$l->account_code}",
                'debit'        => (float)$l->debit,
                'credit'       => (float)$l->credit,
                'memo'         => $l->memo,
                'outlet_name'  => $l->outlet?->name ?: ($entry->outlet?->name ?: 'Maroa – Cabang Utama (Pusat)'),
            ];
        });

        $actualCreatedAt = $entry->created_at;
        if (in_array($entry->reference_type, ['TRANSACTION', 'OPEN_BILL_SETTLEMENT', 'VOID_TRANSACTION']) && !empty($entry->reference_id)) {
            $origTrx = \App\Models\Transaction::find($entry->reference_id);
            if ($origTrx && $origTrx->created_at) {
                $actualCreatedAt = $origTrx->created_at;
            }
        }
        $rawDate = $entry->entry_date;
        $dateOnly = $rawDate ? (is_string($rawDate) ? substr($rawDate, 0, 10) : $rawDate->format('Y-m-d')) : date('Y-m-d');

        return response()->json([
            'id'             => $entry->id,
            'entry_no'       => $entry->entry_no,
            'entry_date'     => $dateOnly,
            'entry_type'     => $entry->entry_type,
            'reference_type' => $entry->reference_type,
            'reference_id'   => $entry->reference_id,
            'description'    => $entry->description,
            'total_debit'    => (float)$entry->total_debit,
            'total_credit'   => (float)$entry->total_credit,
            'difference'     => round((float)$entry->total_debit - (float)$entry->total_credit, 2),
            'is_balanced'    => (bool)$entry->is_balanced,
            'outlet_name'    => $entry->outlet?->name ?: 'Maroa – Cabang Utama (Pusat)',
            'creator_name'   => $entry->creator?->name ?: 'Sistem Akuntansi',
            'created_at'     => $actualCreatedAt?->toDateTimeString(),
            'time'           => $actualCreatedAt ? $actualCreatedAt->format('H:i') : null,
            'lines'          => $lines,
        ]);
    }

    /**
     * List Chronological Journal Entries (Jurnal Umum)
     */
    public function journals(Request $request)
    {
        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-d'));

        $user = $request->user();
        $businessId = $user ? $user->business_id : null;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        $query = JournalEntry::with(['lines.account', 'lines.outlet', 'outlet', 'creator'])
            ->whereBetween('entry_date', [$from, $to]);

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        if ($outletId) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                  ->orWhereHas('lines', fn($l) => $l->where('outlet_id', $outletId));
            });
        }

        if ($request->filled('entry_type') && $request->entry_type !== 'ALL') {
            $query->where('entry_type', $request->entry_type);
        }

        if ($request->filled('search')) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('entry_no', 'like', $search)
                  ->orWhere('description', 'like', $search)
                  ->orWhereHas('lines', function ($l) use ($search) {
                      $l->where('account_code', 'like', $search)
                        ->orWhere('memo', 'like', $search)
                        ->orWhereHas('account', fn($a) => $a->where('name', 'like', $search));
                  });
            });
        }

        // Summary metrics
        $summaryQuery = clone $query;
        $totalEntries = $summaryQuery->count();
        $totalDebit   = (float)$summaryQuery->sum('total_debit');
        $totalCredit  = (float)$summaryQuery->sum('total_credit');
        $balancedCount = (clone $summaryQuery)->where('is_balanced', true)->count();

        $limit = (int)$request->input('limit', 300);
        $direction = strtolower((string)$request->input('direction', $request->input('sort_order', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $entries = $query->orderBy('entry_no', $direction)
            ->orderBy('id', $direction)
            ->limit($limit)
            ->get();

        $trxIds = $entries->whereIn('reference_type', ['TRANSACTION', 'OPEN_BILL_SETTLEMENT', 'VOID_TRANSACTION'])->pluck('reference_id')->filter()->unique();
        $transactionsMap = !empty($trxIds) ? \App\Models\Transaction::whereIn('id', $trxIds)->get()->keyBy('id') : collect();

        $items = $entries->map(function ($entry) use ($transactionsMap) {
            $actualCreatedAt = $entry->created_at;
            if (in_array($entry->reference_type, ['TRANSACTION', 'OPEN_BILL_SETTLEMENT', 'VOID_TRANSACTION']) && !empty($entry->reference_id)) {
                $origTrx = $transactionsMap->get($entry->reference_id);
                if ($origTrx && $origTrx->created_at) {
                    $actualCreatedAt = $origTrx->created_at;
                }
            }
            $rawDate = $entry->entry_date;
            $dateOnly = $rawDate ? (is_string($rawDate) ? substr($rawDate, 0, 10) : $rawDate->format('Y-m-d')) : date('Y-m-d');

            return [
                'id'             => $entry->id,
                'entry_no'       => $entry->entry_no,
                'entry_date'     => $dateOnly,
                'entry_type'     => $entry->entry_type,
                'reference_type' => $entry->reference_type,
                'reference_id'   => $entry->reference_id,
                'description'    => $entry->description,
                'total_debit'    => (float)$entry->total_debit,
                'total_credit'   => (float)$entry->total_credit,
                'difference'     => round((float)$entry->total_debit - (float)$entry->total_credit, 2),
                'is_balanced'    => (bool)$entry->is_balanced,
                'outlet_id'      => $entry->outlet_id,
                'outlet_name'    => $entry->outlet?->name ?: 'Maroa – Cabang Utama (Pusat)',
                'creator_name'   => $entry->creator?->name ?: 'Sistem Akuntansi',
                'created_at'     => $actualCreatedAt?->toDateTimeString(),
                'time'           => $actualCreatedAt ? $actualCreatedAt->format('H:i') : null,
                'lines'          => $entry->lines->map(fn($l) => [
                    'id'           => $l->id,
                    'account_code' => $l->account_code,
                    'account_name' => $l->account?->name ?: "Akun {$l->account_code}",
                    'debit'        => (float)$l->debit,
                    'credit'       => (float)$l->credit,
                    'memo'         => $l->memo,
                    'outlet_name'  => $l->outlet?->name ?: '',
                ]),
            ];
        });

        return response()->json([
            'period' => [
                'from' => $from,
                'to'   => $to,
            ],
            'summary' => [
                'total_entries'  => $totalEntries,
                'total_debit'    => round($totalDebit, 2),
                'total_credit'   => round($totalCredit, 2),
                'balanced_count' => $balancedCount,
                'is_balanced'    => round($totalDebit - $totalCredit, 2) === 0.0,
            ],
            'entries' => $items,
        ]);
    }

    /**
     * Resynchronize & Re-post All Journals from Operational Data
     */
    public function syncAllJournals(Request $request)
    {
        $user = $request->user();
        if ($user && !$user->isOwnerBisnis() && !$user->isPlatformAdmin() && !$user->isOwnerWebsite()) {
            return response()->json(['message' => 'Hanya Owner Bisnis yang dapat melakukan sinkronisasi ulang jurnal.'], 403);
        }

        $businessId = $user ? $user->business_id : null;
        $stats = AccountingService::syncAllHistoricalData($businessId);

        return response()->json([
            'message' => 'Semua jurnal pembukuan berhasil disinkronkan dan dijurnalkan ulang dari seluruh riwayat transaksi operasional.',
            'stats'   => $stats,
        ]);
    }

    /**
     * List Abnormal / Anomalous Journal Entries for Audit & Monitoring
     */
    public function abnormalJournals(Request $request)
    {
        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-d'));

        $user = $request->user();
        $businessId = $user ? $user->business_id : null;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        // Fetch candidate entries in period (also include any unbalanced entries in history)
        $query = JournalEntry::with(['lines.account', 'lines.outlet', 'outlet', 'creator'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('entry_date', [$from, $to])
                  ->orWhere('is_balanced', false);
            });

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        if ($outletId) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                  ->orWhereHas('lines', fn($l) => $l->where('outlet_id', $outletId));
            });
        }

        $entries = $query->orderBy('entry_date', 'desc')->orderBy('id', 'desc')->get();

        // Total journals count for health score ratio
        $totalJournalsCount = JournalEntry::when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->whereBetween('entry_date', [$from, $to])
            ->count();

        // Also identify duplicate references across the business
        $duplicateRefKeys = JournalEntry::whereNotNull('reference_type')
            ->whereNotNull('reference_id')
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->select('reference_type', 'reference_id', DB::raw('count(*) as count'))
            ->groupBy('reference_type', 'reference_id')
            ->havingRaw('count(*) > 1')
            ->pluck('count', DB::raw("CONCAT(reference_type, ':', reference_id)"))
            ->toArray();

        // Get all active account codes for this business
        $validAccountCodes = Account::when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->pluck('code')
            ->map(fn($c) => strtoupper(trim((string)$c)))
            ->toArray();
        $validAccountCodeSet = array_flip($validAccountCodes);

        $abnormalItems = [];
        $unbalancedCount = 0;
        $zeroCount = 0;
        $negativeCount = 0;
        $missingAccountCount = 0;
        $duplicateCount = 0;
        $orphanCount = 0;
        $singleSidedCount = 0;
        $lineSumMismatchCount = 0;
        $theoreticalDeficitCount = 0;
        $totalDiscrepancyAmount = 0.0;

        foreach ($entries as $entry) {
            $anomalies = [];
            $lines = $entry->lines;
            $lineCount = $lines->count();

            $sumDebit = round((float)$lines->sum('debit'), 2);
            $sumCredit = round((float)$lines->sum('credit'), 2);
            $diffDebitCredit = round(abs((float)$entry->total_debit - (float)$entry->total_credit), 2);
            $diffLines = round(abs($sumDebit - $sumCredit), 2);

            // 1. Unbalanced Check
            if (!$entry->is_balanced || $diffDebitCredit > 0.01 || $diffLines > 0.01) {
                $maxDiff = max($diffDebitCredit, $diffLines);
                $anomalies[] = [
                    'code'          => 'UNBALANCED',
                    'severity'      => 'CRITICAL',
                    'label'         => 'Debit & Kredit Tidak Seimbang',
                    'description'   => "Terdapat selisih Rp " . number_format($maxDiff, 0, ',', '.') . " antara Total Debit (Rp " . number_format($sumDebit, 0, ',', '.') . ") dan Total Kredit (Rp " . number_format($sumCredit, 0, ',', '.') . ").",
                    'action_advice' => 'Periksa akun jurnal atau jalankan Sinkronisasi Ulang Jurnal untuk memulihkan keseimbangan buku.',
                ];
                $unbalancedCount++;
                $totalDiscrepancyAmount += $maxDiff;
            }

            // 2. Zero or Empty Check
            if ($lineCount === 0) {
                $anomalies[] = [
                    'code'          => 'ZERO_OR_EMPTY',
                    'severity'      => 'HIGH',
                    'label'         => 'Jurnal Kosong (Tanpa Baris Akun)',
                    'description'   => 'Header entri jurnal tercatat tetapi tidak memiliki satupun baris detail akun debit/kredit.',
                    'action_advice' => 'Hapus entri kosong atau jurnalkan ulang dari data operasional.',
                ];
                $zeroCount++;
            } elseif ($entry->total_debit <= 0 && $entry->total_credit <= 0 && $sumDebit <= 0 && $sumCredit <= 0) {
                $anomalies[] = [
                    'code'          => 'ZERO_OR_EMPTY',
                    'severity'      => 'HIGH',
                    'label'         => 'Nilai Transaksi Nol (Rp 0)',
                    'description'   => 'Entri jurnal memiliki total nominal Rp 0 tanpa mutasi keuangan riil.',
                    'action_advice' => 'Verifikasi apakah transaksi operasional bernilai Rp 0 atau terjadi kesalahan kalkulasi.',
                ];
                $zeroCount++;
            }

            // 3. Single Sided Entry Check
            if ($lineCount === 1) {
                $anomalies[] = [
                    'code'          => 'SINGLE_SIDED',
                    'severity'      => 'CRITICAL',
                    'label'         => 'Jurnal Tunggal (Single-Sided)',
                    'description'   => 'Jurnal hanya memiliki satu baris akun sehingga melanggar kaidah pembukuan berpasangan (double-entry).',
                    'action_advice' => 'Lengkapi akun pasangan (kontra) agar debit dan kredit seimbang.',
                ];
                $singleSidedCount++;
            }

            // 4. Line Sum vs Header Mismatch
            if (abs($sumDebit - (float)$entry->total_debit) > 0.05 || abs($sumCredit - (float)$entry->total_credit) > 0.05) {
                $anomalies[] = [
                    'code'          => 'LINE_SUM_MISMATCH',
                    'severity'      => 'HIGH',
                    'label'         => 'Selisih Rincian Baris vs Header',
                    'description'   => "Jumlah baris (D: Rp " . number_format($sumDebit, 0, ',', '.') . ", K: Rp " . number_format($sumCredit, 0, ',', '.') . ") berbeda dengan rekap header (D: Rp " . number_format($entry->total_debit, 0, ',', '.') . ", K: Rp " . number_format($entry->total_credit, 0, ',', '.') . ").",
                    'action_advice' => 'Kalkulasi ulang total header jurnal sesuai akumulasi baris transaksi.',
                ];
                $lineSumMismatchCount++;
            }

            // 5. Negative Values & Missing Account Check
            $hasNegative = false;
            $missingAccounts = [];
            $hasTheoreticalDeficit = false;

            foreach ($lines as $line) {
                if ((float)$line->debit < 0 || (float)$line->credit < 0) {
                    $hasNegative = true;
                }

                $rawCode = (string)$line->account_code;
                $cleanCode = strtoupper(trim(explode('.', $rawCode)[0]));
                if (!empty($cleanCode) && !isset($validAccountCodeSet[$cleanCode]) && !isset($validAccountCodeSet[$rawCode]) && !$line->account) {
                    $missingAccounts[] = $line->account_code;
                }

                if (
                    str_contains($rawCode, '5-51007') ||
                    str_contains($rawCode, '5-51008')
                ) {
                    $hasTheoreticalDeficit = true;
                }
            }

            if ($hasNegative) {
                $anomalies[] = [
                    'code'          => 'NEGATIVE_VALUES',
                    'severity'      => 'HIGH',
                    'label'         => 'Terdapat Nominal Negatif',
                    'description'   => 'Salah satu atau lebih baris akun memiliki nilai debit atau kredit di bawah 0 (negatif).',
                    'action_advice' => 'Gunakan sisi berlawanan (Kredit untuk membalik Debit) alih-alih nilai negatif.',
                ];
                $negativeCount++;
            }

            if (!empty($missingAccounts)) {
                $anomalies[] = [
                    'code'          => 'MISSING_ACCOUNT',
                    'severity'      => 'HIGH',
                    'label'         => 'Akun Tidak Terdaftar di Bagan Akun (COA)',
                    'description'   => 'Kode akun [' . implode(', ', array_unique($missingAccounts)) . '] tidak ditemukan pada Master Bagan Akun bisnis ini.',
                    'action_advice' => 'Tambahkan kode akun tersebut ke Bagan Akun (COA) atau sinkronkan ulang akun.',
                ];
                $missingAccountCount++;
            }

            // 6. Duplicate Reference Check
            $refKey = $entry->reference_type && $entry->reference_id ? "{$entry->reference_type}:{$entry->reference_id}" : null;
            if ($refKey && isset($duplicateRefKeys[$refKey]) && $duplicateRefKeys[$refKey] > 1) {
                $anomalies[] = [
                    'code'          => 'DUPLICATE_REFERENCE',
                    'severity'      => 'MEDIUM',
                    'label'         => 'Potensi Jurnal Ganda (Duplikasi)',
                    'description'   => "Ditemukan {$duplicateRefKeys[$refKey]} entri jurnal dengan referensi sumber yang sama ({$entry->reference_type} #{$entry->reference_id}).",
                    'action_advice' => 'Periksa apakah terjadi penjurnalan ganda dan hapus entri duplikat yang berlebih.',
                ];
                $duplicateCount++;
            }

            // 7. Theoretical Stock Deficit (Warning)
            if ($hasTheoreticalDeficit) {
                $anomalies[] = [
                    'code'          => 'THEORETICAL_DEFICIT',
                    'severity'      => 'WARNING',
                    'label'         => 'Beban Selisih Stok Minus (Nota Urgent)',
                    'description'   => 'Jurnal ini mencatat Beban Selisih HPP Teoritis akibat penjualan saat stok bahan di sistem berstatus defisit/minus.',
                    'action_advice' => 'Pastikan nota urgent telah diselesaikan (restock) agar persediaan dinormalisasi kembali.',
                ];
                $theoreticalDeficitCount++;
            }

            // 8. Orphan Reference Check
            if ($entry->reference_type && $entry->reference_id) {
                $isOrphan = false;
                switch ($entry->reference_type) {
                    case 'TRANSACTION':
                    case 'SALE':
                        if (!\App\Models\Transaction::where('id', $entry->reference_id)->exists()) {
                            $isOrphan = true;
                        }
                        break;
                    case 'STOCK_MOVEMENT':
                        if (!\App\Models\StockMovement::where('id', $entry->reference_id)->exists()) {
                            $isOrphan = true;
                        }
                        break;
                    case 'CASH_TRANSACTION':
                        if (!\App\Models\CashTransaction::where('id', $entry->reference_id)->exists()) {
                            $isOrphan = true;
                        }
                        break;
                    case 'OPERATING_EXPENSE':
                        if (!\App\Models\OperatingExpense::where('id', $entry->reference_id)->exists()) {
                            $isOrphan = true;
                        }
                        break;
                }
                if ($isOrphan) {
                    $anomalies[] = [
                        'code'          => 'ORPHAN_REFERENCE',
                        'severity'      => 'MEDIUM',
                        'label'         => 'Data Asal (Sumber) Terhapus',
                        'description'   => "Data sumber {$entry->reference_type} #{$entry->reference_id} sudah tidak ditemukan di database operasional.",
                        'action_advice' => 'Jurnal ini merupakan data sisa (orphan record). Lakukan verifikasi sebelum menghapus.',
                    ];
                    $orphanCount++;
                }
            }

            // If entry has ANY anomaly, add to result
            if (!empty($anomalies)) {
                $severities = array_column($anomalies, 'severity');
                $highestSeverity = in_array('CRITICAL', $severities) ? 'CRITICAL'
                    : (in_array('HIGH', $severities) ? 'HIGH'
                    : (in_array('MEDIUM', $severities) ? 'MEDIUM' : 'WARNING'));

                $abnormalItems[] = [
                    'id'               => $entry->id,
                    'entry_no'         => $entry->entry_no,
                    'entry_date'       => (string)$entry->entry_date,
                    'entry_type'       => $entry->entry_type,
                    'reference_type'   => $entry->reference_type,
                    'reference_id'     => $entry->reference_id,
                    'description'      => $entry->description,
                    'total_debit'      => (float)$entry->total_debit,
                    'total_credit'     => (float)$entry->total_credit,
                    'difference'       => round(abs((float)$entry->total_debit - (float)$entry->total_credit), 2),
                    'is_balanced'      => (bool)$entry->is_balanced,
                    'highest_severity' => $highestSeverity,
                    'anomalies'        => $anomalies,
                    'outlet_id'        => $entry->outlet_id,
                    'outlet_name'      => $entry->outlet?->name ?: 'Maroa – Cabang Utama (Pusat)',
                    'creator_name'     => $entry->creator?->name ?: 'Sistem Akuntansi',
                    'created_at'       => $entry->created_at?->toDateTimeString(),
                    'lines_count'      => $lineCount,
                    'lines'            => $lines->map(fn($l) => [
                        'id'           => $l->id,
                        'account_code' => $l->account_code,
                        'account_name' => $l->account?->name ?: "Akun {$l->account_code}",
                        'debit'        => (float)$l->debit,
                        'credit'       => (float)$l->credit,
                        'memo'         => $l->memo,
                        'outlet_name'  => $l->outlet?->name ?: '',
                    ]),
                ];
            }
        }

        // Apply filters on the detected abnormal items
        $filteredItems = $abnormalItems;

        if ($request->filled('anomaly_type') && $request->anomaly_type !== 'ALL') {
            $typeFilter = $request->anomaly_type;
            $filteredItems = array_values(array_filter($filteredItems, function ($item) use ($typeFilter) {
                return in_array($typeFilter, array_column($item['anomalies'], 'code'));
            }));
        }

        if ($request->filled('severity') && $request->severity !== 'ALL') {
            $sevFilter = $request->severity;
            $filteredItems = array_values(array_filter($filteredItems, function ($item) use ($sevFilter) {
                return $item['highest_severity'] === $sevFilter || in_array($sevFilter, array_column($item['anomalies'], 'severity'));
            }));
        }

        if ($request->filled('search')) {
            $search = strtolower(trim($request->search));
            $filteredItems = array_values(array_filter($filteredItems, function ($item) use ($search) {
                return str_contains(strtolower($item['entry_no']), $search) ||
                       str_contains(strtolower($item['description']), $search) ||
                       str_contains(strtolower($item['reference_type'] ?? ''), $search);
            }));
        }

        $totalAnomalies = count($abnormalItems);
        $healthScore = $totalJournalsCount > 0
            ? max(0, min(100, round((($totalJournalsCount - min($totalJournalsCount, $totalAnomalies)) / $totalJournalsCount) * 100, 1)))
            : 100.0;

        return response()->json([
            'period' => [
                'from' => $from,
                'to'   => $to,
            ],
            'summary' => [
                'total_journals_audited'   => $totalJournalsCount,
                'total_anomalies'          => $totalAnomalies,
                'health_score'             => $healthScore,
                'total_discrepancy_amount' => round($totalDiscrepancyAmount, 2),
                'unbalanced_count'         => $unbalancedCount,
                'zero_count'               => $zeroCount,
                'negative_count'           => $negativeCount,
                'missing_account_count'    => $missingAccountCount,
                'duplicate_count'          => $duplicateCount,
                'orphan_count'             => $orphanCount,
                'single_sided_count'       => $singleSidedCount,
                'line_sum_mismatch_count'  => $lineSumMismatchCount,
                'theoretical_deficit_count'=> $theoreticalDeficitCount,
            ],
            'entries' => $filteredItems,
        ]);
    }

    /**
     * Recalculate a single Journal Entry from its lines
     */
    public function recalculateEntry(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $user ? $user->business_id : null;

        $entry = JournalEntry::with('lines')->findOrFail($id);
        if ($businessId && (int)$entry->business_id !== (int)$businessId && !$user->isPlatformAdmin()) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $lines = $entry->lines;
        $totalDebit = round((float)$lines->sum('debit'), 2);
        $totalCredit = round((float)$lines->sum('credit'), 2);
        $diff = round(abs($totalDebit - $totalCredit), 2);
        $isBalanced = ($diff <= 0.01) && ($lines->count() > 0);

        $entry->update([
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced'  => $isBalanced,
            'updated_by'   => $user?->id,
        ]);

        return response()->json([
            'message' => "Jurnal {$entry->entry_no} berhasil dikalkulasi ulang.",
            'entry'   => [
                'id'           => $entry->id,
                'entry_no'     => $entry->entry_no,
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'is_balanced'  => $isBalanced,
            ]
        ]);
    }

    /**
     * Delete an unwanted / orphan / broken journal entry
     */
    public function destroyEntry(Request $request, $id)
    {
        $user = $request->user();
        if ($user && !$user->isOwnerBisnis() && !$user->isPlatformAdmin() && !$user->isOwnerWebsite()) {
            return response()->json(['message' => 'Hanya Owner Bisnis yang dapat menghapus entri jurnal abnormal.'], 403);
        }

        $entry = JournalEntry::findOrFail($id);
        if ($user && $user->business_id && (int)$entry->business_id !== (int)$user->business_id && !$user->isPlatformAdmin()) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $entry->lines()->delete();
        $entry->delete();

        return response()->json([
            'message' => "Entri jurnal #{$id} ({$entry->entry_no}) berhasil dihapus beserta barisnya.",
        ]);
    }
}

