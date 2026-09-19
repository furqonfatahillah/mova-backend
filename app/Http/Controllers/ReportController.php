<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Transaction;
use App\Models\StockMovement;
use App\Models\Opname;
use App\Models\OperatingExpense;
use App\Models\WasteLog;
use App\Models\Outlet;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    protected ?\Illuminate\Database\Eloquent\Collection $cachedActiveIngredients = null;

    private function validatePeriod(Request $request): array
    {
        return $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);
    }

    /** Signed qty for a movement type (IN = +, OUT = -) */
    private function signedQtyByType(string $type, float $qty): float
    {
        static $outTypes = ['SALE_USAGE' => 1, 'WASTE' => 1, 'ADJUSTMENT_OUT' => 1, 'TRANSFER_OUT' => 1, 'PREP_USAGE' => 1];
        return isset($outTypes[$type]) ? -$qty : $qty;
    }

    /**
     * Pre-compute movement aggregates for a single ingredient's movements.
     * Returns all needed sums in a SINGLE pass — no repeated loops.
     */
    private function aggregateIngredientMovements(array $ingMovements, string $from, string $to): array
    {
        $balanceBefore = 0.0;
        $purchase = 0.0;
        $saleUsage = 0.0;
        $prepUsage = 0.0;
        $prepOutput = 0.0;
        $waste = 0.0;
        $transferIn = 0.0;
        $transferOut = 0.0;
        $adjustment = 0.0;
        $wasteRecords = [];

        foreach ($ingMovements as $m) {
            $date = $m->date;
            $type = $m->type;
            $qty  = (float) $m->qty;

            if ($date < $from) {
                // Pre-period: accumulate opening balance
                $balanceBefore += $this->signedQtyByType($type, $qty);
            } elseif ($date <= $to) {
                // In-period: aggregate by type
                switch ($type) {
                    case 'PURCHASE':       $purchase += $qty; break;
                    case 'SALE_USAGE':     $saleUsage += $qty; break;
                    case 'PREP_USAGE':     $prepUsage += $qty; break;
                    case 'PREP_OUTPUT':    $prepOutput += $qty; break;
                    case 'WASTE':
                        $waste += $qty;
                        $wasteRecords[] = $m;
                        break;
                    case 'TRANSFER_IN':    $transferIn += $qty; break;
                    case 'TRANSFER_OUT':   $transferOut += $qty; break;
                    case 'ADJUSTMENT_IN':  case 'ADJUSTMENT_PLUS':
                        $adjustment += $qty; break;
                    case 'ADJUSTMENT_OUT':
                        $adjustment -= $qty; break;
                    default:
                        // Other types in-period contribute to opening balance logic if needed
                        break;
                }
            }
        }

        return compact('balanceBefore', 'purchase', 'saleUsage', 'prepUsage', 'prepOutput', 'waste', 'transferIn', 'transferOut', 'adjustment', 'wasteRecords');
    }

    private function statusOf(float $absPct, float $tol): string
    {
        if ($absPct <= $tol)       return 'NORMAL';
        if ($absPct <= $tol * 2)   return 'WASPADA';
        return 'TIDAK WAJAR';
    }

    /**
     * Build full variance data for all ingredients in a period.
     */
    public function varianceIngredients(Request $request)
    {
        $p = $this->validatePeriod($request);
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ? (int)$request->outlet_id : ($user?->outlet_id));

        return response()->json($this->buildVarianceArray($p['from'], $p['to'], $outletId));
    }

    /**
     * Variance per menu (allocation by theoretical usage share).
    /**
     * Internal variance per menu calculation
     */
    private function calculateVarianceMenusData(string $from, string $to, ?int $outletId): array
    {
        $varData = $this->buildVarianceArray($from, $to, $outletId);
        $menus   = Menu::with(['recipes.items', 'outletMenus'])->get();
        $trxQuery = Transaction::whereBetween('date', [$from, $to])->where('status', 'PAID');
        if ($outletId) {
            $trxQuery->where('outlet_id', $outletId);
        }
        $transactions = $trxQuery->get();

        $perMenu = [];
        foreach ($menus as $m) {
            $perMenu[$m->id] = [
                'menu'           => $m,
                'variance_value' => 0,
                'weighted_pct_num' => 0,
                'weighted_pct_den' => 0,
                'qty_terjual'    => 0,
                'items'          => [],
            ];
        }

        // qty terjual per menu
        foreach ($transactions as $t) {
            if (isset($perMenu[$t->menu_id])) {
                $perMenu[$t->menu_id]['qty_terjual'] += $t->qty;
            }
        }

        foreach ($varData as $iv) {
            if ($iv['variance_value'] === null) continue;
            $ingId = $iv['ingredient']['id'];

            // Compute theoretical usage per menu for this ingredient
            $shares = [];
            foreach ($transactions as $t) {
                $recipe = $menus->find($t->menu_id)?->recipes
                    ->where('version', $t->recipe_version)->first();
                if (! $recipe) continue;
                $item = $recipe->items->firstWhere('ingredient_id', $ingId);
                if (! $item) continue;
                $shares[$t->menu_id] = ($shares[$t->menu_id] ?? 0) + $item->qty * $t->qty;
            }

            $total = array_sum($shares);
            if ($total <= 0) continue;

            foreach ($shares as $menuId => $usage) {
                if (! isset($perMenu[$menuId])) continue;
                $share = $usage / $total;
                $perMenu[$menuId]['variance_value'] += $iv['variance_value'] * $share;
                $perMenu[$menuId]['weighted_pct_num'] += abs($iv['variance_pct'] ?? 0) * $usage;
                $perMenu[$menuId]['weighted_pct_den'] += $usage;
                $perMenu[$menuId]['items'][] = [
                    'ingredient'   => $iv['ingredient'],
                    'usage'        => round($usage, 3),
                    'share'        => round($share, 4),
                    'alloc_value'  => round($iv['variance_value'] * $share, 0),
                    'variance_pct' => $iv['variance_pct'],
                    'status'       => $iv['status'],
                ];
            }
        }

        return array_values(array_map(function ($row) {
            $row['variance_value'] = round($row['variance_value'], 0);
            $row['weighted_pct'] = $row['weighted_pct_den'] > 0
                ? round($row['weighted_pct_num'] / $row['weighted_pct_den'], 2)
                : 0;
            unset($row['weighted_pct_num'], $row['weighted_pct_den']);
            return $row;
        }, $perMenu));
    }

    /**
     * Variance per menu (allocation by theoretical usage share).
     */
    public function varianceMenus(Request $request)
    {
        $p = $this->validatePeriod($request);
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ? (int)$request->outlet_id : ($user?->outlet_id));

        return response()->json($this->calculateVarianceMenusData($p['from'], $p['to'], $outletId));
    }

    /** Menu profitability */
    public function profitability(Request $request)
    {
        $p = $this->validatePeriod($request);
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ? (int)$request->outlet_id : ($user?->outlet_id));

        $menus        = Menu::with(['recipes.items.ingredient', 'outletMenus'])->get();
        $trxQuery     = Transaction::whereBetween('date', [$p['from'], $p['to']])->where('status', 'PAID');
        if ($outletId) {
            $trxQuery->where('outlet_id', $outletId);
        }
        $transactions = $trxQuery->get();

        $varMenuData  = $this->calculateVarianceMenusData($p['from'], $p['to'], $outletId);
        $varByMenu    = [];
        foreach ($varMenuData as $row) {
            $varByMenu[$row['menu']['id']] = $row['variance_value'];
        }

        $result = [];
        foreach ($menus as $m) {
            $hpp = (float)$m->calculateHpp();
            $qtyTerjual = $transactions->where('menu_id', $m->id)->sum('qty');
            $varValue   = $varByMenu[$m->id] ?? 0;
            $varPerPorsi = $qtyTerjual > 0 ? $varValue / $qtyTerjual : 0;
            $grossProfit  = $m->price - $hpp;
            $grossMargin  = $m->price > 0 ? ($grossProfit / $m->price) * 100 : 0;
            $adjustedHpp  = $hpp + $varPerPorsi;
            $adjustedProfit = $m->price - $adjustedHpp;
            $adjustedMargin = $m->price > 0 ? ($adjustedProfit / $m->price) * 100 : 0;

            $result[] = [
                'menu'              => $m,
                'hpp'               => round($hpp, 0),
                'gross_profit'      => round($grossProfit, 0),
                'gross_margin'      => round($grossMargin, 1),
                'qty_terjual'       => $qtyTerjual,
                'variance_value'    => round($varValue, 0),
                'variance_per_porsi'=> round($varPerPorsi, 0),
                'adjusted_hpp'      => round($adjustedHpp, 0),
                'adjusted_profit'   => round($adjustedProfit, 0),
                'adjusted_margin'   => round($adjustedMargin, 1),
            ];
        }

        return response()->json($result);
    }

    /** Dashboard summary */
    public function dashboard(Request $request)
    {
        $p = $this->validatePeriod($request);
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ? (int)$request->outlet_id : ($user?->outlet_id));
        $varData = $this->buildVarianceArray($p['from'], $p['to'], $outletId);

        $statusCounts      = ['NORMAL' => 0, 'WASPADA' => 0, 'TIDAK WAJAR' => 0];
        $totalVarValue     = 0;
        $totalVarLoss      = 0; // Unaccounted shrinkage loss
        $totalWasteValue   = 0; // Documented waste loss
        $wasteByReason     = [];

        foreach ($varData as $iv) {
            if ($iv['status']) $statusCounts[$iv['status']] = ($statusCounts[$iv['status']] ?? 0) + 1;
            if ($iv['variance_value'] !== null) {
                $totalVarValue += $iv['variance_value'];
                if ($iv['variance_value'] > 0) $totalVarLoss += $iv['variance_value'];
            }
            if (isset($iv['waste_value']) && $iv['waste_value'] > 0) {
                $totalWasteValue += $iv['waste_value'];
            }
            if (!empty($iv['waste_records'])) {
                foreach ($iv['waste_records'] as $wr) {
                    $reason = $wr['waste_reason'] ?: 'SPOILED';
                    $wasteByReason[$reason] = ($wasteByReason[$reason] ?? 0) + ($wr['value'] ?? 0);
                }
            }
        }

        $totalCombinedLoss = $totalVarLoss + $totalWasteValue;

        $topBahan = collect($varData)
            ->filter(fn($iv) => $iv['variance_value'] !== null)
            ->sortByDesc(fn($iv) => abs($iv['variance_value']))
            ->take(5)->values();

        $topWaste = collect($varData)
            ->filter(fn($iv) => ($iv['waste_value'] ?? 0) > 0)
            ->sortByDesc(fn($iv) => $iv['waste_value'])
            ->take(5)->values();

        return response()->json([
            'status_counts'        => $statusCounts,
            'total_variance_value' => round($totalVarValue, 0),
            'total_variance_loss'  => round($totalVarLoss, 0),
            'total_waste_value'    => round($totalWasteValue, 0),
            'total_combined_loss'  => round($totalCombinedLoss, 0),
            'top_bahan'            => $topBahan,
            'top_waste'            => $topWaste,
            'waste_by_reason'      => $wasteByReason,
            'period'               => $p,
            'outlet_id'            => $outletId,
        ]);
    }

    /**
     * Comprehensive Profit & Loss (P&L Income Statement) with optional Period Comparison (MoM / YoY)
     */
    public function profitAndLoss(Request $request)
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

        $current = $this->calculatePnlData($from, $to, $outletId);

        $isComparison = $request->boolean('compare') || $request->filled('compare_with') || $request->filled('compare_from');
        if (!$isComparison) {
            return response()->json($current);
        }

        $compareWith = $request->input('compare_with', 'previous_month');
        $compareFrom = null;
        $compareTo   = null;

        if ($compareWith === 'previous_month') {
            $compareFrom = date('Y-m-d', strtotime('-1 month', strtotime($from)));
            $compareTo   = date('Y-m-d', strtotime('-1 month', strtotime($to)));
        } elseif ($compareWith === 'previous_year') {
            $compareFrom = date('Y-m-d', strtotime('-1 year', strtotime($from)));
            $compareTo   = date('Y-m-d', strtotime('-1 year', strtotime($to)));
        } elseif ($compareWith === 'previous_period') {
            $days = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);
            $compareTo   = date('Y-m-d', strtotime('-1 day', strtotime($from)));
            $compareFrom = date('Y-m-d', strtotime("-{$days} days", strtotime($to)));
        } elseif ($compareWith === 'custom' || $request->filled('compare_from')) {
            $compareFrom = $request->input('compare_from', date('Y-m-d', strtotime('-1 month', strtotime($from))));
            $compareTo   = $request->input('compare_to', date('Y-m-d', strtotime('-1 month', strtotime($to))));
        } else {
            $compareFrom = date('Y-m-d', strtotime('-1 month', strtotime($from)));
            $compareTo   = date('Y-m-d', strtotime('-1 month', strtotime($to)));
        }

        $previous = $this->calculatePnlData($compareFrom, $compareTo, $outletId);

        // Detect Cost Anomalies (e.g. OPEX surge > 20% or COGS ratio increase)
        $anomalies = [];
        foreach ($current['opex']['breakdown'] as $cItem) {
            $catKey = $cItem['category'];
            $prevItem = collect($previous['opex']['breakdown'])->firstWhere('category', $catKey);
            $prevAmt = $prevItem ? (float)$prevItem['total'] : 0.0;
            $currAmt = (float)$cItem['total'];
            
            if ($prevAmt > 0 && $currAmt > $prevAmt) {
                $surgePct = round((($currAmt - $prevAmt) / $prevAmt) * 100, 1);
                if ($surgePct >= 20.0 && ($currAmt - $prevAmt) >= 50000) {
                    $anomalies[] = [
                        'type'        => 'OPEX_SURGE',
                        'category'    => $catKey,
                        'name'        => $cItem['label'],
                        'current'     => $currAmt,
                        'previous'    => $prevAmt,
                        'diff'        => round($currAmt - $prevAmt, 2),
                        'surge_pct'   => $surgePct,
                        'severity'    => $surgePct >= 50 ? 'HIGH' : 'MEDIUM',
                        'message'     => "Beban {$cItem['label']} melonjak +{$surgePct}% (+Rp " . number_format($currAmt - $prevAmt, 0, ',', '.') . ") dibanding periode pembanding.",
                    ];
                }
            }
        }

        $currCogsRatio = (float)$current['cogs']['cogs_ratio_pct'];
        $prevCogsRatio = (float)$previous['cogs']['cogs_ratio_pct'];
        if ($currCogsRatio > $prevCogsRatio && ($currCogsRatio - $prevCogsRatio) >= 3.0) {
            $diffRatio = round($currCogsRatio - $prevCogsRatio, 1);
            $anomalies[] = [
                'type'        => 'COGS_RATIO_SURGE',
                'name'        => 'Rasio HPP (Food Cost %)',
                'current'     => $currCogsRatio,
                'previous'    => $prevCogsRatio,
                'diff'        => $diffRatio,
                'surge_pct'   => $diffRatio,
                'severity'    => $diffRatio >= 5.0 ? 'HIGH' : 'MEDIUM',
                'message'     => "Rasio HPP membengkak sebesar +{$diffRatio}% (dari {$prevCogsRatio}% menjadi {$currCogsRatio}% dari omset). Periksa porsi resep atau selisih opname fisik.",
            ];
        }

        $currWasteRatio = (float)$current['waste']['waste_ratio_pct'];
        $prevWasteRatio = (float)$previous['waste']['waste_ratio_pct'];
        if ($currWasteRatio > 2.0 && $currWasteRatio > $prevWasteRatio) {
            $diffWaste = round($currWasteRatio - $prevWasteRatio, 1);
            $anomalies[] = [
                'type'        => 'WASTE_SURGE',
                'name'        => 'Kerugian Waste',
                'current'     => $currWasteRatio,
                'previous'    => $prevWasteRatio,
                'diff'        => $diffWaste,
                'surge_pct'   => $diffWaste,
                'severity'    => 'HIGH',
                'message'     => "Kerugian bahan terbuang (waste) mencapai {$currWasteRatio}% dari omset (melebihi batas aman 2.0%).",
            ];
        }

        $delta = [
            'revenue' => [
                'net_sales'         => $this->calculateDelta($current['revenue']['net_sales'], $previous['revenue']['net_sales'], true),
                'gross_sales'       => $this->calculateDelta($current['revenue']['gross_sales'], $previous['revenue']['gross_sales'], true),
                'total_discount'    => $this->calculateDelta($current['revenue']['total_discount'], $previous['revenue']['total_discount'], false),
                'transaction_count' => $this->calculateDelta($current['revenue']['transaction_count'], $previous['revenue']['transaction_count'], true),
                'avg_order_value'   => $this->calculateDelta($current['revenue']['avg_order_value'], $previous['revenue']['avg_order_value'], true),
            ],
            'cogs' => [
                'total_cogs'       => $this->calculateDelta($current['cogs']['total_cogs'], $previous['cogs']['total_cogs'], false),
                'cogs_recipes'     => $this->calculateDelta($current['cogs']['cogs_recipes'], $previous['cogs']['cogs_recipes'], false),
                'cogs_variance'    => $this->calculateDelta($current['cogs']['cogs_variance'], $previous['cogs']['cogs_variance'], false),
                'cogs_ratio_pct'   => $this->calculateDelta($current['cogs']['cogs_ratio_pct'], $previous['cogs']['cogs_ratio_pct'], false),
                'gross_profit'     => $this->calculateDelta($current['cogs']['gross_profit'], $previous['cogs']['gross_profit'], true),
                'gross_margin_pct' => $this->calculateDelta($current['cogs']['gross_margin_pct'], $previous['cogs']['gross_margin_pct'], true),
            ],
            'waste' => [
                'total_waste_loss' => $this->calculateDelta($current['waste']['total_waste_loss'], $previous['waste']['total_waste_loss'], false),
                'waste_ratio_pct'  => $this->calculateDelta($current['waste']['waste_ratio_pct'], $previous['waste']['waste_ratio_pct'], false),
            ],
            'opex' => [
                'total_opex'     => $this->calculateDelta($current['opex']['total_opex'], $previous['opex']['total_opex'], false),
                'opex_ratio_pct' => $this->calculateDelta($current['opex']['opex_ratio_pct'], $previous['opex']['opex_ratio_pct'], false),
            ],
            'bottom_line' => [
                'net_profit'     => $this->calculateDelta($current['bottom_line']['net_profit'], $previous['bottom_line']['net_profit'], true),
                'net_margin_pct' => $this->calculateDelta($current['bottom_line']['net_margin_pct'], $previous['bottom_line']['net_margin_pct'], true),
            ],
            'anomalies' => $anomalies,
        ];

        return response()->json(array_merge($current, [
            'is_comparison'  => true,
            'compare_period' => [
                'from' => $compareFrom,
                'to'   => $compareTo,
                'type' => $compareWith,
            ],
            'previous' => $previous,
            'delta'    => $delta,
        ]));
    }

    /**
     * Side-by-Side Outlet Benchmark Comparison
     */
    public function outletBenchmark(Request $request)
    {
        $p = $this->validatePeriod($request);
        $from = $p['from'];
        $to   = $p['to'];

        $user = $request->user();
        $outletsQuery = Outlet::where('active', true);
        if ($user && $user->business_id) {
            $outletsQuery->where('business_id', $user->business_id);
        }
        $outlets = $outletsQuery->orderBy('is_main', 'desc')->orderBy('name', 'asc')->get();

        $outletResults = [];
        $groupGrossSales = 0.0;
        $groupTotalDiscount = 0.0;
        $groupNetSales = 0.0;
        $groupTotalCogs = 0.0;
        $groupTotalWaste = 0.0;
        $groupTotalOpex = 0.0;
        $groupSalaryOpex = 0.0;
        $groupNetProfit = 0.0;
        $groupTransactionCount = 0;

        foreach ($outlets as $outlet) {
            $pnl = $this->calculatePnlData($from, $to, $outlet->id);

            $netSales      = (float) $pnl['revenue']['net_sales'];
            $grossSales    = (float) $pnl['revenue']['gross_sales'];
            $totalDiscount = (float) $pnl['revenue']['total_discount'];
            $totalCogs     = (float) $pnl['cogs']['total_cogs'];
            $cogsRatio     = (float) $pnl['cogs']['cogs_ratio_pct'];
            $wasteLoss     = (float) $pnl['waste']['total_waste_loss'];
            $wasteRatio    = (float) $pnl['waste']['waste_ratio_pct'];
            $totalOpex     = (float) $pnl['opex']['total_opex'];
            $opexRatio     = (float) $pnl['opex']['opex_ratio_pct'];
            $netProfit     = (float) $pnl['bottom_line']['net_profit'];
            $netMargin     = (float) $pnl['bottom_line']['net_margin_pct'];
            $txCount       = (int)   $pnl['revenue']['transaction_count'];
            $aov           = (float) $pnl['revenue']['avg_order_value'];

            // Extract salary / payroll expenses from OPEX breakdown
            $salaryAmount = 0.0;
            foreach ($pnl['opex']['breakdown'] as $b) {
                $cat = strtoupper($b['category'] ?? '');
                if (str_contains($cat, 'GAJI') || str_contains($cat, 'SALARY') || str_contains($cat, 'UPAH') || str_contains($cat, 'KARYAWAN') || str_contains($cat, 'SDM')) {
                    $salaryAmount += (float) ($b['total'] ?? $b['amount'] ?? 0);
                }
            }
            $salaryRatio = $netSales > 0 ? round(($salaryAmount / $netSales) * 100, 1) : 0.0;

            // Accumulate Group Totals
            $groupGrossSales       += $grossSales;
            $groupTotalDiscount    += $totalDiscount;
            $groupNetSales         += $netSales;
            $groupTotalCogs        += $totalCogs;
            $groupTotalWaste       += $wasteLoss;
            $groupTotalOpex        += $totalOpex;
            $groupSalaryOpex       += $salaryAmount;
            $groupNetProfit        += $netProfit;
            $groupTransactionCount += $txCount;

            $outletResults[] = [
                'outlet' => [
                    'id'      => $outlet->id,
                    'code'    => $outlet->code,
                    'name'    => $outlet->name,
                    'is_main' => (bool) $outlet->is_main,
                    'address' => $outlet->address,
                ],
                'revenue' => [
                    'gross_sales'       => $grossSales,
                    'total_discount'    => $totalDiscount,
                    'net_sales'         => $netSales,
                    'transaction_count' => $txCount,
                    'avg_order_value'   => $aov,
                ],
                'cogs' => [
                    'total_cogs'       => $totalCogs,
                    'cogs_ratio_pct'   => $cogsRatio,
                    'gross_profit'     => (float) $pnl['cogs']['gross_profit'],
                    'gross_margin_pct' => (float) $pnl['cogs']['gross_margin_pct'],
                ],
                'waste' => [
                    'total_waste_loss' => $wasteLoss,
                    'waste_ratio_pct'  => $wasteRatio,
                    'total_records'    => $pnl['waste']['total_records'],
                ],
                'opex' => [
                    'total_opex'       => $totalOpex,
                    'opex_ratio_pct'   => $opexRatio,
                    'salary_amount'    => $salaryAmount,
                    'salary_ratio_pct' => $salaryRatio,
                    'total_records'    => $pnl['opex']['total_records'],
                ],
                'bottom_line' => [
                    'net_profit'     => $netProfit,
                    'net_margin_pct' => $netMargin,
                    'health_status'  => $pnl['bottom_line']['health_status'],
                    'health_label'   => $pnl['bottom_line']['health_label'],
                    'health_color'   => $pnl['bottom_line']['health_color'],
                ],
                'tags' => [],
            ];
        }

        // Calculate shares, tags, and benchmarks
        $bestMarginVal  = -999999;
        $bestMarginIdx  = null;
        $topRevenueVal  = -1;
        $topRevenueIdx  = null;
        $lowestWasteVal = 999999;
        $lowestWasteIdx = null;

        foreach ($outletResults as $idx => &$item) {
            $ns = $item['revenue']['net_sales'];
            $item['revenue']['share_pct'] = $groupNetSales > 0 ? round(($ns / $groupNetSales) * 100, 1) : 0.0;
            $item['bottom_line']['profit_share_pct'] = $groupNetProfit > 0 ? round(($item['bottom_line']['net_profit'] / $groupNetProfit) * 100, 1) : 0.0;

            if ($ns > 0) {
                if ($item['bottom_line']['net_margin_pct'] > $bestMarginVal) {
                    $bestMarginVal = $item['bottom_line']['net_margin_pct'];
                    $bestMarginIdx = $idx;
                }
                if ($ns > $topRevenueVal) {
                    $topRevenueVal = $ns;
                    $topRevenueIdx = $idx;
                }
                if ($item['waste']['waste_ratio_pct'] < $lowestWasteVal) {
                    $lowestWasteVal = $item['waste']['waste_ratio_pct'];
                    $lowestWasteIdx = $idx;
                }
            }

            // Warning flags
            $flags = [];
            if ($item['bottom_line']['net_profit'] < 0) {
                $flags[] = [
                    'type'  => 'DEFICIT',
                    'label' => 'Defisit Operasional',
                    'color' => '#EF4444',
                ];
            }
            if ($item['waste']['waste_ratio_pct'] > 2.0) {
                $flags[] = [
                    'type'  => 'HIGH_WASTE',
                    'label' => 'Waste Tinggi (' . $item['waste']['waste_ratio_pct'] . '%)',
                    'color' => '#F59E0B',
                ];
            }
            if ($item['cogs']['cogs_ratio_pct'] > 35.0) {
                $flags[] = [
                    'type'  => 'HIGH_COGS',
                    'label' => 'HPP Tinggi (' . $item['cogs']['cogs_ratio_pct'] . '%)',
                    'color' => '#EC4899',
                ];
            }
            if ($item['opex']['salary_ratio_pct'] > 25.0) {
                $flags[] = [
                    'type'  => 'HIGH_SALARY',
                    'label' => 'Rasio Gaji Tinggi (' . $item['opex']['salary_ratio_pct'] . '%)',
                    'color' => '#8B5CF6',
                ];
            }
            $item['warnings'] = $flags;
        }
        unset($item);

        if ($bestMarginIdx !== null) {
            $outletResults[$bestMarginIdx]['tags'][] = [
                'type'  => 'CHAMPION_MARGIN',
                'label' => '🏆 Margin Champion',
                'color' => '#10B981',
            ];
        }
        if ($topRevenueIdx !== null) {
            $outletResults[$topRevenueIdx]['tags'][] = [
                'type'  => 'REVENUE_LEADER',
                'label' => '⭐ Omset Terbesar',
                'color' => '#3B82F6',
            ];
        }
        if ($lowestWasteIdx !== null && $lowestWasteVal <= 2.0) {
            $outletResults[$lowestWasteIdx]['tags'][] = [
                'type'  => 'LOWEST_WASTE',
                'label' => '🌿 Efisiensi Bahan',
                'color' => '#06B6D4',
            ];
        }

        // Consolidated group summary
        $groupCogsRatio   = $groupNetSales > 0 ? round(($groupTotalCogs / $groupNetSales) * 100, 1) : 0.0;
        $groupWasteRatio  = $groupNetSales > 0 ? round(($groupTotalWaste / $groupNetSales) * 100, 1) : 0.0;
        $groupOpexRatio   = $groupNetSales > 0 ? round(($groupTotalOpex / $groupNetSales) * 100, 1) : 0.0;
        $groupSalaryRatio = $groupNetSales > 0 ? round(($groupSalaryOpex / $groupNetSales) * 100, 1) : 0.0;
        $groupNetMargin   = $groupNetSales > 0 ? round(($groupNetProfit / $groupNetSales) * 100, 1) : 0.0;
        $groupAov         = $groupTransactionCount > 0 ? round($groupNetSales / $groupTransactionCount, 0) : 0.0;

        $consolidated = [
            'total_outlets'     => count($outlets),
            'gross_sales'       => round($groupGrossSales, 2),
            'total_discount'    => round($groupTotalDiscount, 2),
            'net_sales'         => round($groupNetSales, 2),
            'total_cogs'        => round($groupTotalCogs, 2),
            'cogs_ratio_pct'    => $groupCogsRatio,
            'total_waste_loss'  => round($groupTotalWaste, 2),
            'waste_ratio_pct'   => $groupWasteRatio,
            'total_opex'        => round($groupTotalOpex, 2),
            'opex_ratio_pct'    => $groupOpexRatio,
            'salary_amount'     => round($groupSalaryOpex, 2),
            'salary_ratio_pct'  => $groupSalaryRatio,
            'net_profit'        => round($groupNetProfit, 2),
            'net_margin_pct'    => $groupNetMargin,
            'transaction_count' => $groupTransactionCount,
            'avg_order_value'   => $groupAov,
        ];

        // Highlights for executive banner
        $highlights = [
            'best_margin' => $bestMarginIdx !== null ? [
                'outlet_name'    => $outletResults[$bestMarginIdx]['outlet']['name'],
                'net_margin_pct' => $outletResults[$bestMarginIdx]['bottom_line']['net_margin_pct'],
                'net_profit'     => $outletResults[$bestMarginIdx]['bottom_line']['net_profit'],
            ] : null,
            'top_revenue' => $topRevenueIdx !== null ? [
                'outlet_name' => $outletResults[$topRevenueIdx]['outlet']['name'],
                'net_sales'   => $outletResults[$topRevenueIdx]['revenue']['net_sales'],
                'share_pct'   => $outletResults[$topRevenueIdx]['revenue']['share_pct'],
            ] : null,
            'lowest_waste' => $lowestWasteIdx !== null ? [
                'outlet_name'     => $outletResults[$lowestWasteIdx]['outlet']['name'],
                'waste_ratio_pct' => $outletResults[$lowestWasteIdx]['waste']['waste_ratio_pct'],
                'waste_loss'      => $outletResults[$lowestWasteIdx]['waste']['total_waste_loss'],
            ] : null,
            'attention_needed' => null,
        ];

        // Find which outlet needs most attention (lowest margin or biggest deficit)
        $worstMarginVal = 999999;
        $worstIdx = null;
        foreach ($outletResults as $idx => $item) {
            if ($item['bottom_line']['net_margin_pct'] < $worstMarginVal) {
                $worstMarginVal = $item['bottom_line']['net_margin_pct'];
                $worstIdx = $idx;
            }
        }
        if ($worstIdx !== null && ($worstMarginVal < 10 || count($outletResults[$worstIdx]['warnings']) > 0)) {
            $highlights['attention_needed'] = [
                'outlet_name'    => $outletResults[$worstIdx]['outlet']['name'],
                'net_margin_pct' => $outletResults[$worstIdx]['bottom_line']['net_margin_pct'],
                'warnings'       => $outletResults[$worstIdx]['warnings'],
            ];
        }

        return response()->json([
            'period'       => [
                'from' => $from,
                'to'   => $to,
            ],
            'outlets'      => $outletResults,
            'consolidated' => $consolidated,
            'highlights'   => $highlights,
        ]);
    }

    /**
     * Compute Delta between Current and Previous Period
     */
    private function calculateDelta(float $current, float $previous, bool $higherIsGood = true): array
    {
        $diffNominal = round($current - $previous, 2);
        $diffPct = 0.0;
        if (abs($previous) > 0.001) {
            $diffPct = round((($current - $previous) / abs($previous)) * 100, 1);
        } elseif (abs($current) > 0.001) {
            $diffPct = $current > 0 ? 100.0 : -100.0;
        }

        $trend = 'FLAT';
        if ($diffNominal > 0) {
            $trend = 'UP';
        } elseif ($diffNominal < 0) {
            $trend = 'DOWN';
        }

        $sentiment = 'NEUTRAL';
        if ($trend === 'UP') {
            $sentiment = $higherIsGood ? 'GOOD' : 'BAD';
        } elseif ($trend === 'DOWN') {
            $sentiment = $higherIsGood ? 'BAD' : 'GOOD';
        }

        return [
            'current'      => $current,
            'previous'     => $previous,
            'diff_nominal' => $diffNominal,
            'diff_pct'     => $diffPct,
            'trend'        => $trend,
            'sentiment'    => $sentiment,
        ];
    }

    /**
     * Internal P&L Calculation for a single period
     */
    private function calculatePnlData(string $from, string $to, ?int $outletId = null): array
    {
        // 1. REVENUE (PENDAPATAN USAHA)
        $trxQuery = Transaction::with(['menu.recipes.items'])
            ->where('status', 'PAID')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $trxQuery->where('outlet_id', $outletId);
        }
        $transactions = $trxQuery->get();

        $grossSales = 0.0;
        $totalDiscount = 0.0;
        $netSales = 0.0;
        $paymentMethodsMap = [];
        $orderNumbers = [];
        $itemCount = 0;

        foreach ($transactions as $t) {
            $subtotal = (float)($t->subtotal > 0 ? $t->subtotal : ($t->total_price + ($t->discount_amount ?? 0)));
            $discount = (float)($t->discount_amount ?? 0);
            $net = (float)$t->total_price;

            $grossSales += $subtotal;
            $totalDiscount += $discount;
            $netSales += $net;
            $itemCount += (int)$t->qty;

            if ($t->order_number) {
                $orderNumbers[$t->order_number] = true;
            }

            $method = $t->payment_method ?: 'CASH';
            $paymentMethodsMap[$method] = ($paymentMethodsMap[$method] ?? 0.0) + $net;
        }

        $transactionCount = count($orderNumbers);
        $avgOrderValue = $transactionCount > 0 ? round($netSales / $transactionCount, 0) : 0;

        $methodLabels = [
            'CASH'       => 'Tunai (Cash)',
            'QRIS'       => 'QRIS (GoPay/OVO/ShopeePay)',
            'TRANSFER'   => 'Transfer Bank',
            'DEBIT'      => 'Kartu Debit / EDC',
            'PETTY_CASH' => 'Kas Kecil',
        ];
        $paymentBreakdown = [];
        foreach ($paymentMethodsMap as $mKey => $mTotal) {
            $paymentBreakdown[] = [
                'method'     => $mKey,
                'label'      => $methodLabels[$mKey] ?? $mKey,
                'total'      => round($mTotal, 2),
                'percentage' => $netSales > 0 ? round(($mTotal / $netSales) * 100, 1) : 0,
            ];
        }
        usort($paymentBreakdown, fn($a, $b) => $b['total'] <=> $a['total']);

        // 2. COGS (HPP RIIL: RESEP + SUSUT OPNAME)
        $varianceData = $this->buildVarianceArray($from, $to, $outletId);
        
        $cogsRecipes = 0.0;
        $cogsVariance = 0.0;
        $topIngredientsUsage = [];

        foreach ($varianceData as $row) {
            $ing = $row['ingredient'];
            $hargaPerPakai = (float)$ing->harga / max((float)$ing->konversi, 1);
            $rowRecipeCost = (float)$row['pemakaian_teoritis'] * $hargaPerPakai;
            $cogsRecipes += $rowRecipeCost;

            if ($row['unaccounted_value'] !== null) {
                $cogsVariance += (float)$row['unaccounted_value'];
            }

            if ($rowRecipeCost > 0) {
                $topIngredientsUsage[] = [
                    'ingredient_id'   => $ing->id,
                    'ingredient_name' => $ing->name,
                    'unit'            => $ing->unit_pakai,
                    'qty'             => (float)$row['pemakaian_teoritis'],
                    'cost'            => round($rowRecipeCost, 0),
                ];
            }
        }
        usort($topIngredientsUsage, fn($a, $b) => $b['cost'] <=> $a['cost']);
        $topIngredientsUsage = array_slice($topIngredientsUsage, 0, 8);

        // Tambahkan HPP barang direct retail (non-resep)
        $cogsDirectItems = 0.0;
        foreach ($transactions as $t) {
            $m = $t->menu;
            if ($m && ($m->item_type === 'DIRECT' || (!$m->activeRecipe($t->date) && $m->cost_price > 0))) {
                $cogsDirectItems += (float)($m->cost_price * $t->qty);
            }
        }

        $totalCogs = round($cogsRecipes + $cogsVariance + $cogsDirectItems, 2);
        $cogsRatioPct = $netSales > 0 ? round(($totalCogs / $netSales) * 100, 1) : 0;

        // Gross Profit (Laba Kotor)
        $grossProfit = round($netSales - $totalCogs, 2);
        $grossMarginPct = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 1) : 0;

        // 3. KERUGIAN WASTE & SPOILAGE
        $wasteLogQuery = WasteLog::whereBetween('date', [$from, $to]);
        if ($outletId) {
            $wasteLogQuery->where('outlet_id', $outletId);
        }
        $wasteLogs = $wasteLogQuery->get();

        $wasteLossSum = (float)$wasteLogs->sum('loss_cost');
        if ($wasteLossSum <= 0) {
            foreach ($varianceData as $row) {
                $wasteLossSum += (float)($row['waste_value'] ?? 0);
            }
        }
        $totalWasteLoss = round($wasteLossSum, 2);
        $wasteRatioPct = $netSales > 0 ? round(($totalWasteLoss / $netSales) * 100, 1) : 0;

        $wasteReasonConfig = WasteLog::reasonCategories();
        $wasteBreakdown = [];
        foreach ($wasteReasonConfig as $wKey => $wLabel) {
            $wItems = $wasteLogs->where('reason_category', $wKey);
            $wCost = (float)$wItems->sum('loss_cost');
            if ($wCost > 0) {
                $wasteBreakdown[] = [
                    'category'   => $wKey,
                    'label'      => $wLabel,
                    'total'      => $wCost,
                    'count'      => $wItems->count(),
                    'percentage' => $totalWasteLoss > 0 ? round(($wCost / $totalWasteLoss) * 100, 1) : 0,
                ];
            }
        }
        usort($wasteBreakdown, fn($a, $b) => $b['total'] <=> $a['total']);

        $operatingProfitAfterWaste = round($grossProfit - $totalWasteLoss, 2);

        // 4. BEBAN OPERASIONAL TOKO (OPEX)
        $opexQuery = OperatingExpense::whereBetween('date', [$from, $to]);
        if ($outletId) {
            $opexQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $expenses = $opexQuery->get();
        $totalOpex = round((float)$expenses->sum('amount'), 2);
        $opexRatioPct = $netSales > 0 ? round(($totalOpex / $netSales) * 100, 1) : 0;

        $expenseCategoriesConfig = OperatingExpense::categories();
        $opexBreakdown = [];
        foreach ($expenseCategoriesConfig as $cKey => $cLabel) {
            $cItems = $expenses->where('category', $cKey);
            $cAmount = (float)$cItems->sum('amount');
            if ($cAmount > 0) {
                $opexBreakdown[] = [
                    'category'   => $cKey,
                    'label'      => $cLabel,
                    'total'      => $cAmount,
                    'count'      => $cItems->count(),
                    'percentage' => $totalOpex > 0 ? round(($cAmount / $totalOpex) * 100, 1) : 0,
                ];
            }
        }
        usort($opexBreakdown, fn($a, $b) => $b['total'] <=> $a['total']);

        // 5. LABA BERSIH USAHA (NET OPERATING PROFIT)
        $netProfit = round($operatingProfitAfterWaste - $totalOpex, 2);
        $netMarginPct = $netSales > 0 ? round(($netProfit / $netSales) * 100, 1) : 0;

        // Health Status Indicator
        $healthStatus = 'PRIME';
        $healthLabel  = 'Sangat Sehat (Prima)';
        $healthColor  = '#10B981'; // Emerald
        if ($netMarginPct < 0) {
            $healthStatus = 'DEFICIT';
            $healthLabel  = 'Defisit / Rugi Operasional';
            $healthColor  = '#EF4444'; // Red
        } elseif ($netMarginPct < 10) {
            $healthStatus = 'WARNING';
            $healthLabel  = 'Waspada (Margin Tipis)';
            $healthColor  = '#F59E0B'; // Amber
        } elseif ($netMarginPct < 20) {
            $healthStatus = 'HEALTHY';
            $healthLabel  = 'Sehat (Standar F&B Ideal)';
            $healthColor  = '#06B6D4'; // Cyan
        }

        // 6. WATERFALL STEP DATA
        $waterfall = [
            [
                'step'        => 'gross_sales',
                'name'        => 'Penjualan Kotor (Gross)',
                'amount'      => round($grossSales, 2),
                'type'        => 'base',
                'pct_of_net'  => $netSales > 0 ? round(($grossSales / $netSales) * 100, 1) : 100,
            ],
            [
                'step'        => 'discount',
                'name'        => 'Diskon & Promosi Kasir',
                'amount'      => -round($totalDiscount, 2),
                'type'        => 'subtraction',
                'pct_of_net'  => $netSales > 0 ? round(($totalDiscount / $netSales) * 100, 1) : 0,
            ],
            [
                'step'        => 'net_sales',
                'name'        => 'Omset Bersih (Net Revenue)',
                'amount'      => round($netSales, 2),
                'type'        => 'subtotal',
                'pct_of_net'  => 100.0,
            ],
            [
                'step'        => 'cogs_recipes',
                'name'        => 'HPP Teoretis Resep',
                'amount'      => -round($cogsRecipes, 2),
                'type'        => 'subtraction',
                'pct_of_net'  => $netSales > 0 ? round(($cogsRecipes / $netSales) * 100, 1) : 0,
            ],
            [
                'step'        => 'cogs_variance',
                'name'        => 'Selisih Opname / Susut Stok',
                'amount'      => -round($cogsVariance, 2),
                'type'        => 'subtraction',
                'pct_of_net'  => $netSales > 0 ? round(($cogsVariance / $netSales) * 100, 1) : 0,
            ],
            [
                'step'        => 'gross_profit',
                'name'        => 'Laba Kotor (Gross Profit)',
                'amount'      => round($grossProfit, 2),
                'type'        => 'subtotal',
                'pct_of_net'  => $grossMarginPct,
            ],
            [
                'step'        => 'waste_loss',
                'name'        => 'Kerugian Bahan Terbuang (Waste)',
                'amount'      => -round($totalWasteLoss, 2),
                'type'        => 'subtraction',
                'pct_of_net'  => $wasteRatioPct,
            ],
            [
                'step'        => 'opex',
                'name'        => 'Beban Operasional Toko (OPEX)',
                'amount'      => -round($totalOpex, 2),
                'type'        => 'subtraction',
                'pct_of_net'  => $opexRatioPct,
            ],
            [
                'step'        => 'net_profit',
                'name'        => 'Laba Bersih Usaha (Net Operating Profit)',
                'amount'      => round($netProfit, 2),
                'type'        => 'result',
                'pct_of_net'  => $netMarginPct,
            ],
        ];

        return [
            'period' => [
                'from'      => $from,
                'to'        => $to,
                'outlet_id' => $outletId,
            ],
            'revenue' => [
                'gross_sales'        => round($grossSales, 2),
                'total_discount'     => round($totalDiscount, 2),
                'net_sales'          => round($netSales, 2),
                'transaction_count'  => $transactionCount,
                'item_sold_count'    => $itemCount,
                'avg_order_value'    => $avgOrderValue,
                'payment_breakdown'  => $paymentBreakdown,
            ],
            'cogs' => [
                'cogs_recipes'          => round($cogsRecipes, 2),
                'cogs_variance'         => round($cogsVariance, 2),
                'total_cogs'            => round($totalCogs, 2),
                'cogs_ratio_pct'        => $cogsRatioPct,
                'gross_profit'          => round($grossProfit, 2),
                'gross_margin_pct'      => $grossMarginPct,
                'top_ingredients_usage' => $topIngredientsUsage,
            ],
            'waste' => [
                'total_waste_loss'             => round($totalWasteLoss, 2),
                'waste_ratio_pct'              => $wasteRatioPct,
                'operating_profit_after_waste' => round($operatingProfitAfterWaste, 2),
                'breakdown'                    => $wasteBreakdown,
                'total_records'                => $wasteLogs->count(),
            ],
            'opex' => [
                'total_opex'     => round($totalOpex, 2),
                'opex_ratio_pct' => $opexRatioPct,
                'breakdown'      => $opexBreakdown,
                'total_records'  => $expenses->count(),
            ],
            'bottom_line' => [
                'net_profit'     => round($netProfit, 2),
                'net_margin_pct' => $netMarginPct,
                'health_status'  => $healthStatus,
                'health_label'   => $healthLabel,
                'health_color'   => $healthColor,
            ],
            'waterfall' => $waterfall,
        ];
    }

    /** Internal helper - reuse variance computation (OPTIMIZED: pre-indexed by ingredient) */
    public function buildVarianceArray(string $from, string $to, ?int $outletId = null): array
    {
        if ($this->cachedActiveIngredients === null) {
            $this->cachedActiveIngredients = Ingredient::with(['outletIngredients'])->where('active', true)->get();
        }
        $ingredients = $this->cachedActiveIngredients;

        // Fetch movements WITHOUT heavy eager-loads (creator/user only needed for waste display)
        $movQuery = StockMovement::where(function ($q) use ($from, $to) {
            $q->where('date', '<', $from)->orWhereBetween('date', [$from, $to]);
        });
        if ($outletId) {
            $movQuery->where('outlet_id', $outletId);
        }
        $allMovements = $movQuery->get();

        // ⚡ KEY OPTIMIZATION: Pre-index movements by ingredient_id — O(m) once
        $movementsByIngredient = [];
        foreach ($allMovements as $m) {
            $movementsByIngredient[$m->ingredient_id][] = $m;
        }

        // Batch-load waste record creator names (only for WASTE type movements in period)
        $wasteCreatorIds = [];
        foreach ($allMovements as $m) {
            if ($m->type === 'WASTE' && $m->date >= $from && $m->date <= $to) {
                if ($m->created_by) $wasteCreatorIds[$m->created_by] = true;
                if ($m->user_id) $wasteCreatorIds[$m->user_id] = true;
            }
        }
        $userNames = !empty($wasteCreatorIds)
            ? \App\Models\User::whereIn('id', array_keys($wasteCreatorIds))->pluck('name', 'id')->all()
            : [];

        $opnQuery = Opname::where('period_from', $from)->where('period_to', $to);
        if ($outletId) {
            $opnQuery->where('outlet_id', $outletId);
        }
        $opnames = $opnQuery->get()->keyBy('ingredient_id');

        $result = [];
        foreach ($ingredients as $ing) {
            // ⚡ Only process THIS ingredient's movements — O(k) per ingredient
            $ingMovements = $movementsByIngredient[$ing->id] ?? [];
            $agg = $this->aggregateIngredientMovements($ingMovements, $from, $to);

            // Opening stock calculation
            if ($outletId) {
                $outletRow = $ing->outletIngredients->firstWhere('outlet_id', $outletId);
                $stokAwalMaster = $outletRow ? (float) $outletRow->stok_awal : ($outletId === 1 ? (float) $ing->stok_awal : 0.0);
            } else {
                $sumInit = (float) $ing->outletIngredients->sum('stok_awal');
                $stokAwalMaster = $sumInit > 0 ? $sumInit : (float) $ing->stok_awal;
            }

            $stokAwalPeriode = $stokAwalMaster + $agg['balanceBefore'];
            $pembelian       = $agg['purchase'];
            $pemakaianTeo    = $agg['saleUsage'];
            $prepUsage       = $agg['prepUsage'] ?? 0.0;
            $prepOutput      = $agg['prepOutput'] ?? 0.0;
            $wasteQty        = $agg['waste'];
            $transferIn      = $agg['transferIn'];
            $transferOut     = $agg['transferOut'];
            $adjustment      = $agg['adjustment'];

            $stokAkhirTeo = $stokAwalPeriode + $pembelian + $prepOutput + $transferIn - $pemakaianTeo - $prepUsage - $wasteQty - $transferOut + $adjustment;

            $opname    = $opnames->get($ing->id);
            $actualQty = $opname?->actual_qty;
            $hasActual = $actualQty !== null;

            $hargaPerPakai       = $ing->harga / max($ing->konversi, 1);
            $wasteValue          = round($wasteQty * $hargaPerPakai, 0);

            // Pemakaian fisik lapangan (memperhitungkan transfer & batch prep)
            $pemakaianAktual     = $hasActual ? ($stokAwalPeriode + $pembelian + $prepOutput + $transferIn - $transferOut - $actualQty) : null;
            // Variance kotor terhadap total pemakaian resep (POS + Batch Prep)
            $totalTeoritisPakai  = $pemakaianTeo + $prepUsage;
            $varianceGrossQty    = $hasActual ? ($pemakaianAktual - $totalTeoritisPakai) : null;
            $varianceGrossValue  = $hasActual ? round($varianceGrossQty * $hargaPerPakai, 0) : null;
            // Unaccounted variance (setelah dikurangi waste tercatat)
            $unaccountedQty      = $hasActual ? ($varianceGrossQty - $wasteQty) : null;
            $unaccountedValue    = $hasActual ? round($unaccountedQty * $hargaPerPakai, 0) : null;

            $variancePct         = ($hasActual && $totalTeoritisPakai > 0) ? ($unaccountedQty / $totalTeoritisPakai) * 100 : null;
            $status              = $hasActual ? $this->statusOf(abs($variancePct ?? 0), $ing->tolerance) : null;

            // Waste records already collected during aggregation — no extra loop needed
            $wasteRecordsFmt = [];
            foreach ($agg['wasteRecords'] as $m) {
                $wasteRecordsFmt[] = [
                    'id'              => $m->id,
                    'date'            => $m->date,
                    'qty'             => (float) $m->qty,
                    'waste_reason'    => $m->waste_reason ?: 'SPOILED',
                    'note'            => $m->note,
                    'value'           => round($m->qty * $hargaPerPakai, 0),
                    'created_by_name' => $userNames[$m->created_by] ?? ($userNames[$m->user_id] ?? 'Staff'),
                ];
            }

            $result[] = [
                'ingredient'           => $ing,
                'outlet_id'            => $outletId,
                'stok_awal_periode'    => round($stokAwalPeriode, 3),
                'pembelian'            => round($pembelian, 3),
                'pemakaian_teoritis'   => round($pemakaianTeo, 3),
                'prep_usage'           => round($prepUsage, 3),
                'prep_output'          => round($prepOutput, 3),
                'waste'                => round($wasteQty, 3),
                'waste_qty'            => round($wasteQty, 3),
                'waste_value'          => $wasteValue,
                'waste_records'        => $wasteRecordsFmt,
                'transfer_in'          => round($transferIn, 3),
                'transfer_out'         => round($transferOut, 3),
                'adjustment'           => round($adjustment, 3),
                'stok_akhir_teoritis'  => round($stokAkhirTeo, 3),
                'stok_akhir_aktual'    => $actualQty,
                'pemakaian_aktual'     => $pemakaianAktual !== null ? round($pemakaianAktual, 3) : null,
                'variance_gross_qty'   => $varianceGrossQty !== null ? round($varianceGrossQty, 3) : null,
                'variance_gross_value' => $varianceGrossValue,
                'unaccounted_qty'      => $unaccountedQty !== null ? round($unaccountedQty, 3) : null,
                'unaccounted_value'    => $unaccountedValue,
                'variance_qty'         => $unaccountedQty !== null ? round($unaccountedQty, 3) : null,
                'variance_pct'         => $variancePct !== null ? round($variancePct, 2) : null,
                'variance_value'       => $unaccountedValue,
                'status'               => $status,
                'opname'               => $opname,
            ];
        }
        return $result;
    }
}

