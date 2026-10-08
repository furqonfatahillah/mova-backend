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

            $items[] = [
                'id'               => $l->id,
                'journal_entry_id' => $l->journal_entry_id,
                'entry_no'         => $je?->entry_no ?: "JE-{$l->journal_entry_id}",
                'date'             => $dateStr,
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

        return response()->json([
            'id'             => $entry->id,
            'entry_no'       => $entry->entry_no,
            'entry_date'     => (string)$entry->entry_date,
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
            'created_at'     => $entry->created_at?->toDateTimeString(),
            'lines'          => $lines,
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
}
