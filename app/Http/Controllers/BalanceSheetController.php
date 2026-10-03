<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\CashTransaction;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Shift;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BalanceSheetController extends Controller
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
     * Get Comprehensive Balance Sheet Report (Laporan Neraca Keuangan)
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

        // =========================================================================
        // 1. LABA PERIODE BERJALAN / TAHUN INI (3-30003) DARI P&L
        // =========================================================================
        $reportCtrl = new ReportController();
        $pnlRequest = new Request([
            'from'      => $from,
            'to'        => $to,
            'outlet_id' => $outletId,
        ]);
        if ($user) {
            $pnlRequest->setUserResolver(fn() => $user);
        }
        $pnlResponse = $reportCtrl->profitAndLoss($pnlRequest);
        $pnlData = json_decode($pnlResponse->getContent(), true);

        $netProfitAccrual = (float)($pnlData['bottom_line']['net_profit'] ?? 0);

        // =========================================================================
        // 2. PIUTANG USAHA (1-10100) & PIUTANG GRAB / MITRA ONLINE (1-10150)
        // =========================================================================
        
        // A. Piutang Usaha / Kasbon Pelanggan (1-10100)
        $recCustomerQuery = Receivable::where('status', '!=', 'PAID')
            ->where('issue_date', '<=', $to)
            ->where(function ($q) {
                $q->where('ar_type', 'CUSTOMER')
                  ->orWhereNull('ar_type')
                  ->orWhere(function ($sub) {
                      $sub->where('ar_type', '!=', 'MERCHANT_ECOMMERCE')
                          ->where(function ($inner) {
                              $inner->whereNull('merchant_channel')
                                    ->orWhereNotIn('merchant_channel', ['GRAB', 'GRABFOOD', 'GOFOOD', 'SHOPEE', 'SHOPEEFOOD', 'TIKTOK', 'ONLINE_DELIVERY']);
                          });
                  });
            });

        if ($outletId) {
            $recCustomerQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $recCustomerQuery->where('business_id', $businessId);
        }
        $piutangUsaha = (float)$recCustomerQuery->sum('remaining_amount');

        // B. Piutang Grab & Mitra Platform Online (1-10150)
        // 1. Transaksi Penjualan Grab dari POS
        $grabSalesQuery = Transaction::where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('status', 'PAID')
                    ->where(function ($inner) {
                        $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                    });
            })->orWhere('status', 'SPLIT_CLOSED');
        })
        ->where(function ($q) {
            $q->where('payment_method', 'like', '%GRAB%')
              ->orWhere('payment_method', 'like', '%GOFOOD%')
              ->orWhere('payment_method', 'like', '%SHOPEE%')
              ->orWhere('payment_method', 'like', '%TIKTOK%');
        })
        ->where('date', '<=', $to);

        if ($outletId) {
            $grabSalesQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $grabSalesQuery->where('business_id', $businessId);
        }
        $grabSalesTotal = (float)$grabSalesQuery->sum('total_price');

        // 2. Pencairan Dana Grab yang telah cair & masuk ke Rekening Bank
        $grabSettledToBankQuery = CashTransaction::where('type', 'IN')
            ->whereIn('account', ['BANK_MAIN', 'BANK'])
            ->where(function ($q) {
                $q->where('category', 'like', '%GRAB%')
                  ->orWhere('category', 'like', '%SETTLEMENT%')
                  ->orWhere('category', 'like', '%ECOMMERCE%')
                  ->orWhere('name', 'like', '%Grab%')
                  ->orWhere('name', 'like', '%Settlement%')
                  ->orWhere('name', 'like', '%GoFood%')
                  ->orWhere('name', 'like', '%Shopee%')
                  ->orWhere('notes', 'like', '%Grab%')
                  ->orWhere('notes', 'like', '%Settlement%');
            })
            ->where('date', '<=', $to);

        if ($outletId) {
            $grabSettledToBankQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $grabSettledToBankQuery->where('business_id', $businessId);
        }
        $grabSettledToBank = (float)$grabSettledToBankQuery->sum('amount');

        // 3. Receivable E-Commerce / Grab yang tercatat di tabel receivables
        $recGrabQuery = Receivable::where(function ($q) {
            $q->where('ar_type', 'MERCHANT_ECOMMERCE')
              ->orWhereIn('merchant_channel', ['GRAB', 'GRABFOOD', 'GOFOOD', 'SHOPEE', 'SHOPEEFOOD', 'TIKTOK'])
              ->orWhere('merchant_channel', 'like', '%GRAB%')
              ->orWhere('customer_name', 'like', '%Grab%')
              ->orWhere('customer_name', 'like', '%E-Commerce%');
        })
        ->where('issue_date', '<=', $to);

        if ($outletId) {
            $recGrabQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $recGrabQuery->where('business_id', $businessId);
        }

        $recGrabPaid = (float)(clone $recGrabQuery)->where(function ($q) {
            $q->where('status', 'PAID')->orWhere('settlement_status', 'SETTLED');
        })->sum('paid_amount');

        $recGrabRemaining = (float)(clone $recGrabQuery)->where('status', '!=', 'PAID')
            ->where('settlement_status', '!=', 'SETTLED')
            ->sum('remaining_amount');

        // Total Grab yang telah cair ke Bank
        $totalGrabCairKeBank = max($grabSettledToBank, $recGrabPaid);

        // Sisa Piutang Grab yang belum cair
        $piutangGrab = max(0, round(max($recGrabRemaining, $grabSalesTotal - $totalGrabCairKeBank), 2));

        // =========================================================================
        // 3. PERSEDIAAN BAHAN (1-10200) & PERSEDIAAN PERLENGKAPAN (1-10210)
        // =========================================================================
        $ingQuery = Ingredient::where('active', true);
        if ($businessId) {
            $ingQuery->where('business_id', $businessId);
        }
        $ingredients = $ingQuery->with(['outletIngredients', 'movements', 'categoryModel'])->get();

        $persediaanBahan = 0.0;
        $persediaanPerlengkapan = 0.0;
        $modalAwalPersediaanTotal = 0.0;

        foreach ($ingredients as $ing) {
            $cat = strtolower((string)($ing->category ?: ($ing->categoryModel?->name ?? '')));
            $name = strtolower((string)$ing->name);
            $code = strtoupper((string)$ing->code);

            $isPerlengkapan = (
                str_contains($cat, 'perlengkapan') ||
                str_contains($cat, 'packaging') ||
                str_contains($cat, 'kemasan') ||
                str_contains($cat, 'cup') ||
                str_contains($cat, 'pipet') ||
                str_contains($cat, 'sedotan') ||
                str_contains($cat, 'tissue') ||
                str_contains($cat, 'tisu') ||
                str_contains($cat, 'sealer') ||
                str_contains($cat, 'kantong') ||
                str_contains($cat, 'kresek') ||
                str_contains($cat, 'paperbag') ||
                str_contains($cat, 'box') ||
                str_contains($cat, 'sendok') ||
                str_contains($cat, 'garpu') ||
                str_contains($name, 'cup') ||
                str_contains($name, 'pipet') ||
                str_contains($name, 'sedotan') ||
                str_contains($name, 'tissue') ||
                str_contains($name, 'tisu') ||
                str_contains($name, 'sealer') ||
                str_contains($name, 'kantong') ||
                str_contains($name, 'kresek') ||
                str_contains($name, 'paperbag') ||
                str_contains($name, 'box') ||
                str_contains($name, 'sendok') ||
                str_contains($name, 'garpu') ||
                str_starts_with($code, 'PLK-') ||
                str_starts_with($code, 'PKG-')
            );

            $stock = $outletId ? $ing->stockForOutlet($outletId) : $ing->consolidatedStock();
            $unitCost = $ing->costPerPakaiForOutlet($outletId);
            $itemValue = max(0, $stock * $unitCost);

            if ($isPerlengkapan) {
                $persediaanPerlengkapan += $itemValue;
            } else {
                $persediaanBahan += $itemValue;
            }

            // Hitung modal saldo awal persediaan bahan & perlengkapan fisik
            $outletRow = $outletId ? $ing->outletIngredients->firstWhere('outlet_id', $outletId) : $ing->outletIngredients->first();
            $stokAwalMaster = 0.0;
            $saldoAwalNominalMaster = 0.0;
            if ($outletRow && (float)$outletRow->stok_awal > 0) {
                $stokAwalMaster = (float)$outletRow->stok_awal;
                $saldoAwalNominalMaster = (float)($outletRow->saldo_awal_nominal ?? 0);
            } elseif ($ing->stok_awal > 0) {
                $stokAwalMaster = (float)$ing->stok_awal;
                $saldoAwalNominalMaster = (float)($ing->saldo_awal_nominal ?? 0);
            }
            if ($saldoAwalNominalMaster <= 0 && $stokAwalMaster > 0) {
                $saldoAwalNominalMaster = round($stokAwalMaster * $unitCost, 2);
            }
            $modalAwalPersediaanTotal += $saldoAwalNominalMaster;
        }

        // Add direct retail items in menu into Persediaan Bahan
        $menuQuery = Menu::where('item_type', 'DIRECT')->where('active', true);
        if ($businessId) {
            $menuQuery->where('business_id', $businessId);
        }
        $directMenus = $menuQuery->get();
        foreach ($directMenus as $m) {
            $persediaanBahan += ((float)$m->current_stock * (float)$m->cost_price);
        }
        $persediaanBahan = round($persediaanBahan, 2);
        $persediaanPerlengkapan = round($persediaanPerlengkapan, 2);

        // =========================================================================
        // 4. ASET TETAP (DIHILANGKAN SESUAI INSTRUKSI)
        // =========================================================================
        $jumlahAsetTetap = 0.0;
        $fixedAssetAccounts = [];

        // =========================================================================
        // 5. LIABILITAS / HUTANG (2-20000)
        // =========================================================================
        $payQuery = Payable::where('status', '!=', 'PAID')
            ->where('issue_date', '<=', $to);
        if ($outletId) {
            $payQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $payQuery->where('business_id', $businessId);
        }
        $hutangUsaha = (float)$payQuery->sum('remaining_amount');

        // Hutang pinjaman modal financing jika ada
        $loanIn = (float)CashTransaction::where('category', 'LOAN_RECEIPT')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');
        $loanOut = (float)CashTransaction::where('category', 'LOAN_REPAYMENT')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');
        $hutangLain = max(0, $loanIn - $loanOut);

        $jumlahHutang = round($hutangUsaha + $hutangLain, 2);

        $liabilityAccounts = [];
        $liabilityAccounts[] = [
            'code'   => '2-20100',
            'name'   => 'Hutang Usaha / Supplier',
            'amount' => round($hutangUsaha, 2),
        ];
        if ($hutangLain > 0) {
            $liabilityAccounts[] = [
                'code'   => '2-20200',
                'name'   => 'Hutang Pokok Pinjaman',
                'amount' => round($hutangLain, 2),
            ];
        }

        // =========================================================================
        // 6. KAS, BANK, & QRIS (ASET LANCAR)
        // =========================================================================
        // A. Kas Kecil (Cash Drawer / Kasir):
        // 1. Modal Awal Kasir / Kas Toko / Kas Kecil
        $modalAwalTrx = (float)CashTransaction::where('category', 'CAPITAL_INJECTION')
            ->where(function ($q) {
                $q->whereIn('account', ['CASH_DRAWER', 'PETTY_CASH', 'CASH'])
                  ->orWhere(function ($sub) {
                      $sub->whereNull('account')
                          ->orWhereNotIn('account', ['BANK_MAIN', 'KAS_BESAR']);
                  });
            })
            ->where('type', 'IN')
            ->where('date', '<=', $to)
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->sum('amount');

        // Modal Awal dari Shift Kasir (initial_cash laci kasir)
        $shiftModalAwal = 0.0;
        if ($outletId) {
            $shift = Shift::where('outlet_id', $outletId)
                ->whereDate('opened_at', '<=', $to)
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 ELSE 1 END")
                ->orderByDesc('opened_at')
                ->first();
            if ($shift) {
                $shiftModalAwal = (float)$shift->initial_cash;
            }
        } else {
            $activeOutlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->where('active', true)
                ->pluck('id');

            foreach ($activeOutlets as $oId) {
                $shift = Shift::where('outlet_id', $oId)
                    ->whereDate('opened_at', '<=', $to)
                    ->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 ELSE 1 END")
                    ->orderByDesc('opened_at')
                    ->first();
                if ($shift) {
                    $shiftModalAwal += (float)$shift->initial_cash;
                }
            }

            if ($shiftModalAwal == 0) {
                $fallbackShift = Shift::whereDate('opened_at', '<=', $to)
                    ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                    ->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 ELSE 1 END")
                    ->orderByDesc('opened_at')
                    ->first();
                if ($fallbackShift) {
                    $shiftModalAwal = (float)$fallbackShift->initial_cash;
                }
            }
        }

        // Modal Awal Kasir Pertama Kali (Shift Perdana saat entitas bisnis pertama kali dibuka)
        $modalKasPertama = 0.0;
        if ($outletId) {
            $firstShift = Shift::where('outlet_id', $outletId)
                ->whereDate('opened_at', '<=', $to)
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->orderBy('opened_at', 'asc')
                ->orderBy('id', 'asc')
                ->first();
            $modalKasPertama = $firstShift ? (float)$firstShift->initial_cash : (float)$shiftModalAwal;
        } else {
            $activeOutlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))->pluck('id');
            foreach ($activeOutlets as $oId) {
                $fs = Shift::where('outlet_id', $oId)
                    ->whereDate('opened_at', '<=', $to)
                    ->orderBy('opened_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->first();
                if ($fs) {
                    $modalKasPertama += (float)$fs->initial_cash;
                }
            }
            if ($modalKasPertama == 0) {
                $modalKasPertama = (float)$shiftModalAwal;
            }
        }
        if ($modalKasPertama <= 0) {
            $modalKasPertama = max($modalAwalTrx, $shiftModalAwal);
        }
        $modalAwalKasKecil = $modalKasPertama;

        // Penerimaan dari Pelunasan Kasbon / Piutang Pelanggan (Receivable Payments)
        $recPaymentQuery = ReceivablePayment::whereDate('payment_date', '<=', $to);
        if ($outletId) {
            $recPaymentQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $recPaymentQuery->where('business_id', $businessId);
        }
        $allRecPayments = $recPaymentQuery->get();

        $recCashTotal = 0.0;
        $recQrisTotal = 0.0;
        $recBankTotal = 0.0;
        foreach ($allRecPayments as $rp) {
            $rpm = strtoupper(trim($rp->payment_method ?? 'CASH'));
            $ramt = (float)$rp->amount;
            if ($rpm === 'CASH' || $rpm === 'TUNAI') {
                $recCashTotal += $ramt;
            } elseif (str_contains($rpm, 'QRIS')) {
                $recQrisTotal += $ramt;
            } elseif (str_contains($rpm, 'TRANSFER') || str_contains($rpm, 'DEBIT') || str_contains($rpm, 'EDC') || str_contains($rpm, 'BANK') || str_contains($rpm, 'CREDIT') || str_contains($rpm, 'KARTU')) {
                $recBankTotal += $ramt;
            }
        }

        // 2. Penjualan Tunai
        // A. Penjualan POS Tunai Langsung (non-kasbon)
        $directCashSales = (float)Transaction::where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('status', 'PAID')
                    ->where(function ($inner) {
                        $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                    });
            })->orWhere('status', 'SPLIT_CLOSED');
        })
        ->whereIn('payment_method', ['CASH', 'TUNAI'])
        ->whereNotIn('payment_method', ['KASBON', 'PIUTANG'])
        ->where('date', '<=', $to)
        ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
        ->when($businessId, fn($q) => $q->where('business_id', $businessId))
        ->sum('total_price');

        // Total Kas Tunai Masuk = Penjualan Tunai Langsung + Pelunasan Kasbon Tunai
        $cashSales = $directCashSales + $recCashTotal;

        // 3. Pengeluaran Kasir / Kas Kecil
        $cashExpenseQuery = CashTransaction::where('type', 'OUT')
            ->whereIn('account', ['CASH_DRAWER', 'PETTY_CASH', 'CASH'])
            ->where('date', '<=', $to);
        if ($outletId) {
            $cashExpenseQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $cashExpenseQuery->where('business_id', $businessId);
        }
        $cashExpense = (float)$cashExpenseQuery->sum('amount');
        
        // 4. Setoran Kasir ke Kas Besar
        $kasKecilSetoran = (float)CashTransaction::where(function ($q) {
            $q->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
              ->orWhere('account', 'KAS_BESAR');
        })
        ->where('type', 'IN')
        ->where('date', '<=', $to)
        ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
        ->when($businessId, fn($q) => $q->where('business_id', $businessId))
        ->sum('amount');

        // 5. Selisih Kasir dari shift yang ditutup (Closing Shift difference)
        $closedShiftsQuery = Shift::where('status', 'CLOSED')
            ->where(function ($q) use ($to) {
                $q->whereDate('closed_at', '<=', $to)
                  ->orWhere(function ($sub) use ($to) {
                      $sub->whereNull('closed_at')->whereDate('opened_at', '<=', $to);
                  });
            });
        if ($outletId) $closedShiftsQuery->where('outlet_id', $outletId);
        if ($businessId) $closedShiftsQuery->where('business_id', $businessId);
        $closedShifts = $closedShiftsQuery->orderBy('opened_at', 'asc')->get();

        $selisihTutupShiftTotal = 0.0;
        foreach ($closedShifts as $cs) {
            if ($cs->cash_difference !== null) {
                $diff = (float)$cs->cash_difference;
            } elseif ($cs->closing_cash !== null) {
                $expected = $cs->system_cash !== null && (float)$cs->system_cash > 0
                    ? (float)$cs->system_cash
                    : (float)$cs->initial_cash;
                $diff = round((float)$cs->closing_cash - $expected, 2);
            } else {
                $diff = 0.0;
            }
            $selisihTutupShiftTotal += $diff;
        }

        // 6. Selisih Modal Kasir Antar Shift (Initial cash shift baru vs closing cash shift sebelumnya)
        $allShiftsQuery = Shift::whereDate('opened_at', '<=', $to);
        if ($outletId) $allShiftsQuery->where('outlet_id', $outletId);
        if ($businessId) $allShiftsQuery->where('business_id', $businessId);
        $allShifts = $allShiftsQuery->orderBy('opened_at', 'asc')->orderBy('id', 'asc')->get();

        $selisihAntarShiftTotal = 0.0;
        $prevClosedShift = null;

        foreach ($allShifts as $s) {
            if ($prevClosedShift && $prevClosedShift->closing_cash !== null) {
                $prevClosing = (float)$prevClosedShift->closing_cash;
                $diffAntarShift = round((float)$s->initial_cash - $prevClosing, 2);
                $selisihAntarShiftTotal += $diffAntarShift;
            }
            if ($s->status === 'CLOSED' && $s->closing_cash !== null) {
                $prevClosedShift = $s;
            }
        }

        // Saldo Kas Kecil: Modal Awal Kasir Pertama Kali + Penjualan Tunai - Pengeluaran Kas - Setoran Kasir ke Kas Besar + Selisih Tutup Shift + Selisih Modal Antar Shift
        $kasKecil = max(0, round($modalKasPertama + $cashSales - $cashExpense - $kasKecilSetoran + $selisihTutupShiftTotal + $selisihAntarShiftTotal, 2));

        // B. QRIS (1-10003):
        // Penjualan POS QRIS Langsung (non-kasbon)
        $directQrisSales = (float)Transaction::where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('status', 'PAID')
                    ->where(function ($inner) {
                        $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                    });
            })->orWhere('status', 'SPLIT_CLOSED');
        })
        ->where('payment_method', 'like', '%QRIS%')
        ->whereNotIn('payment_method', ['KASBON', 'PIUTANG'])
        ->where('date', '<=', $to)
        ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
        ->when($businessId, fn($q) => $q->where('business_id', $businessId))
        ->sum('total_price');

        // Total Penerimaan QRIS = Penjualan Langsung QRIS + Pelunasan Kasbon QRIS
        $qrisSales = $directQrisSales + $recQrisTotal;

        // C. Bank Transfer & Debit EDC (1-10004) (Termasuk dana Grab yang cair ke Bank):
        // Penjualan POS Transfer/Debit Langsung (non-kasbon)
        $directBankSales = (float)Transaction::where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('status', 'PAID')
                    ->where(function ($inner) {
                        $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                    });
            })->orWhere('status', 'SPLIT_CLOSED');
        })
        ->where(function ($inner) {
            $inner->where('payment_method', 'like', '%TRANSFER%')
                  ->orWhere('payment_method', 'like', '%DEBIT%')
                  ->orWhere('payment_method', 'like', '%EDC%')
                  ->orWhere('payment_method', 'like', '%BANK%')
                  ->orWhere('payment_method', 'like', '%CREDIT%')
                  ->orWhere('payment_method', 'like', '%KARTU%');
        })
        ->whereNotIn('payment_method', ['KASBON', 'PIUTANG'])
        ->where('date', '<=', $to)
        ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
        ->when($businessId, fn($q) => $q->where('business_id', $businessId))
        ->sum('total_price');

        // Total Kas Masuk Penjualan & Kasbon via Bank:
        $bankSales = $directBankSales + $recBankTotal;

        // Kas Masuk ke Bank lainnya (Setoran Kasir ke Bank, Pencairan Grab ke Bank, Modal/Pinjaman Masuk ke Bank)
        $bankInQuery = CashTransaction::where('type', 'IN')
            ->whereIn('account', ['BANK_MAIN', 'BANK'])
            ->where('date', '<=', $to);
        if ($outletId) {
            $bankInQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $bankInQuery->where('business_id', $businessId);
        }
        $bankInOther = (float)$bankInQuery->sum('amount');

        // Total Kas Masuk ke Bank (termasuk dana Grab yang cair ke bank):
        $totalBankIn = $bankSales + max($bankInOther, $totalGrabCairKeBank);

        $bankExpenseQuery = CashTransaction::where('type', 'OUT')
            ->whereIn('account', ['BANK_MAIN', 'BANK'])
            ->where('date', '<=', $to);
        if ($outletId) {
            $bankExpenseQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $bankExpenseQuery->where('business_id', $businessId);
        }
        $bankExpense = (float)$bankExpenseQuery->sum('amount');
        $bankNet = max(0, round($totalBankIn - $bankExpense, 2));

        // Rekening Bank & QRIS Accounts:
        $bankAccountsQuery = BankAccount::where('is_active', true);
        if ($businessId) {
            $bankAccountsQuery->where('business_id', $businessId);
        }
        if ($outletId) {
            $bankAccountsQuery->where(function ($sq) use ($outletId) {
                $sq->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $bankAccounts = $bankAccountsQuery->get();

        $qrisAccounts = $bankAccounts->filter(fn($ba) => strtoupper($ba->account_type) === 'QRIS' || stripos($ba->bank_name, 'QRIS') !== false);
        $bankTransferAccounts = $bankAccounts->filter(fn($ba) => !(strtoupper($ba->account_type) === 'QRIS' || stripos($ba->bank_name, 'QRIS') !== false));

        $bankQrAccounts = [];
        $accCodeCounter = 10003;

        // QRIS Account(s)
        if ($qrisAccounts->count() > 0) {
            $qrisCount = $qrisAccounts->count();
            foreach ($qrisAccounts as $ba) {
                $qrisName = !empty($ba->account_number) ? "QRIS | {$ba->account_number}" : "QRIS ({$ba->bank_name})";
                $bankQrAccounts[] = [
                    'code'   => '1-' . $accCodeCounter++,
                    'name'   => $qrisName,
                    'amount' => round($qrisSales / $qrisCount, 2),
                ];
            }
        } else {
            $bankQrAccounts[] = [
                'code'   => '1-10003',
                'name'   => 'QRIS',
                'amount' => round($qrisSales, 2),
            ];
            $accCodeCounter = 10004;
        }

        // Bank Transfer Account(s)
        if ($bankTransferAccounts->count() > 0) {
            $bankCount = $bankTransferAccounts->count();
            foreach ($bankTransferAccounts as $ba) {
                $bankLabel = !empty($ba->bank_code) ? "Bank Transfer ({$ba->bank_code} | {$ba->account_number})" : (!empty($ba->bank_name) ? "Bank Transfer ({$ba->bank_name} | {$ba->account_number})" : "Bank Transfer | {$ba->account_number}");
                $bankQrAccounts[] = [
                    'code'   => '1-' . $accCodeCounter++,
                    'name'   => $bankLabel,
                    'amount' => round($bankNet / $bankCount, 2),
                ];
            }
        } else {
            $bankQrAccounts[] = [
                'code'   => '1-10004',
                'name'   => 'Bank Transfer',
                'amount' => round($bankNet, 2),
            ];
        }

        // C. Kas Besar (1-10001):
        // Kas Besar mencatat saldo brankas / kas utama dari setoran kasir & penerimaan kas besar
        $kasBesarIn = (float)CashTransaction::where(function ($q) {
            $q->where(function ($sub) {
                $sub->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                    ->where('account', '!=', 'BANK_MAIN');
            })->orWhere(function ($sub) {
                $sub->where('account', 'KAS_BESAR');
            });
        })
        ->where('type', 'IN')
        ->where('date', '<=', $to)
        ->when($businessId, fn($q) => $q->where('business_id', $businessId))
        ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
        ->sum('amount');

        $kasBesarOut = (float)CashTransaction::where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('category', 'OWNER_WITHDRAWAL')
                    ->where('account', 'KAS_BESAR');
            })->orWhere('account', 'KAS_BESAR');
        })
        ->where('type', 'OUT')
        ->where('date', '<=', $to)
        ->when($businessId, fn($q) => $q->where('business_id', $businessId))
        ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
        ->sum('amount');

        $kasBesar = max(0, round($kasBesarIn - $kasBesarOut, 2));

        // Susun daftar akun Aset Lancar:
        $currentAssetAccounts = [];
        $currentAssetAccounts[] = [
            'code'   => '1-10001',
            'name'   => 'Kas Besar',
            'amount' => $kasBesar,
        ];
        $currentAssetAccounts[] = [
            'code'   => '1-10002',
            'name'   => 'Kas Kecil (Laci Kasir)',
            'amount' => $kasKecil,
        ];
        foreach ($bankQrAccounts as $bqa) {
            $currentAssetAccounts[] = $bqa;
        }
        $currentAssetAccounts[] = [
            'code'   => '1-10100',
            'name'   => 'Piutang Usaha (Pelanggan / Kasbon)',
            'amount' => round($piutangUsaha, 2),
        ];
        $currentAssetAccounts[] = [
            'code'   => '1-10150',
            'name'   => 'Piutang Grab & Mitra Online (Belum Cair)',
            'amount' => round($piutangGrab, 2),
        ];
        $currentAssetAccounts[] = [
            'code'   => '1-10200',
            'name'   => 'Persediaan Bahan',
            'amount' => round($persediaanBahan, 2),
        ];
        $currentAssetAccounts[] = [
            'code'   => '1-10210',
            'name'   => 'Persediaan Perlengkapan',
            'amount' => round($persediaanPerlengkapan, 2),
        ];

        $jumlahAsetLancar = round(array_sum(array_column($currentAssetAccounts, 'amount')), 2);
        $jumlahAset = $jumlahAsetLancar;

        // =========================================================================
        // 7. MODAL & EKUITAS (3-30000) - PERSAMAAN DASAR AKUNTANSI: ASET = LIABILITAS + EKUITAS
        // =========================================================================
        $modalDisetorExplicit = (float)CashTransaction::where('category', 'CAPITAL_INJECTION')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');

        $prive = (float)CashTransaction::where('category', 'OWNER_WITHDRAWAL')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');

        $labaTahunIni = round($netProfitAccrual, 2);

        // Modal Pemilik / Disetor bersifat TETAP (tidak boleh bergerak / berubah karena laba-rugi)

        // Modal Pemilik / Disetor bersifat TETAP (tidak boleh bergerak / berubah karena laba-rugi)
        $biz = $businessId ? Business::find($businessId) : Business::first();
        $customModal = null;
        if ($biz && is_array($biz->settings) && isset($biz->settings['modal_pemilik'])) {
            $customModal = (float)$biz->settings['modal_pemilik'];
        }

        if ($customModal !== null && $customModal > 0) {
            $modalPemilikTetap = round($customModal, 2);
        } elseif ($modalDisetorExplicit > 0 && ($modalKasPertama + $modalAwalPersediaanTotal) == 0) {
            $modalPemilikTetap = round($modalDisetorExplicit, 2);
        } else {
            // Gabungan modal kas awal (kasir pertama kali), setoran kas/bank eksplisit, dan persediaan awal fisik
            $modalPemilikTetap = round($modalKasPertama + $modalDisetorExplicit + $modalAwalPersediaanTotal, 2);
            if ($modalPemilikTetap <= 0) {
                $modalPemilikTetap = max(0, round($jumlahAset - $jumlahHutang, 2));
            }
        }

        $targetTotalEkuitas = round($jumlahAset - $jumlahHutang, 2);
        // Saldo Laba (Laba Ditahan) menampung akumulasi laba/rugi historis tanpa mengubah Modal Pemilik Tetap
        $saldoLaba = round($targetTotalEkuitas - ($modalPemilikTetap - $prive + $labaTahunIni), 2);

        $equityAccounts = [];
        $equityAccounts[] = [
            'code'   => '3-30001',
            'name'   => 'Modal Pemilik / Disetor',
            'amount' => round($modalPemilikTetap, 2),
        ];
        if ($prive > 0) {
            $equityAccounts[] = [
                'code'   => '3-30002',
                'name'   => 'Prive Pemilik',
                'amount' => round(-$prive, 2),
            ];
        }
        if (abs($saldoLaba) >= 0.01) {
            $equityAccounts[] = [
                'code'   => '3-30004',
                'name'   => 'Saldo Laba (Laba Ditahan)',
                'amount' => round($saldoLaba, 2),
            ];
        }
        $equityAccounts[] = [
            'code'   => '3-30003',
            'name'   => 'Laba Tahun Ini',
            'amount' => $labaTahunIni,
        ];

        $jumlahModal = round(array_sum(array_column($equityAccounts, 'amount')), 2);

        // Total Kewajiban dan Modal (Liabilities + Equity)
        $jumlahKewajibanDanModal = round($jumlahHutang + $jumlahModal, 2);

        $difference = round($jumlahAset - $jumlahKewajibanDanModal, 2);
        $isBalanced = abs($difference) < 1.0;

        return response()->json([
            'period' => [
                'from'           => $from,
                'to'             => $to,
                'from_formatted' => $this->formatIndonesianDate($from),
                'to_formatted'   => $this->formatIndonesianDate($to),
                'outlet_id'      => $outletId,
                'outlet_name'    => $outletName,
            ],
            'current_assets' => [
                'label'    => 'Aset Lancar',
                'accounts' => $currentAssetAccounts,
                'subtotal' => $jumlahAsetLancar,
            ],
            'fixed_assets' => [
                'label'    => 'Aset Tetap',
                'accounts' => $fixedAssetAccounts,
                'subtotal' => $jumlahAsetTetap,
            ],
            'total_assets' => [
                'label'  => 'Jumlah Aset',
                'amount' => $jumlahAset,
            ],
            'liabilities' => [
                'label'    => 'Liabilitas',
                'accounts' => $liabilityAccounts,
                'subtotal' => $jumlahHutang,
            ],
            'equity' => [
                'label'    => 'Modal',
                'accounts' => $equityAccounts,
                'subtotal' => $jumlahModal,
            ],
            'total_liabilities_and_equity' => [
                'label'  => 'Jumlah Kewajiban dan Modal',
                'amount' => $jumlahKewajibanDanModal,
            ],
            'is_balanced' => $isBalanced,
            'difference'  => $difference,
        ]);
    }

    /**
     * Get Drill-Down Detailed Audit Trail for a Specific Balance Sheet Account / Metric
     */
    public function detail(Request $request)
    {
        $accountCode = trim((string)$request->input('account_code', ''));
        $accountName = trim((string)$request->input('account_name', ''));
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

        // =========================================================================
        // 1. KAS BESAR (1-10001)
        // =========================================================================
        if ($accountCode === '1-10001') {
            $kasBesarInQuery = CashTransaction::where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                        ->where('account', '!=', 'BANK_MAIN');
                })->orWhere('account', 'KAS_BESAR');
            })
            ->where('type', 'IN')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId));

            $kasBesarOutQuery = CashTransaction::where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('category', 'OWNER_WITHDRAWAL')
                        ->where('account', 'KAS_BESAR');
                })->orWhere('account', 'KAS_BESAR');
            })
            ->where('type', 'OUT')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId));

            $kasBesarIn = (float)$kasBesarInQuery->sum('amount');
            $kasBesarOut = (float)$kasBesarOutQuery->sum('amount');
            $kasBesar = max(0, round($kasBesarIn - $kasBesarOut, 2));

            $transactions = CashTransaction::where('date', '<=', $to)
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                            ->where('account', '!=', 'BANK_MAIN');
                    })->orWhere('account', 'KAS_BESAR');
                })
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->orderBy('date', 'desc')
                ->orderBy('id', 'desc')
                ->limit(200)
                ->get();

            $items = $transactions->map(function ($t) {
                return [
                    'date'           => $t->date?->format('Y-m-d') ?: (string)$t->date,
                    'ref_no'         => $t->transaction_no ?: "TRX-{$t->id}",
                    'category'       => $t->category_label ?: $t->category,
                    'description'    => $t->name ?: ($t->notes ?: '-'),
                    'account'        => $t->account_label ?: ($t->account ?: 'KAS_BESAR'),
                    'direction'      => $t->type === 'IN' ? 'MASUK' : 'KELUAR',
                    'amount'         => (float)$t->amount,
                    'user_name'      => $t->user_name ?: '-',
                ];
            });

            return response()->json([
                'account_code'    => '1-10001',
                'account_name'    => 'Kas Besar',
                'account_type'    => 'CURRENT_ASSET',
                'category_label'  => 'Aset Lancar',
                'amount'          => $kasBesar,
                'explanation'     => 'Saldo fisik brankas / kas besar utama bisnis yang berasal dari akumulasi seluruh setoran kasir harian dan penerimaan kas besar lainnya, dikurangi pengeluaran operasional serta penarikan dana oleh owner dari kas besar hingga tanggal laporan.',
                'formula'         => 'Total Kas Masuk ke Kas Besar - Total Kas Keluar dari Kas Besar',
                'components'      => [
                    ['label' => 'Total Kas Masuk & Setoran', 'value' => $kasBesarIn, 'type' => 'success', 'prefix' => '(+)'],
                    ['label' => 'Total Kas Keluar / Tarik Tunai', 'value' => $kasBesarOut, 'type' => 'danger', 'prefix' => '(-)'],
                    ['label' => 'Saldo Akhir Kas Besar', 'value' => $kasBesar, 'type' => 'primary', 'prefix' => '(=)'],
                ],
                'columns'         => [
                    ['key' => 'date', 'label' => 'Tanggal', 'align' => 'left'],
                    ['key' => 'ref_no', 'label' => 'No. Ref', 'align' => 'left'],
                    ['key' => 'category', 'label' => 'Kategori', 'align' => 'left'],
                    ['key' => 'description', 'label' => 'Keterangan', 'align' => 'left'],
                    ['key' => 'direction', 'label' => 'Arus', 'align' => 'center'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'user_name', 'label' => 'Kasir / Petugas', 'align' => 'left'],
                ],
                'items'           => $items,
                'action_link'     => '/cash-flow',
                'action_label'    => 'Buka Buku Kas & Arus Kas',
            ]);
        }

        // =========================================================================
        // 2. KAS KECIL / LACI KASIR (1-10002)
        // =========================================================================
        if ($accountCode === '1-10002') {
            // Modal Awal Kasir / Kas Toko / Kas Kecil
            $modalAwalTrx = (float)CashTransaction::where('category', 'CAPITAL_INJECTION')
                ->where(function ($q) {
                    $q->whereIn('account', ['CASH_DRAWER', 'PETTY_CASH', 'CASH'])
                      ->orWhere(function ($sub) {
                          $sub->whereNull('account')
                              ->orWhereNotIn('account', ['BANK_MAIN', 'KAS_BESAR']);
                      });
                })
                ->where('type', 'IN')
                ->where('date', '<=', $to)
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->sum('amount');

            $shiftModalAwal = 0.0;
            if ($outletId) {
                $shift = Shift::where('outlet_id', $outletId)
                    ->whereDate('opened_at', '<=', $to)
                    ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                    ->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 ELSE 1 END")
                    ->orderByDesc('opened_at')
                    ->first();
                if ($shift) {
                    $shiftModalAwal = (float)$shift->initial_cash;
                }
            } else {
                $activeOutlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))
                    ->where('active', true)
                    ->pluck('id');
                foreach ($activeOutlets as $oId) {
                    $shift = Shift::where('outlet_id', $oId)
                        ->whereDate('opened_at', '<=', $to)
                        ->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 ELSE 1 END")
                        ->orderByDesc('opened_at')
                        ->first();
                    if ($shift) {
                        $shiftModalAwal += (float)$shift->initial_cash;
                    }
                }
            }
            $modalAwalKasKecil = max($modalAwalTrx, $shiftModalAwal);

            // Penjualan Tunai POS
            $directCashSales = (float)Transaction::where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->whereIn('payment_method', ['CASH', 'TUNAI'])
            ->whereNotIn('payment_method', ['KASBON', 'PIUTANG'])
            ->where('date', '<=', $to)
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->sum('total_price');

            // Penerimaan Kasbon Tunai
            $recCashSales = (float)ReceivablePayment::whereDate('payment_date', '<=', $to)
                ->whereIn('payment_method', ['CASH', 'TUNAI'])
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->sum('amount');

            $cashSales = $directCashSales + $recCashSales;

            // Pengeluaran Kasir
            $cashExpenseQuery = CashTransaction::where('type', 'OUT')
                ->whereIn('account', ['CASH_DRAWER', 'PETTY_CASH', 'CASH'])
                ->where('date', '<=', $to);
            if ($outletId) $cashExpenseQuery->where('outlet_id', $outletId);
            if ($businessId) $cashExpenseQuery->where('business_id', $businessId);
            $cashExpense = (float)$cashExpenseQuery->sum('amount');

            // Setoran Kasir
            $kasKecilSetoran = (float)CashTransaction::where(function ($q) {
                $q->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                  ->orWhere('account', 'KAS_BESAR');
            })
            ->where('type', 'IN')
            ->where('date', '<=', $to)
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->sum('amount');

            // Modal Awal Kasir Pertama Kali (Shift Perdana saat entitas bisnis pertama kali dibuka)
            $modalKasPertama = 0.0;
            $firstShiftPerdana = null;
            if ($outletId) {
                $firstShiftPerdana = Shift::with(['user', 'outlet'])
                    ->where('outlet_id', $outletId)
                    ->whereDate('opened_at', '<=', $to)
                    ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                    ->orderBy('opened_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->first();
                if ($firstShiftPerdana) {
                    $modalKasPertama = (float)$firstShiftPerdana->initial_cash;
                }
            } else {
                $activeOutlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))->pluck('id');
                foreach ($activeOutlets as $oId) {
                    $fs = Shift::with(['user', 'outlet'])
                        ->where('outlet_id', $oId)
                        ->whereDate('opened_at', '<=', $to)
                        ->orderBy('opened_at', 'asc')
                        ->orderBy('id', 'asc')
                        ->first();
                    if ($fs) {
                        $modalKasPertama += (float)$fs->initial_cash;
                        if (!$firstShiftPerdana) $firstShiftPerdana = $fs;
                    }
                }
            }
            if ($modalKasPertama <= 0) {
                $modalKasPertama = max($modalAwalTrx, $shiftModalAwal);
            }

            // Selisih Kasir dari shift yang ditutup (Closing Shift difference)
            $closedShiftsQuery = Shift::with(['user', 'closedByUser', 'outlet'])
                ->where('status', 'CLOSED')
                ->where(function ($q) use ($to) {
                    $q->whereDate('closed_at', '<=', $to)
                      ->orWhere(function ($sub) use ($to) {
                          $sub->whereNull('closed_at')->whereDate('opened_at', '<=', $to);
                      });
                });
            if ($outletId) $closedShiftsQuery->where('outlet_id', $outletId);
            if ($businessId) $closedShiftsQuery->where('business_id', $businessId);
            $closedShifts = $closedShiftsQuery->orderBy('opened_at', 'asc')->get();

            $selisihTutupShiftTotal = 0.0;
            $closedShiftDiffItems = [];
            foreach ($closedShifts as $cs) {
                if ($cs->cash_difference !== null) {
                    $diff = (float)$cs->cash_difference;
                } elseif ($cs->closing_cash !== null) {
                    $expected = $cs->system_cash !== null && (float)$cs->system_cash > 0
                        ? (float)$cs->system_cash
                        : (float)$cs->initial_cash;
                    $diff = round((float)$cs->closing_cash - $expected, 2);
                } else {
                    $diff = 0.0;
                }
                $selisihTutupShiftTotal += $diff;

                if ($diff != 0) {
                    $closedShiftDiffItems[] = [
                        'date'        => $cs->closed_at ? date('Y-m-d', strtotime($cs->closed_at)) : (string)$cs->opened_at,
                        'ref_no'      => "SHIFT-{$cs->id}-CLOSING",
                        'category'    => 'Selisih Kasir Tutup Shift',
                        'description' => "Selisih fisik kas closing {$cs->shift_name} (Fisik: Rp " . number_format((float)$cs->closing_cash, 0, ',', '.') . ")",
                        'amount'      => $diff,
                        'user_name'   => $cs->closedByUser?->name ?: ($cs->user?->name ?: 'Kasir'),
                    ];
                }
            }

            // Selisih Modal Kasir Antar Shift (Initial cash shift baru vs closing cash shift sebelumnya)
            $allShiftsQuery = Shift::with(['user', 'outlet'])
                ->whereDate('opened_at', '<=', $to);
            if ($outletId) $allShiftsQuery->where('outlet_id', $outletId);
            if ($businessId) $allShiftsQuery->where('business_id', $businessId);
            $allShifts = $allShiftsQuery->orderBy('opened_at', 'asc')->orderBy('id', 'asc')->get();

            $selisihAntarShiftTotal = 0.0;
            $interShiftDiffItems = [];
            $prevClosedShift = null;

            foreach ($allShifts as $s) {
                if ($prevClosedShift && $prevClosedShift->closing_cash !== null) {
                    $prevClosing = (float)$prevClosedShift->closing_cash;
                    $diffAntarShift = round((float)$s->initial_cash - $prevClosing, 2);
                    $selisihAntarShiftTotal += $diffAntarShift;

                    if ($diffAntarShift != 0) {
                        $interShiftDiffItems[] = [
                            'date'        => $s->opened_at ? date('Y-m-d', strtotime($s->opened_at)) : '-',
                            'ref_no'      => "SHIFT-{$s->id}-SERAH-TERIMA",
                            'category'    => 'Selisih Modal Antar Shift',
                            'description' => "Modal {$s->shift_name} vs Kas Lalu {$prevClosedShift->shift_name} (Rp " . number_format((float)$s->initial_cash, 0, ',', '.') . " vs Rp " . number_format($prevClosing, 0, ',', '.') . ")",
                            'amount'      => $diffAntarShift,
                            'user_name'   => $s->user?->name ?: 'Kasir',
                        ];
                    }
                }
                if ($s->status === 'CLOSED' && $s->closing_cash !== null) {
                    $prevClosedShift = $s;
                }
            }

            // Saldo Kas Kecil: Modal Awal Kasir Pertama Kali + Penjualan Tunai - Pengeluaran Kas - Setoran Kasir ke Kas Besar + Selisih Tutup Shift + Selisih Modal Antar Shift
            $kasKecil = max(0, round($modalKasPertama + $cashSales - $cashExpense - $kasKecilSetoran + $selisihTutupShiftTotal + $selisihAntarShiftTotal, 2));

            // Susun Data Rincian Items Audit Laci Kasir
            $items = [];

            // A. Modal Kasir Pertama Kali (Shift Perdana)
            if ($firstShiftPerdana) {
                $items[] = [
                    'date'        => $firstShiftPerdana->opened_at ? date('Y-m-d', strtotime($firstShiftPerdana->opened_at)) : '-',
                    'ref_no'      => "SHIFT-{$firstShiftPerdana->id}-PERDANA",
                    'category'    => 'Modal Kasir Pertama Kali',
                    'description' => "Modal awal laci kasir perdana saat operasional toko pertama kali dibuka ({$firstShiftPerdana->shift_name})",
                    'amount'      => $modalKasPertama,
                    'user_name'   => $firstShiftPerdana->user?->name ?: 'Kasir',
                ];
            }

            // B. Pelunasan Kasbon (Tunai) - Uang Masuk ke Laci Kasir
            $recCashList = ReceivablePayment::with(['receivable', 'receivable.customer', 'receiver'])
                ->whereDate('payment_date', '<=', $to)
                ->whereIn('payment_method', ['CASH', 'TUNAI'])
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->get();
            foreach ($recCashList as $rp) {
                $cust = $rp->receivable?->customer_name ?: ($rp->receivable?->customer?->name ?: 'Pelanggan');
                $items[] = [
                    'date'        => $rp->payment_date ? (is_string($rp->payment_date) ? substr($rp->payment_date, 0, 10) : $rp->payment_date->format('Y-m-d')) : '-',
                    'ref_no'      => $rp->payment_no ?: "PAY-PIU-{$rp->id}",
                    'category'    => 'Pelunasan Kasbon (Tunai)',
                    'description' => "Penerimaan tunai pelunasan piutang/kasbon ({$cust})",
                    'amount'      => (float)$rp->amount,
                    'user_name'   => $rp->received_by_name ?: ($rp->receiver?->name ?: 'Kasir'),
                ];
            }

            // C. Penjualan Tunai Kasir Langsung (Cash Inflow dari Transaksi POS)
            $directCashTxList = Transaction::with(['user', 'outlet'])
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('status', 'PAID')
                            ->where(function ($inner) {
                                $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                            });
                    })->orWhere('status', 'SPLIT_CLOSED');
                })
                ->whereIn('payment_method', ['CASH', 'TUNAI'])
                ->whereNotIn('payment_method', ['KASBON', 'PIUTANG'])
                ->where('date', '<=', $to)
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(200)
                ->get();
            foreach ($directCashTxList as $ctx) {
                $custStr = $ctx->customer_name ? " ({$ctx->customer_name})" : '';
                $items[] = [
                    'date'        => $ctx->date ? date('Y-m-d', strtotime($ctx->date)) : ($ctx->created_at ? $ctx->created_at->format('Y-m-d') : '-'),
                    'ref_no'      => $ctx->invoice_number ?: ($ctx->order_number ?: "TRX-{$ctx->id}"),
                    'category'    => 'Penjualan Tunai Kasir',
                    'description' => "Penjualan langsung tunai di kasir{$custStr}",
                    'amount'      => (float)$ctx->total_price,
                    'user_name'   => $ctx->user?->name ?: ($ctx->cashier_name ?: 'Kasir'),
                ];
            }

            // D. Pengeluaran Kasir (Cash Out dari Laci)
            $expenseList = (clone $cashExpenseQuery)->orderByDesc('date')->orderByDesc('id')->limit(100)->get();
            foreach ($expenseList as $ex) {
                $items[] = [
                    'date'        => $ex->date?->format('Y-m-d') ?: (string)$ex->date,
                    'ref_no'      => $ex->transaction_no ?: "EXP-{$ex->id}",
                    'category'    => $ex->category_label ?: ($ex->category ?: 'Operasional Kasir'),
                    'description' => $ex->name ?: ($ex->notes ?: 'Pengeluaran Kas Laci'),
                    'amount'      => -(float)$ex->amount,
                    'user_name'   => $ex->user_name ?: '-',
                ];
            }

            // E. Setoran Kasir ke Kas Besar
            $setoranList = CashTransaction::where(function ($q) {
                $q->whereIn('category', ['SETORAN_KASIR', 'CASH_DEPOSIT'])
                  ->orWhere('account', 'KAS_BESAR');
            })
            ->where('type', 'IN')
            ->where('date', '<=', $to)
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->orderByDesc('date')
            ->get();
            foreach ($setoranList as $st) {
                $items[] = [
                    'date'        => $st->date?->format('Y-m-d') ?: (string)$st->date,
                    'ref_no'      => $st->transaction_no ?: "SET-{$st->id}",
                    'category'    => 'Setoran ke Kas Besar',
                    'description' => $st->name ?: ($st->notes ?: 'Setoran Uang Kasir ke Kas Besar'),
                    'amount'      => -(float)$st->amount,
                    'user_name'   => $st->user_name ?: '-',
                ];
            }

            // F. Selisih Kasir Tutup Shift
            foreach ($closedShiftDiffItems as $ci) {
                $items[] = $ci;
            }

            // G. Selisih Modal Kasir Antar Shift
            foreach ($interShiftDiffItems as $ii) {
                $items[] = $ii;
            }

            return response()->json([
                'account_code'    => '1-10002',
                'account_name'    => 'Kas Kecil (Laci Kasir)',
                'account_type'    => 'CURRENT_ASSET',
                'category_label'  => 'Aset Lancar',
                'amount'          => $kasKecil,
                'explanation'     => 'Saldo kas tunai riil yang ada di laci kasir (kas kecil) per tanggal laporan. Dihitung secara berkesinambungan (kontinu) dari modal awal kasir pertama kali ditambah penerimaan tunai, dikurangi pengeluaran laci dan setoran kasir ke kas besar, serta disesuaikan dengan selisih fisik closing shift dan selisih modal antar shift.',
                'formula'         => 'Modal Awal Kasir Pertama Kali + Penjualan Tunai - Pengeluaran Kasir - Setoran Kasir ke Kas Besar + Selisih Tutup Shift + Selisih Modal Antar Shift',
                'components'      => [
                    ['label' => 'Modal Awal Kasir Pertama Kali', 'value' => $modalKasPertama, 'type' => 'info', 'prefix' => '(+)'],
                    ['label' => 'Penjualan Tunai Langsung', 'value' => $directCashSales, 'type' => 'success', 'prefix' => '(+)'],
                    ['label' => 'Pelunasan Kasbon (Tunai)', 'value' => $recCashSales, 'type' => 'success', 'prefix' => '(+)'],
                    ['label' => 'Pengeluaran Kasir (Cash Out)', 'value' => $cashExpense, 'type' => 'danger', 'prefix' => '(-)'],
                    ['label' => 'Setoran Kasir ke Kas Besar', 'value' => $kasKecilSetoran, 'type' => 'warning', 'prefix' => '(-)'],
                    ['label' => 'Akumulasi Selisih Tutup Shift', 'value' => $selisihTutupShiftTotal, 'type' => $selisihTutupShiftTotal >= 0 ? 'success' : 'danger', 'prefix' => '(+/-)'],
                    ['label' => 'Selisih Modal Kasir Antar Shift', 'value' => $selisihAntarShiftTotal, 'type' => $selisihAntarShiftTotal >= 0 ? 'success' : 'danger', 'prefix' => '(+/-)'],
                    ['label' => 'Saldo Akhir Laci Kasir', 'value' => $kasKecil, 'type' => 'primary', 'prefix' => '(=)'],
                ],
                'columns'         => [
                    ['key' => 'date', 'label' => 'Tanggal', 'align' => 'left'],
                    ['key' => 'ref_no', 'label' => 'No. Bukti / Shift', 'align' => 'left'],
                    ['key' => 'category', 'label' => 'Kategori Transaksi', 'align' => 'left'],
                    ['key' => 'description', 'label' => 'Keterangan Audit Laci Kasir', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'user_name', 'label' => 'Kasir / PIC', 'align' => 'left'],
                ],
                'items'           => $items,
                'action_link'     => '/shifts',
                'action_label'    => 'Buka Manajemen Shift Kasir',
            ]);
        }

        // =========================================================================
        // 3. QRIS (1-10003 ATAU AKUN DENGAN NAMA QRIS)
        // =========================================================================
        if ($accountCode === '1-10003' || str_contains(strtoupper($accountCode), 'QRIS') || str_contains(strtoupper($accountName), 'QRIS')) {
            $directQrisSalesQuery = Transaction::where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->where('payment_method', 'like', '%QRIS%')
            ->whereNotIn('payment_method', ['KASBON', 'PIUTANG'])
            ->where('date', '<=', $to);

            if ($outletId) $directQrisSalesQuery->where('outlet_id', $outletId);
            if ($businessId) $directQrisSalesQuery->where('business_id', $businessId);

            $directQrisSales = (float)$directQrisSalesQuery->sum('total_price');

            // Penerimaan QRIS dari Kasbon / Piutang
            $recQrisQuery = ReceivablePayment::with(['receivable'])
                ->whereDate('payment_date', '<=', $to)
                ->where('payment_method', 'like', '%QRIS%')
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->when($businessId, fn($q) => $q->where('business_id', $businessId));

            $recQrisSales = (float)(clone $recQrisQuery)->sum('amount');
            $qrisSales = $directQrisSales + $recQrisSales;

            $directItems = (clone $directQrisSalesQuery)->orderBy('date', 'desc')->orderBy('id', 'desc')->limit(100)->get()->map(function ($t) {
                return [
                    'date'           => $t->date ?: ($t->created_at?->format('Y-m-d H:i') ?: '-'),
                    'order_number'   => $t->order_number ?: "ORD-{$t->id}",
                    'customer_name'  => $t->customer_name ?: 'Pelanggan Umum',
                    'payment_method' => $t->payment_method ?: 'QRIS',
                    'status'         => 'PAID (Langsung)',
                    'amount'         => (float)$t->total_price,
                    'cashier'        => $t->created_by_name ?: '-',
                ];
            });

            $recItems = $recQrisQuery->orderBy('payment_date', 'desc')->limit(100)->get()->map(function ($rp) {
                return [
                    'date'           => $rp->payment_date ?: ($rp->created_at?->format('Y-m-d H:i') ?: '-'),
                    'order_number'   => $rp->payment_no . ($rp->receivable ? " ({$rp->receivable->receivable_no})" : ''),
                    'customer_name'  => $rp->customer_name ?: ($rp->receivable?->customer_name ?: 'Pelanggan Kasbon'),
                    'payment_method' => $rp->payment_method ?: 'QRIS',
                    'status'         => 'LUNAS (Kasbon)',
                    'amount'         => (float)$rp->amount,
                    'cashier'        => $rp->receiver_name ?: ($rp->user?->name ?: '-'),
                ];
            });

            $items = $directItems->concat($recItems)->sortByDesc('date')->values();
            $txCount = $items->count();
            $avgAmount = $txCount > 0 ? round($qrisSales / $txCount, 2) : 0;

            return response()->json([
                'account_code'    => $accountCode ?: '1-10003',
                'account_name'    => $accountName ?: 'QRIS',
                'account_type'    => 'CURRENT_ASSET',
                'category_label'  => 'Aset Lancar',
                'amount'          => $qrisSales,
                'explanation'     => 'Total akumulasi penerimaan penjualan dari kasir POS yang dibayar menggunakan kode pembayaran QRIS, baik dari penjualan langsung maupun pembayaran kasbon via QRIS hingga batas akhir periode laporan.',
                'formula'         => 'QRIS Penjualan Langsung + Pelunasan Kasbon via QRIS',
                'components'      => [
                    ['label' => 'QRIS Penjualan Langsung', 'value' => $directQrisSales, 'type' => 'info', 'format' => 'rupiah'],
                    ['label' => 'QRIS Pelunasan Kasbon', 'value' => $recQrisSales, 'type' => 'warning', 'format' => 'rupiah'],
                    ['label' => 'Total Pendapatan QRIS', 'value' => $qrisSales, 'type' => 'success', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'date', 'label' => 'Tanggal & Waktu', 'align' => 'left'],
                    ['key' => 'order_number', 'label' => 'No. Bukti / Struk', 'align' => 'left'],
                    ['key' => 'customer_name', 'label' => 'Pelanggan', 'align' => 'left'],
                    ['key' => 'payment_method', 'label' => 'Metode', 'align' => 'center'],
                    ['key' => 'status', 'label' => 'Jenis / Status', 'align' => 'center'],
                    ['key' => 'amount', 'label' => 'Total Bayar (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/reports/sales',
                'action_label'    => 'Buka Laporan Penjualan POS',
            ]);
        }

        // =========================================================================
        // 4. BANK TRANSFER / EDC / REKENING BANK (1-10004 ATAU CODE LAINNYA)
        // =========================================================================
        if ($accountCode === '1-10004' || str_contains(strtoupper($accountCode), 'BANK') || str_contains(strtoupper($accountName), 'BANK') || str_contains(strtoupper($accountName), 'TRANSFER')) {
            $directBankSalesQuery = Transaction::where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->where(function ($inner) {
                $inner->where('payment_method', 'like', '%TRANSFER%')
                      ->orWhere('payment_method', 'like', '%DEBIT%')
                      ->orWhere('payment_method', 'like', '%EDC%')
                      ->orWhere('payment_method', 'like', '%BANK%')
                      ->orWhere('payment_method', 'like', '%CREDIT%')
                      ->orWhere('payment_method', 'like', '%KARTU%');
            })
            ->whereNotIn('payment_method', ['KASBON', 'PIUTANG'])
            ->where('date', '<=', $to);
            if ($outletId) $directBankSalesQuery->where('outlet_id', $outletId);
            if ($businessId) $directBankSalesQuery->where('business_id', $businessId);

            $directBankSales = (float)$directBankSalesQuery->sum('total_price');

            // Penerimaan Bank Transfer dari Kasbon / Piutang
            $recBankQuery = ReceivablePayment::with(['receivable'])
                ->whereDate('payment_date', '<=', $to)
                ->where(function ($inner) {
                    $inner->where('payment_method', 'like', '%TRANSFER%')
                          ->orWhere('payment_method', 'like', '%DEBIT%')
                          ->orWhere('payment_method', 'like', '%EDC%')
                          ->orWhere('payment_method', 'like', '%BANK%')
                          ->orWhere('payment_method', 'like', '%CREDIT%')
                          ->orWhere('payment_method', 'like', '%KARTU%');
                })
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->when($businessId, fn($q) => $q->where('business_id', $businessId));

            $recBankSales = (float)(clone $recBankQuery)->sum('amount');
            $bankSales = $directBankSales + $recBankSales;

            // Kas Masuk ke Bank lainnya
            $bankInQuery = CashTransaction::where('type', 'IN')
                ->whereIn('account', ['BANK_MAIN', 'BANK'])
                ->where('date', '<=', $to);
            if ($outletId) $bankInQuery->where('outlet_id', $outletId);
            if ($businessId) $bankInQuery->where('business_id', $businessId);
            $bankInOther = (float)$bankInQuery->sum('amount');

            // Dana Grab yang cair ke Bank
            $grabSettledToBankQuery = CashTransaction::where('type', 'IN')
                ->whereIn('account', ['BANK_MAIN', 'BANK'])
                ->where(function ($q) {
                    $q->where('category', 'like', '%GRAB%')
                      ->orWhere('category', 'like', '%SETTLEMENT%')
                      ->orWhere('category', 'like', '%ECOMMERCE%')
                      ->orWhere('name', 'like', '%Grab%')
                      ->orWhere('name', 'like', '%Settlement%')
                      ->orWhere('name', 'like', '%GoFood%')
                      ->orWhere('name', 'like', '%Shopee%');
                })
                ->where('date', '<=', $to);
            if ($outletId) $grabSettledToBankQuery->where('outlet_id', $outletId);
            if ($businessId) $grabSettledToBankQuery->where('business_id', $businessId);
            $grabSettledToBank = (float)$grabSettledToBankQuery->sum('amount');

            $recGrabPaid = (float)Receivable::where(function ($q) {
                $q->where('ar_type', 'MERCHANT_ECOMMERCE')
                  ->orWhereIn('merchant_channel', ['GRAB', 'GRABFOOD', 'GOFOOD', 'SHOPEE', 'SHOPEEFOOD', 'TIKTOK']);
            })
            ->where('issue_date', '<=', $to)
            ->where(function ($q) {
                $q->where('status', 'PAID')->orWhere('settlement_status', 'SETTLED');
            })
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->sum('paid_amount');

            $totalGrabCairKeBank = max($grabSettledToBank, $recGrabPaid);
            $totalBankIn = $bankSales + max($bankInOther, $totalGrabCairKeBank);

            // Pengeluaran dari Bank
            $bankExpenseQuery = CashTransaction::where('type', 'OUT')
                ->whereIn('account', ['BANK_MAIN', 'BANK'])
                ->where('date', '<=', $to);
            if ($outletId) $bankExpenseQuery->where('outlet_id', $outletId);
            if ($businessId) $bankExpenseQuery->where('business_id', $businessId);
            $bankExpense = (float)$bankExpenseQuery->sum('amount');

            $bankNet = max(0, round($totalBankIn - $bankExpense, 2));

            // List of Cash Transactions & Kasbon Payments for Bank
            $bankTxList = CashTransaction::whereIn('account', ['BANK_MAIN', 'BANK'])
                ->where('date', '<=', $to)
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->orderBy('date', 'desc')
                ->orderBy('id', 'desc')
                ->limit(100)
                ->get();

            $bankTxItems = $bankTxList->map(function ($t) {
                return [
                    'date'        => $t->date?->format('Y-m-d') ?: (string)$t->date,
                    'ref_no'      => $t->transaction_no ?: "BNK-{$t->id}",
                    'category'    => $t->category_label ?: $t->category,
                    'description' => $t->name ?: ($t->notes ?: '-'),
                    'direction'   => $t->type === 'IN' ? 'MASUK' : 'KELUAR',
                    'amount'      => (float)$t->amount,
                    'user_name'   => $t->user_name ?: '-',
                ];
            });

            $recBankItems = $recBankQuery->orderBy('payment_date', 'desc')->limit(100)->get()->map(function ($rp) {
                return [
                    'date'        => $rp->payment_date ?: ($rp->created_at?->format('Y-m-d H:i') ?: '-'),
                    'ref_no'      => $rp->payment_no . ($rp->receivable ? " ({$rp->receivable->receivable_no})" : ''),
                    'category'    => 'Pelunasan Kasbon',
                    'description' => 'Pembayaran Kasbon: ' . ($rp->customer_name ?: ($rp->receivable?->customer_name ?: 'Pelanggan')),
                    'direction'   => 'MASUK',
                    'amount'      => (float)$rp->amount,
                    'user_name'   => $rp->receiver_name ?: ($rp->user?->name ?: '-'),
                ];
            });

            $items = $bankTxItems->concat($recBankItems)->sortByDesc('date')->values();

            return response()->json([
                'account_code'    => $accountCode ?: '1-10004',
                'account_name'    => $accountName ?: 'Bank Transfer & Debit EDC',
                'account_type'    => 'CURRENT_ASSET',
                'category_label'  => 'Aset Lancar',
                'amount'          => $bankNet,
                'explanation'     => 'Saldo bersih kas rekening bank bisnis dari penerimaan transaksi penjualan Transfer/Debit/EDC kasir serta seluruh setoran tunai dan pencairan dana platform online (Grab/GoFood) ke rekening bank, dikurangi pengeluaran operasional yang dibayar lewat rekening bank.',
                'formula'         => 'Penjualan Transfer/EDC + Kas Masuk & Pencairan Bank - Pengeluaran via Bank',
                'components'      => [
                    ['label' => 'Penjualan Transfer / EDC Kasir', 'value' => $bankSales, 'type' => 'success', 'prefix' => '(+)'],
                    ['label' => 'Setoran & Pencairan Masuk Bank', 'value' => max($bankInOther, $totalGrabCairKeBank), 'type' => 'info', 'prefix' => '(+)'],
                    ['label' => 'Pengeluaran Operasional via Bank', 'value' => $bankExpense, 'type' => 'danger', 'prefix' => '(-)'],
                    ['label' => 'Saldo Bersih Rekening Bank', 'value' => $bankNet, 'type' => 'primary', 'prefix' => '(=)'],
                ],
                'columns'         => [
                    ['key' => 'date', 'label' => 'Tanggal', 'align' => 'left'],
                    ['key' => 'ref_no', 'label' => 'No. Ref', 'align' => 'left'],
                    ['key' => 'category', 'label' => 'Kategori', 'align' => 'left'],
                    ['key' => 'description', 'label' => 'Keterangan', 'align' => 'left'],
                    ['key' => 'direction', 'label' => 'Arus', 'align' => 'center'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'user_name', 'label' => 'Kasir / Petugas', 'align' => 'left'],
                ],
                'items'           => $items,
                'action_link'     => '/cash-flow',
                'action_label'    => 'Buka Buku Kas & Arus Kas',
            ]);
        }

        // =========================================================================
        // 5. PIUTANG USAHA / KASBON PELANGGAN (1-10100)
        // =========================================================================
        if ($accountCode === '1-10100') {
            $recCustomerQuery = Receivable::where('status', '!=', 'PAID')
                ->where('issue_date', '<=', $to)
                ->where(function ($q) {
                    $q->where('ar_type', 'CUSTOMER')
                      ->orWhereNull('ar_type')
                      ->orWhere(function ($sub) {
                          $sub->where('ar_type', '!=', 'MERCHANT_ECOMMERCE')
                              ->where(function ($inner) {
                                  $inner->whereNull('merchant_channel')
                                        ->orWhereNotIn('merchant_channel', ['GRAB', 'GRABFOOD', 'GOFOOD', 'SHOPEE', 'SHOPEEFOOD', 'TIKTOK', 'ONLINE_DELIVERY']);
                              });
                      });
                });

            if ($outletId) $recCustomerQuery->where('outlet_id', $outletId);
            if ($businessId) $recCustomerQuery->where('business_id', $businessId);

            $piutangUsaha = (float)$recCustomerQuery->sum('remaining_amount');
            $totalAmount = (float)(clone $recCustomerQuery)->sum('total_amount');
            $paidAmount = (float)(clone $recCustomerQuery)->sum('paid_amount');
            $count = (clone $recCustomerQuery)->count();

            $receivables = (clone $recCustomerQuery)->orderBy('remaining_amount', 'desc')->limit(250)->get();
            $items = $receivables->map(function ($r) {
                return [
                    'receivable_no'    => $r->receivable_no,
                    'issue_date'       => $r->issue_date?->format('Y-m-d') ?: (string)$r->issue_date,
                    'due_date'         => $r->due_date?->format('Y-m-d') ?: ($r->due_date ? (string)$r->due_date : '-'),
                    'customer_name'    => $r->customer_name ?: 'Pelanggan Umum',
                    'customer_phone'   => $r->customer_phone ?: '-',
                    'status'           => $r->status ?: 'UNPAID',
                    'status_label'     => $r->status_label ?: ($r->status === 'PARTIAL' ? 'Sebagian' : 'Belum Lunas'),
                    'total_amount'     => (float)$r->total_amount,
                    'paid_amount'      => (float)$r->paid_amount,
                    'remaining_amount' => (float)$r->remaining_amount,
                    'notes'            => $r->notes ?: '-',
                ];
            });

            return response()->json([
                'account_code'    => '1-10100',
                'account_name'    => 'Piutang Usaha (Pelanggan / Kasbon)',
                'account_type'    => 'CURRENT_ASSET',
                'category_label'  => 'Aset Lancar',
                'amount'          => $piutangUsaha,
                'explanation'     => 'Total saldo tagihan kasbon atau piutang pelanggan yang berstatus aktif (belum lunas atau baru dicicil sebagian) hingga batas akhir tanggal laporan neraca. Nilai ini mencerminkan hak penerimaan kas yang masih berada di pihak pelanggan.',
                'formula'         => 'Total Tagihan Kasbon Diterbitkan - Total Cicilan / Pelunasan yang Sudah Diterima',
                'components'      => [
                    ['label' => 'Total Nota Kasbon Aktif', 'value' => $count, 'type' => 'info', 'format' => 'number'],
                    ['label' => 'Total Nilai Tagihan Kasbon', 'value' => $totalAmount, 'type' => 'warning', 'format' => 'rupiah'],
                    ['label' => 'Total Cicilan Diterima', 'value' => $paidAmount, 'type' => 'success', 'format' => 'rupiah'],
                    ['label' => 'Sisa Piutang Berjalan', 'value' => $piutangUsaha, 'type' => 'primary', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'receivable_no', 'label' => 'No. Nota Kasbon', 'align' => 'left'],
                    ['key' => 'issue_date', 'label' => 'Tgl Nota', 'align' => 'left'],
                    ['key' => 'customer_name', 'label' => 'Pelanggan / Peminjam', 'align' => 'left'],
                    ['key' => 'customer_phone', 'label' => 'No. Telepon', 'align' => 'left'],
                    ['key' => 'status_label', 'label' => 'Status', 'align' => 'center'],
                    ['key' => 'total_amount', 'label' => 'Total Kasbon (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'paid_amount', 'label' => 'Sudah Bayar (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'remaining_amount', 'label' => 'Sisa Kasbon (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/receivables',
                'action_label'    => 'Buka Buku Piutang & Kasbon',
            ]);
        }

        // =========================================================================
        // 6. PIUTANG GRAB & MITRA ONLINE (1-10150)
        // =========================================================================
        if ($accountCode === '1-10150') {
            $grabSalesQuery = Transaction::where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->where(function ($q) {
                $q->where('payment_method', 'like', '%GRAB%')
                  ->orWhere('payment_method', 'like', '%GOFOOD%')
                  ->orWhere('payment_method', 'like', '%SHOPEE%')
                  ->orWhere('payment_method', 'like', '%TIKTOK%');
            })
            ->where('date', '<=', $to);
            if ($outletId) $grabSalesQuery->where('outlet_id', $outletId);
            if ($businessId) $grabSalesQuery->where('business_id', $businessId);
            $grabSalesTotal = (float)$grabSalesQuery->sum('total_price');

            $grabSettledToBankQuery = CashTransaction::where('type', 'IN')
                ->whereIn('account', ['BANK_MAIN', 'BANK'])
                ->where(function ($q) {
                    $q->where('category', 'like', '%GRAB%')
                      ->orWhere('category', 'like', '%SETTLEMENT%')
                      ->orWhere('category', 'like', '%ECOMMERCE%')
                      ->orWhere('name', 'like', '%Grab%')
                      ->orWhere('name', 'like', '%Settlement%')
                      ->orWhere('name', 'like', '%GoFood%')
                      ->orWhere('name', 'like', '%Shopee%');
                })
                ->where('date', '<=', $to);
            if ($outletId) $grabSettledToBankQuery->where('outlet_id', $outletId);
            if ($businessId) $grabSettledToBankQuery->where('business_id', $businessId);
            $grabSettledToBank = (float)$grabSettledToBankQuery->sum('amount');

            $recGrabPaid = (float)Receivable::where(function ($q) {
                $q->where('ar_type', 'MERCHANT_ECOMMERCE')
                  ->orWhereIn('merchant_channel', ['GRAB', 'GRABFOOD', 'GOFOOD', 'SHOPEE', 'SHOPEEFOOD', 'TIKTOK']);
            })
            ->where('issue_date', '<=', $to)
            ->where(function ($q) {
                $q->where('status', 'PAID')->orWhere('settlement_status', 'SETTLED');
            })
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->sum('paid_amount');

            $totalGrabCairKeBank = max($grabSettledToBank, $recGrabPaid);
            $piutangGrab = max(0, round($grabSalesTotal - $totalGrabCairKeBank, 2));

            $grabTransactions = (clone $grabSalesQuery)->orderBy('date', 'desc')->orderBy('id', 'desc')->limit(150)->get();
            $items = $grabTransactions->map(function ($t) {
                return [
                    'date'           => $t->date ?: ($t->created_at?->format('Y-m-d') ?: '-'),
                    'order_number'   => $t->order_number ?: "ORD-{$t->id}",
                    'channel'        => $t->payment_method ?: 'ONLINE_DELIVERY',
                    'customer_name'  => $t->customer_name ?: 'Pelanggan Online',
                    'amount'         => (float)$t->total_price,
                    'status'         => $t->status ?: 'PAID',
                ];
            });

            return response()->json([
                'account_code'    => '1-10150',
                'account_name'    => 'Piutang Grab & Mitra Online (Belum Cair)',
                'account_type'    => 'CURRENT_ASSET',
                'category_label'  => 'Aset Lancar',
                'amount'          => $piutangGrab,
                'explanation'     => 'Estimasi nilai pendapatan transaksi dari platform pesan antar online (GrabFood, GoFood, ShopeeFood, TikTok Shop) yang telah selesai transaksinya di sistem kasir namun dananya belum dicairkan / ditransfer oleh pihak platform ke rekening bank bisnis.',
                'formula'         => 'Total Penjualan POS Platform Online - Total Pencairan Dana yang Sudah Masuk ke Rekening Bank',
                'components'      => [
                    ['label' => 'Total Penjualan Online POS', 'value' => $grabSalesTotal, 'type' => 'info', 'format' => 'rupiah'],
                    ['label' => 'Total Dana Sudah Cair ke Bank', 'value' => $totalGrabCairKeBank, 'type' => 'success', 'format' => 'rupiah'],
                    ['label' => 'Sisa Piutang Online Belum Cair', 'value' => $piutangGrab, 'type' => 'warning', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'date', 'label' => 'Tanggal', 'align' => 'left'],
                    ['key' => 'order_number', 'label' => 'No. Struk', 'align' => 'left'],
                    ['key' => 'channel', 'label' => 'Platform / Channel', 'align' => 'center'],
                    ['key' => 'customer_name', 'label' => 'Keterangan Order', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal Penjualan (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/receivables',
                'action_label'    => 'Buka Rekonsiliasi E-Commerce',
            ]);
        }

        // =========================================================================
        // 7. PERSEDIAAN BAHAN (1-10200) & PERSEDIAAN PERLENGKAPAN (1-10210)
        // =========================================================================
        if ($accountCode === '1-10200' || $accountCode === '1-10210') {
            $isTargetPerlengkapan = ($accountCode === '1-10210');

            $ingQuery = Ingredient::where('active', true);
            if ($businessId) {
                $ingQuery->where('business_id', $businessId);
            }
            $ingredients = $ingQuery->with(['outletIngredients', 'categoryModel'])->get();

            $rawItems = [];
            $totalPersediaan = 0.0;

            foreach ($ingredients as $ing) {
                $cat = strtolower((string)($ing->category ?: ($ing->categoryModel?->name ?? '')));
                $name = strtolower((string)$ing->name);
                $code = strtoupper((string)$ing->code);

                $isPerlengkapan = (
                    str_contains($cat, 'perlengkapan') ||
                    str_contains($cat, 'packaging') ||
                    str_contains($cat, 'kemasan') ||
                    str_contains($cat, 'cup') ||
                    str_contains($cat, 'pipet') ||
                    str_contains($cat, 'sedotan') ||
                    str_contains($cat, 'tissue') ||
                    str_contains($cat, 'tisu') ||
                    str_contains($cat, 'sealer') ||
                    str_contains($cat, 'kantong') ||
                    str_contains($cat, 'kresek') ||
                    str_contains($cat, 'paperbag') ||
                    str_contains($cat, 'box') ||
                    str_contains($cat, 'sendok') ||
                    str_contains($cat, 'garpu') ||
                    str_contains($name, 'cup') ||
                    str_contains($name, 'pipet') ||
                    str_contains($name, 'sedotan') ||
                    str_contains($name, 'tissue') ||
                    str_contains($name, 'tisu') ||
                    str_contains($name, 'sealer') ||
                    str_contains($name, 'kantong') ||
                    str_contains($name, 'kresek') ||
                    str_contains($name, 'paperbag') ||
                    str_contains($name, 'box') ||
                    str_contains($name, 'sendok') ||
                    str_contains($name, 'garpu') ||
                    str_starts_with($code, 'PLK-') ||
                    str_starts_with($code, 'PKG-')
                );

                if ($isTargetPerlengkapan && !$isPerlengkapan) continue;
                if (!$isTargetPerlengkapan && $isPerlengkapan) continue;

                $stock = $outletId ? $ing->stockForOutlet($outletId) : $ing->consolidatedStock();
                $unitCost = $ing->costPerPakaiForOutlet($outletId);
                $itemValue = max(0, round($stock * $unitCost, 2));

                $totalPersediaan += $itemValue;

                $rawItems[] = [
                    'code'          => $ing->code ?: "ING-{$ing->id}",
                    'name'          => $ing->name,
                    'category'      => $ing->category ?: ($ing->categoryModel?->name ?? 'Umum'),
                    'stock'         => (float)$stock,
                    'unit'          => $ing->unit ?: 'pcs',
                    'unit_cost'     => (float)$unitCost,
                    'total_value'   => (float)$itemValue,
                ];
            }

            // Direct Menus into Persediaan Bahan
            if (!$isTargetPerlengkapan) {
                $menuQuery = Menu::where('item_type', 'DIRECT')->where('active', true);
                if ($businessId) $menuQuery->where('business_id', $businessId);
                $directMenus = $menuQuery->get();
                foreach ($directMenus as $m) {
                    $val = round((float)$m->current_stock * (float)$m->cost_price, 2);
                    $totalPersediaan += $val;
                    $rawItems[] = [
                        'code'        => $m->code ?: "MNU-{$m->id}",
                        'name'        => "{$m->name} (Barang Jadi)",
                        'category'    => 'Barang Retail / Direct',
                        'stock'       => (float)$m->current_stock,
                        'unit'        => 'pcs',
                        'unit_cost'   => (float)$m->cost_price,
                        'total_value' => (float)$val,
                    ];
                }
            }

            // Sort by total_value descending
            usort($rawItems, fn($a, $b) => $b['total_value'] <=> $a['total_value']);

            $accLabel = $isTargetPerlengkapan ? 'Persediaan Perlengkapan' : 'Persediaan Bahan';
            $accExplain = $isTargetPerlengkapan
                ? 'Total nilai aset fisik perlengkapan, packaging, cup, kresek, sealer, dan alat operasional sekali pakai yang aktif per tanggal laporan. Dihitung dari stok fisik dikalikan harga pokok per unit.'
                : 'Total nilai aset fisik seluruh bahan baku makanan dan minuman yang aktif per tanggal laporan neraca. Dihitung secara teliti dari kuantitas stok fisik dikalikan harga pokok (HPP / cost per unit) masing-masing bahan.';

            return response()->json([
                'account_code'    => $accountCode,
                'account_name'    => $accLabel,
                'account_type'    => 'CURRENT_ASSET',
                'category_label'  => 'Aset Lancar',
                'amount'          => round($totalPersediaan, 2),
                'explanation'     => $accExplain,
                'formula'         => '∑ (Stok Fisik per Item × Harga Pokok per Unit / HPP)',
                'components'      => [
                    ['label' => 'Total Jenis Item', 'value' => count($rawItems), 'type' => 'info', 'format' => 'number'],
                    ['label' => 'Item Nilai Tertinggi', 'value' => $rawItems[0]['name'] ?? '-', 'type' => 'warning', 'format' => 'string'],
                    ['label' => 'Total Nilai Aset Persediaan', 'value' => round($totalPersediaan, 2), 'type' => 'success', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'code', 'label' => 'Kode', 'align' => 'left'],
                    ['key' => 'name', 'label' => 'Nama Item', 'align' => 'left'],
                    ['key' => 'category', 'label' => 'Kategori', 'align' => 'left'],
                    ['key' => 'stock', 'label' => 'Stok Fisik', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'unit', 'label' => 'Satuan', 'align' => 'center'],
                    ['key' => 'unit_cost', 'label' => 'Harga Pokok (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'total_value', 'label' => 'Total Nilai (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $rawItems,
                'action_link'     => $isTargetPerlengkapan ? '/master/perlengkapan' : '/inventory/stock',
                'action_label'    => $isTargetPerlengkapan ? 'Buka Master Perlengkapan' : 'Buka Kartu Stok / Bahan',
            ]);
        }

        // =========================================================================
        // 8. HUTANG USAHA / SUPPLIER (2-20100)
        // =========================================================================
        if ($accountCode === '2-20100') {
            $payQuery = Payable::where('status', '!=', 'PAID')
                ->where('issue_date', '<=', $to);
            if ($outletId) $payQuery->where('outlet_id', $outletId);
            if ($businessId) $payQuery->where('business_id', $businessId);

            $hutangUsaha = (float)$payQuery->sum('remaining_amount');
            $totalAmount = (float)(clone $payQuery)->sum('total_amount');
            $paidAmount = (float)(clone $payQuery)->sum('paid_amount');
            $count = (clone $payQuery)->count();

            $payables = (clone $payQuery)->orderBy('remaining_amount', 'desc')->limit(250)->get();
            $items = $payables->map(function ($p) {
                return [
                    'payable_no'       => $p->payable_no,
                    'purchase_no'      => $p->purchase_no ?: '-',
                    'issue_date'       => $p->issue_date?->format('Y-m-d') ?: (string)$p->issue_date,
                    'due_date'         => $p->due_date?->format('Y-m-d') ?: ($p->due_date ? (string)$p->due_date : '-'),
                    'supplier_name'    => $p->supplier_name ?: 'Supplier / Vendor',
                    'status_label'     => $p->status_label ?: ($p->status === 'PARTIAL' ? 'Sebagian' : 'Belum Lunas'),
                    'total_amount'     => (float)$p->total_amount,
                    'paid_amount'      => (float)$p->paid_amount,
                    'remaining_amount' => (float)$p->remaining_amount,
                    'notes'            => $p->notes ?: '-',
                ];
            });

            return response()->json([
                'account_code'    => '2-20100',
                'account_name'    => 'Hutang Usaha / Supplier',
                'account_type'    => 'LIABILITY',
                'category_label'  => 'Liabilitas (Kewajiban)',
                'amount'          => $hutangUsaha,
                'explanation'     => 'Total sisa kewajiban atau hutang pembelian bahan baku, perlengkapan, dan operasional kepada supplier / vendor yang belum diselesaikan pembayarannya hingga tanggal laporan neraca.',
                'formula'         => 'Total Faktur Pembelian Tempo - Total Pembayaran / Cicilan Hutang yang Telah Dibayar',
                'components'      => [
                    ['label' => 'Total Faktur Hutang Aktif', 'value' => $count, 'type' => 'info', 'format' => 'number'],
                    ['label' => 'Total Nilai Faktur Pembelian', 'value' => $totalAmount, 'type' => 'warning', 'format' => 'rupiah'],
                    ['label' => 'Total Pembayaran Dilakukan', 'value' => $paidAmount, 'type' => 'success', 'format' => 'rupiah'],
                    ['label' => 'Sisa Kewajiban Hutang', 'value' => $hutangUsaha, 'type' => 'danger', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'payable_no', 'label' => 'No. Hutang', 'align' => 'left'],
                    ['key' => 'purchase_no', 'label' => 'No. Faktur Beli', 'align' => 'left'],
                    ['key' => 'issue_date', 'label' => 'Tgl Faktur', 'align' => 'left'],
                    ['key' => 'due_date', 'label' => 'Jatuh Tempo', 'align' => 'left'],
                    ['key' => 'supplier_name', 'label' => 'Supplier / Vendor', 'align' => 'left'],
                    ['key' => 'status_label', 'label' => 'Status', 'align' => 'center'],
                    ['key' => 'total_amount', 'label' => 'Total Tagihan (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'paid_amount', 'label' => 'Sudah Bayar (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'remaining_amount', 'label' => 'Sisa Hutang (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/payables',
                'action_label'    => 'Buka Buku Hutang Usaha',
            ]);
        }

        // =========================================================================
        // 9. HUTANG POKOK PINJAMAN (2-20200)
        // =========================================================================
        if ($accountCode === '2-20200') {
            $loanIn = (float)CashTransaction::where('category', 'LOAN_RECEIPT')
                ->where('date', '<=', $to)
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->sum('amount');
            $loanOut = (float)CashTransaction::where('category', 'LOAN_REPAYMENT')
                ->where('date', '<=', $to)
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->sum('amount');
            $hutangLain = max(0, round($loanIn - $loanOut, 2));

            $loanTransactions = CashTransaction::whereIn('category', ['LOAN_RECEIPT', 'LOAN_REPAYMENT'])
                ->where('date', '<=', $to)
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->orderBy('date', 'desc')
                ->get();

            $items = $loanTransactions->map(function ($t) {
                return [
                    'date'        => $t->date?->format('Y-m-d') ?: (string)$t->date,
                    'ref_no'      => $t->transaction_no ?: "LOAN-{$t->id}",
                    'category'    => $t->category === 'LOAN_RECEIPT' ? 'Penerimaan Pinjaman' : 'Angsuran / Pelunasan Pinjaman',
                    'description' => $t->name ?: ($t->notes ?: '-'),
                    'amount'      => (float)$t->amount,
                ];
            });

            return response()->json([
                'account_code'    => '2-20200',
                'account_name'    => 'Hutang Pokok Pinjaman',
                'account_type'    => 'LIABILITY',
                'category_label'  => 'Liabilitas (Kewajiban)',
                'amount'          => $hutangLain,
                'explanation'     => 'Sisa pokok pinjaman dana modal usaha dari perbankan atau pihak ketiga (Penerimaan pinjaman dikurangi pembayaran angsuran pokok pinjaman).',
                'formula'         => 'Total Pinjaman Diterima (Loan Receipt) - Total Angsuran Pokok (Loan Repayment)',
                'components'      => [
                    ['label' => 'Total Pinjaman Diterima', 'value' => $loanIn, 'type' => 'info', 'format' => 'rupiah'],
                    ['label' => 'Total Angsuran Pokok Dibayar', 'value' => $loanOut, 'type' => 'success', 'format' => 'rupiah'],
                    ['label' => 'Sisa Pokok Pinjaman', 'value' => $hutangLain, 'type' => 'danger', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'date', 'label' => 'Tanggal', 'align' => 'left'],
                    ['key' => 'ref_no', 'label' => 'No. Ref', 'align' => 'left'],
                    ['key' => 'category', 'label' => 'Jenis Transaksi', 'align' => 'left'],
                    ['key' => 'description', 'label' => 'Keterangan', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/cash-flow',
                'action_label'    => 'Buka Buku Kas & Arus Kas',
            ]);
        }

        // =========================================================================
        // 10. MODAL PEMILIK / DISETOR (3-30001)
        // =========================================================================
        if ($accountCode === '3-30001') {
            $biz = $businessId ? Business::find($businessId) : Business::first();
            $customModal = null;
            if ($biz && is_array($biz->settings) && isset($biz->settings['modal_pemilik'])) {
                $customModal = (float)$biz->settings['modal_pemilik'];
            }

            // 1. Modal Kas Kecil Waktu Pertama Kali (Shift Perdana saat entitas bisnis pertama kali dibuka)
            $firstShiftList = [];
            $modalKasPertama = 0.0;
            if ($outletId) {
                $firstShift = Shift::with('outlet')
                    ->where('outlet_id', $outletId)
                    ->whereDate('opened_at', '<=', $to)
                    ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                    ->orderBy('opened_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->first();
                if ($firstShift) {
                    $modalKasPertama = (float)$firstShift->initial_cash;
                    $firstShiftList[] = $firstShift;
                }
            } else {
                $activeOutlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))
                    ->where('active', true)
                    ->pluck('id');
                foreach ($activeOutlets as $oId) {
                    $fs = Shift::with('outlet')
                        ->where('outlet_id', $oId)
                        ->whereDate('opened_at', '<=', $to)
                        ->orderBy('opened_at', 'asc')
                        ->orderBy('id', 'asc')
                        ->first();
                    if ($fs) {
                        $modalKasPertama += (float)$fs->initial_cash;
                        $firstShiftList[] = $fs;
                    }
                }
                if ($modalKasPertama == 0 && empty($firstShiftList)) {
                    $fs = Shift::with('outlet')
                        ->whereDate('opened_at', '<=', $to)
                        ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                        ->orderBy('opened_at', 'asc')
                        ->orderBy('id', 'asc')
                        ->first();
                    if ($fs) {
                        $modalKasPertama = (float)$fs->initial_cash;
                        $firstShiftList[] = $fs;
                    }
                }
            }

            // 2. Setoran Modal Kas / Bank Pemilik (Capital Injection eksplisit)
            $capTransactions = CashTransaction::where('category', 'CAPITAL_INJECTION')
                ->where('date', '<=', $to)
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->get();
            $modalDisetorExplicit = (float)$capTransactions->sum('amount');

            // 3. Saldo Awal Persediaan Bahan & Perlengkapan Fisik
            $ingQuery = Ingredient::where('active', true);
            if ($businessId) {
                $ingQuery->where('business_id', $businessId);
            }
            $ingredients = $ingQuery->with(['outletIngredients', 'categoryModel'])->get();

            $modalAwalBahanTotal = 0.0;
            $modalAwalPerlengkapanTotal = 0.0;
            $inventoryInitialItems = [];

            foreach ($ingredients as $ing) {
                $cat = strtolower((string)($ing->category ?: ($ing->categoryModel?->name ?? '')));
                $name = (string)$ing->name;
                $code = strtoupper((string)$ing->code);

                $isPerlengkapan = (
                    str_contains($cat, 'perlengkapan') ||
                    str_contains($cat, 'packaging') ||
                    str_contains($cat, 'kemasan') ||
                    str_contains($cat, 'cup') ||
                    str_contains($cat, 'pipet') ||
                    str_contains($cat, 'sedotan') ||
                    str_contains($cat, 'tissue') ||
                    str_contains($cat, 'tisu') ||
                    str_contains($cat, 'sealer') ||
                    str_contains($cat, 'kantong') ||
                    str_contains($cat, 'kresek') ||
                    str_contains($cat, 'paperbag') ||
                    str_contains($cat, 'box') ||
                    str_contains($cat, 'sendok') ||
                    str_contains($cat, 'garpu') ||
                    str_starts_with($code, 'PLK-') ||
                    str_starts_with($code, 'PKG-')
                );

                $unitCost = $ing->costPerPakaiForOutlet($outletId);

                $outletRow = $outletId ? $ing->outletIngredients->firstWhere('outlet_id', $outletId) : $ing->outletIngredients->first();
                $stokAwalMaster = 0.0;
                $saldoAwalNominalMaster = 0.0;
                if ($outletRow && (float)$outletRow->stok_awal > 0) {
                    $stokAwalMaster = (float)$outletRow->stok_awal;
                    $saldoAwalNominalMaster = (float)($outletRow->saldo_awal_nominal ?? 0);
                } elseif ($ing->stok_awal > 0) {
                    $stokAwalMaster = (float)$ing->stok_awal;
                    $saldoAwalNominalMaster = (float)($ing->saldo_awal_nominal ?? 0);
                }
                if ($saldoAwalNominalMaster <= 0 && $stokAwalMaster > 0) {
                    $saldoAwalNominalMaster = round($stokAwalMaster * $unitCost, 2);
                }

                if ($saldoAwalNominalMaster > 0) {
                    $itemCatLabel = $isPerlengkapan ? 'Persediaan Perlengkapan' : 'Persediaan Bahan Baku';
                    if ($isPerlengkapan) {
                        $modalAwalPerlengkapanTotal += $saldoAwalNominalMaster;
                    } else {
                        $modalAwalBahanTotal += $saldoAwalNominalMaster;
                    }

                    $unitLabel = $ing->unit_pakai ?: ($ing->unit_beli ?: 'Unit');
                    $inventoryInitialItems[] = [
                        'source'      => $isPerlengkapan ? 'Master Perlengkapan' : 'Master Bahan Baku',
                        'category'    => $itemCatLabel,
                        'description' => "Saldo Awal: {$name} ({$stokAwalMaster} {$unitLabel} @ Rp " . number_format($unitCost, 0, ',', '.') . ")",
                        'amount'      => round($saldoAwalNominalMaster, 2),
                    ];
                }
            }

            // Direct Menus opening stock (if any)
            $menuQuery = Menu::where('item_type', 'DIRECT')->where('active', true);
            if ($businessId) {
                $menuQuery->where('business_id', $businessId);
            }
            $directMenus = $menuQuery->get();
            foreach ($directMenus as $m) {
                $stk = (float)($m->initial_stock ?? $m->current_stock ?? 0);
                $cost = (float)$m->cost_price;
                if ($stk > 0 && $cost > 0) {
                    $val = round($stk * $cost, 2);
                    $modalAwalBahanTotal += $val;
                    $inventoryInitialItems[] = [
                        'source'      => 'Master Menu (Retail)',
                        'category'    => 'Persediaan Barang Jadi',
                        'description' => "Saldo Awal: {$m->name} ({$stk} Pcs @ Rp " . number_format($cost, 0, ',', '.') . ")",
                        'amount'      => $val,
                    ];
                }
            }

            $modalAwalPersediaanTotal = round($modalAwalBahanTotal + $modalAwalPerlengkapanTotal, 2);

            // Hitung Modal Pemilik Tetap
            if ($customModal !== null && $customModal > 0) {
                $modalPemilikTetap = round($customModal, 2);
            } elseif ($modalDisetorExplicit > 0 && ($modalKasPertama + $modalAwalPersediaanTotal) == 0) {
                $modalPemilikTetap = round($modalDisetorExplicit, 2);
            } else {
                $modalPemilikTetap = round($modalKasPertama + $modalDisetorExplicit + $modalAwalPersediaanTotal, 2);
            }

            // Susun Daftar Items Rincian Audit Trail
            $items = [];

            // A. Kas Kecil Waktu Pertama Kali
            if (!empty($firstShiftList)) {
                foreach ($firstShiftList as $fs) {
                    $shiftDateStr = $fs->opened_at ? date('Y-m-d', strtotime($fs->opened_at)) : 'Shift Perdana';
                    $outletNameStr = $fs->outlet?->name ?: "Outlet #{$fs->outlet_id}";
                    $items[] = [
                        'source'      => $shiftDateStr,
                        'category'    => 'Kas Kecil (Laci Kasir)',
                        'description' => "Modal Kasir Pertama Kali (Shift Perdana #{$fs->id} - {$fs->shift_name}) [{$outletNameStr}]",
                        'amount'      => (float)$fs->initial_cash,
                    ];
                }
            } elseif ($modalKasPertama > 0) {
                $items[] = [
                    'source'      => 'Shift Perdana',
                    'category'    => 'Kas Kecil (Laci Kasir)',
                    'description' => 'Modal Kasir Pertama Kali saat awal operasional toko dimulai',
                    'amount'      => $modalKasPertama,
                ];
            }

            // B. Setoran Modal Kas / Bank Pemilik
            foreach ($capTransactions as $ct) {
                $items[] = [
                    'source'      => $ct->date?->format('Y-m-d') ?: (string)$ct->date,
                    'category'    => 'Kas Besar / Bank',
                    'description' => $ct->name ?: ($ct->notes ?: 'Setoran Modal Pemilik ke Kas/Bank'),
                    'amount'      => (float)$ct->amount,
                ];
            }

            // C. Saldo Awal Persediaan Fisik (Bahan & Perlengkapan)
            foreach ($inventoryInitialItems as $invItem) {
                $items[] = $invItem;
            }

            // Jika custom modal disetor aktif di pengaturan bisnis
            if ($customModal !== null && $customModal > 0) {
                array_unshift($items, [
                    'source'      => 'Pengaturan Bisnis',
                    'category'    => 'Modal Disetor Tetap',
                    'description' => 'Nilai modal disetor tetap yang dikonfigurasi pada profil bisnis.',
                    'amount'      => $modalPemilikTetap,
                ]);
            }

            // Susun Komponen Kartu Atas
            $components = [];
            if ($modalKasPertama > 0) {
                $components[] = [
                    'label'  => 'Modal Kas Kecil Pertama Kali (Shift Perdana)',
                    'value'  => round($modalKasPertama, 2),
                    'type'   => 'info',
                    'prefix' => '(+)',
                ];
            }
            if ($modalAwalBahanTotal > 0) {
                $components[] = [
                    'label'  => 'Saldo Awal Persediaan Bahan Baku',
                    'value'  => round($modalAwalBahanTotal, 2),
                    'type'   => 'success',
                    'prefix' => '(+)',
                ];
            }
            if ($modalAwalPerlengkapanTotal > 0) {
                $components[] = [
                    'label'  => 'Saldo Awal Persediaan Perlengkapan',
                    'value'  => round($modalAwalPerlengkapanTotal, 2),
                    'type'   => 'warning',
                    'prefix' => '(+)',
                ];
            }
            if ($modalDisetorExplicit > 0) {
                $components[] = [
                    'label'  => 'Setoran Modal Pemilik (Kas/Bank)',
                    'value'  => round($modalDisetorExplicit, 2),
                    'type'   => 'info',
                    'prefix' => '(+)',
                ];
            }
            $components[] = [
                'label'  => 'Total Modal Pemilik / Disetor',
                'value'  => round($modalPemilikTetap, 2),
                'type'   => 'primary',
                'prefix' => '(=)',
            ];

            return response()->json([
                'account_code'    => '3-30001',
                'account_name'    => 'Modal Pemilik / Disetor',
                'account_type'    => 'EQUITY',
                'category_label'  => 'Modal & Ekuitas',
                'amount'          => $modalPemilikTetap,
                'explanation'     => 'Modal awal yang disetorkan oleh pemilik usaha saat entitas bisnis pertama kali beroperasi, mencakup modal kas kecil laci kasir perdana, saldo awal persediaan bahan baku, saldo awal perlengkapan/packaging, dan setoran modal tunai/bank pemilik.',
                'formula'         => 'Modal Kas Kecil Pertama Kali + Saldo Awal Persediaan Bahan + Saldo Awal Persediaan Perlengkapan + Setoran Kas/Bank Pemilik',
                'components'      => $components,
                'columns'         => [
                    ['key' => 'source', 'label' => 'Sumber / Tanggal', 'align' => 'left'],
                    ['key' => 'category', 'label' => 'Kategori Akun', 'align' => 'left'],
                    ['key' => 'description', 'label' => 'Keterangan Modal & Saldo Awal', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal Modal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/settings/business',
                'action_label'    => 'Buka Pengaturan Bisnis',
            ]);
        }

        // =========================================================================
        // 11. PRIVE PEMILIK (3-30002)
        // =========================================================================
        if ($accountCode === '3-30002') {
            $priveQuery = CashTransaction::where('category', 'OWNER_WITHDRAWAL')
                ->where('date', '<=', $to)
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId));

            $prive = (float)$priveQuery->sum('amount');
            $priveTx = (clone $priveQuery)->orderBy('date', 'desc')->get();

            $items = $priveTx->map(function ($t) {
                return [
                    'date'        => $t->date?->format('Y-m-d') ?: (string)$t->date,
                    'ref_no'      => $t->transaction_no ?: "PRV-{$t->id}",
                    'account'     => $t->account_label ?: ($t->account ?: 'KAS_BESAR'),
                    'description' => $t->notes ?: ($t->name ?: 'Penarikan Pribadi Owner'),
                    'user_name'   => $t->user_name ?: '-',
                    'amount'      => (float)$t->amount,
                ];
            });

            return response()->json([
                'account_code'    => '3-30002',
                'account_name'    => 'Prive Pemilik',
                'account_type'    => 'EQUITY',
                'category_label'  => 'Modal & Ekuitas',
                'amount'          => -$prive,
                'explanation'     => 'Akumulasi seluruh penarikan uang kas atau transfer bank oleh pemilik untuk keperluan pribadi yang sifatnya mengurangi total ekuitas kepemilikan modal di dalam neraca.',
                'formula'         => '∑ Penarikan Pribadi Pemilik (Owner Withdrawal)',
                'components'      => [
                    ['label' => 'Frekuensi Penarikan', 'value' => count($items), 'type' => 'info', 'format' => 'number'],
                    ['label' => 'Total Prive Ditarik', 'value' => $prive, 'type' => 'danger', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'date', 'label' => 'Tanggal', 'align' => 'left'],
                    ['key' => 'ref_no', 'label' => 'No. Ref', 'align' => 'left'],
                    ['key' => 'account', 'label' => 'Sumber Kas/Bank', 'align' => 'left'],
                    ['key' => 'description', 'label' => 'Keperluan / Keterangan', 'align' => 'left'],
                    ['key' => 'user_name', 'label' => 'Petugas', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/cash-flow',
                'action_label'    => 'Buka Buku Kas & Arus Kas',
            ]);
        }

        // =========================================================================
        // 12. LABA TAHUN INI / PERIODE BERJALAN (3-30003)
        // =========================================================================
        if ($accountCode === '3-30003') {
            $reportCtrl = new ReportController();
            $pnlRequest = new Request([
                'from'      => $from,
                'to'        => $to,
                'outlet_id' => $outletId,
            ]);
            if ($user) $pnlRequest->setUserResolver(fn() => $user);
            $pnlResponse = $reportCtrl->profitAndLoss($pnlRequest);
            $pnlData = json_decode($pnlResponse->getContent(), true);

            $grossSales = (float)($pnlData['revenue']['gross_sales'] ?? 0);
            $discounts  = (float)($pnlData['revenue']['discounts'] ?? 0);
            $netSales   = (float)($pnlData['revenue']['net_sales'] ?? 0);
            $cogsTotal  = (float)($pnlData['cogs']['total_cogs'] ?? 0);
            $grossProfit= (float)($pnlData['gross_profit']['amount'] ?? 0);
            $opexTotal  = (float)($pnlData['operating_expenses']['total_opex'] ?? 0);
            $netProfit  = (float)($pnlData['bottom_line']['net_profit'] ?? 0);

            $items = [
                ['component' => 'Pendapatan Penjualan Kotor (Gross Sales)', 'type' => 'Pendapatan', 'amount' => $grossSales],
                ['component' => 'Potongan / Diskon Penjualan', 'type' => 'Pengurang', 'amount' => -$discounts],
                ['component' => 'Pendapatan Penjualan Bersih (Net Sales)', 'type' => 'Subtotal', 'amount' => $netSales],
                ['component' => 'Beban Pokok Penjualan (HPP / Biaya Bahan Baku)', 'type' => 'Beban', 'amount' => -$cogsTotal],
                ['component' => 'Laba Kotor Operasional (Gross Profit)', 'type' => 'Subtotal', 'amount' => $grossProfit],
                ['component' => 'Beban Operasional & Biaya Kasir (OPEX)', 'type' => 'Beban', 'amount' => -$opexTotal],
                ['component' => 'Laba Bersih Tahun / Periode Berjalan (Net Profit)', 'type' => 'Hasil Akhir', 'amount' => $netProfit],
            ];

            return response()->json([
                'account_code'    => '3-30003',
                'account_name'    => 'Laba Tahun Ini',
                'account_type'    => 'EQUITY',
                'category_label'  => 'Modal & Ekuitas',
                'amount'          => round($netProfit, 2),
                'explanation'     => 'Laba Bersih yang diperoleh bisnis selama periode berjalan yang dihitung secara akrual dari Laporan Laba Rugi (Profit & Loss). Nilai ini menghubungkan Laporan Laba Rugi langsung ke Laporan Neraca.',
                'formula'         => 'Penjualan Bersih - Beban Pokok Penjualan (HPP) - Biaya Operasional (OPEX) + Pendapatan/Beban Lain',
                'components'      => [
                    ['label' => 'Penjualan Bersih', 'value' => $netSales, 'type' => 'info', 'format' => 'rupiah'],
                    ['label' => 'Total HPP (Bahan Baku)', 'value' => $cogsTotal, 'type' => 'warning', 'format' => 'rupiah'],
                    ['label' => 'Beban Operasional (OPEX)', 'value' => $opexTotal, 'type' => 'danger', 'format' => 'rupiah'],
                    ['label' => 'Laba Bersih Berjalan', 'value' => $netProfit, 'type' => $netProfit >= 0 ? 'success' : 'danger', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'component', 'label' => 'Komponen Laba Rugi', 'align' => 'left'],
                    ['key' => 'type', 'label' => 'Sifat Akun', 'align' => 'center'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/reports/profit-loss',
                'action_label'    => 'Buka Laporan Laba Rugi Lengkap',
            ]);
        }

        // =========================================================================
        // 13. SALDO LABA / LABA DITAHAN (3-30004)
        // =========================================================================
        if ($accountCode === '3-30004') {
            // Jalankan kalkulasi neraca lengkap untuk memberikan rekonsiliasi
            $neracaRes = $this->index($request);
            $neraca = json_decode($neracaRes->getContent(), true);

            $totAsset = (float)($neraca['total_assets']['amount'] ?? 0);
            $totLiab  = (float)($neraca['liabilities']['subtotal'] ?? 0);
            $totEqTarget = round($totAsset - $totLiab, 2);

            $modPemilik = (float)($neraca['equity']['accounts'][0]['amount'] ?? 0);
            $labaTahun = (float)(collect($neraca['equity']['accounts'])->firstWhere('code', '3-30003')['amount'] ?? 0);
            $saldoLaba = (float)(collect($neraca['equity']['accounts'])->firstWhere('code', '3-30004')['amount'] ?? 0);

            $items = [
                ['step' => '1. Total Aset (Aktiva)', 'formula' => '∑ Seluruh Aset Lancar & Fisik', 'amount' => $totAsset],
                ['step' => '2. Dikurangi: Total Liabilitas (Hutang)', 'formula' => '(-) Hutang Supplier & Pinjaman', 'amount' => -$totLiab],
                ['step' => '3. Target Total Ekuitas Pemilik', 'formula' => '(=) Aset - Liabilitas', 'amount' => $totEqTarget],
                ['step' => '4. Dikurangi: Modal Pemilik Tetap', 'formula' => '(-) Modal Disetor Tetap', 'amount' => -$modPemilik],
                ['step' => '5. Dikurangi: Laba Periode Berjalan', 'formula' => '(-) Laba Bersih P&L Periode Ini', 'amount' => -$labaTahun],
                ['step' => '6. Saldo Laba Ditahan (Laba Historis)', 'formula' => '(=) Akumulasi Saldo Laba Periode Lalu', 'amount' => $saldoLaba],
            ];

            return response()->json([
                'account_code'    => '3-30004',
                'account_name'    => 'Saldo Laba (Laba Ditahan)',
                'account_type'    => 'EQUITY',
                'category_label'  => 'Modal & Ekuitas',
                'amount'          => $saldoLaba,
                'explanation'     => 'Saldo Laba Ditahan menampung akumulasi laba atau rugi dari periode-periode operasional sebelumnya yang ditahan di dalam entitas bisnis untuk menjaga kepatuhan persamaan dasar akuntansi (Aset = Kewajiban + Modal).',
                'formula'         => 'Total Aset - Total Liabilitas - Modal Pemilik Tetap + Prive - Laba Berjalan',
                'components'      => [
                    ['label' => 'Total Aset (Aktiva)', 'value' => $totAsset, 'type' => 'info', 'format' => 'rupiah'],
                    ['label' => 'Total Liabilitas (Hutang)', 'value' => $totLiab, 'type' => 'warning', 'format' => 'rupiah'],
                    ['label' => 'Saldo Laba Ditahan', 'value' => $saldoLaba, 'type' => 'primary', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'step', 'label' => 'Tahapan Rekonsiliasi Neraca', 'align' => 'left'],
                    ['key' => 'formula', 'label' => 'Rumus & Keterangan', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/reports/balance-sheet',
                'action_label'    => 'Tinjau Laporan Neraca',
            ]);
        }

        // =========================================================================
        // 14. METRIC CARDS: TOTAL ASET, TOTAL LIABILITAS, TOTAL MODAL, STATUS NERACA
        // =========================================================================
        $neracaRes = $this->index($request);
        $neraca = json_decode($neracaRes->getContent(), true);

        if ($accountCode === 'TOTAL_ASSETS') {
            $items = collect($neraca['current_assets']['accounts'] ?? [])->map(function ($a) use ($neraca) {
                $tot = (float)($neraca['total_assets']['amount'] ?: 1);
                $pct = round(((float)$a['amount'] / $tot) * 100, 1);
                return [
                    'code'        => $a['code'],
                    'name'        => $a['name'],
                    'amount'      => (float)$a['amount'],
                    'percentage'  => "{$pct}%",
                ];
            });

            return response()->json([
                'account_code'    => 'TOTAL_ASSETS',
                'account_name'    => 'Total Aset (Aktiva)',
                'account_type'    => 'SUMMARY',
                'category_label'  => 'Ringkasan Aktiva',
                'amount'          => (float)$neraca['total_assets']['amount'],
                'explanation'     => 'Total seluruh kekayaan dan sumber daya ekonomi yang dimiliki oleh bisnis, mencakup kas tunai, simpanan bank, piutang usaha yang belum cair, serta persediaan fisik bahan dan perlengkapan.',
                'formula'         => 'Kas Besar + Kas Kecil + QRIS + Bank + Piutang Usaha + Piutang Online + Persediaan Bahan & Perlengkapan',
                'components'      => [
                    ['label' => 'Subtotal Aset Lancar', 'value' => (float)$neraca['current_assets']['subtotal'], 'type' => 'success', 'format' => 'rupiah'],
                    ['label' => 'Grand Total Aktiva', 'value' => (float)$neraca['total_assets']['amount'], 'type' => 'primary', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'code', 'label' => 'Kode Akun', 'align' => 'left'],
                    ['key' => 'name', 'label' => 'Nama Aset', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'percentage', 'label' => 'Porsi (%)', 'align' => 'center'],
                ],
                'items'           => $items,
                'action_link'     => '/reports/balance-sheet',
                'action_label'    => 'Tinjau Neraca',
            ]);
        }

        if ($accountCode === 'TOTAL_LIABILITIES') {
            $items = collect($neraca['liabilities']['accounts'] ?? [])->map(function ($a) use ($neraca) {
                $tot = (float)($neraca['liabilities']['subtotal'] ?: 1);
                $pct = round(((float)$a['amount'] / $tot) * 100, 1);
                return [
                    'code'        => $a['code'],
                    'name'        => $a['name'],
                    'amount'      => (float)$a['amount'],
                    'percentage'  => "{$pct}%",
                ];
            });

            return response()->json([
                'account_code'    => 'TOTAL_LIABILITIES',
                'account_name'    => 'Total Liabilitas (Hutang)',
                'account_type'    => 'SUMMARY',
                'category_label'  => 'Ringkasan Kewajiban',
                'amount'          => (float)$neraca['liabilities']['subtotal'],
                'explanation'     => 'Seluruh kewajiban finansial dan hutang bisnis yang harus diselesaikan kepada pihak ketiga, mencakup hutang tempo kepada supplier bahan/perlengkapan dan sisa pinjaman modal.',
                'formula'         => 'Hutang Usaha / Supplier + Hutang Pokok Pinjaman',
                'components'      => [
                    ['label' => 'Total Kewajiban Hutang', 'value' => (float)$neraca['liabilities']['subtotal'], 'type' => 'danger', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'code', 'label' => 'Kode Akun', 'align' => 'left'],
                    ['key' => 'name', 'label' => 'Jenis Hutang', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'percentage', 'label' => 'Porsi (%)', 'align' => 'center'],
                ],
                'items'           => $items,
                'action_link'     => '/payables',
                'action_label'    => 'Buka Buku Hutang',
            ]);
        }

        if ($accountCode === 'TOTAL_EQUITY') {
            $items = collect($neraca['equity']['accounts'] ?? [])->map(function ($a) {
                return [
                    'code'        => $a['code'],
                    'name'        => $a['name'],
                    'amount'      => (float)$a['amount'],
                ];
            });

            return response()->json([
                'account_code'    => 'TOTAL_EQUITY',
                'account_name'    => 'Total Modal & Ekuitas',
                'account_type'    => 'SUMMARY',
                'category_label'  => 'Ringkasan Modal',
                'amount'          => (float)$neraca['equity']['subtotal'],
                'explanation'     => 'Hak residual atas aset bisnis setelah dikurangi seluruh kewajiban liabilitas. Terdiri dari modal disetor tetap pemilik, penarikan pribadi (prive), saldo laba ditahan, dan laba bersih periode berjalan.',
                'formula'         => 'Modal Disetor - Prive + Saldo Laba Ditahan + Laba Tahun Ini',
                'components'      => [
                    ['label' => 'Total Modal & Ekuitas', 'value' => (float)$neraca['equity']['subtotal'], 'type' => 'primary', 'format' => 'rupiah'],
                ],
                'columns'         => [
                    ['key' => 'code', 'label' => 'Kode Akun', 'align' => 'left'],
                    ['key' => 'name', 'label' => 'Komponen Modal', 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ],
                'items'           => $items,
                'action_link'     => '/reports/balance-sheet',
                'action_label'    => 'Tinjau Neraca',
            ]);
        }

        if ($accountCode === 'STATUS_BALANCE') {
            $isBalanced = (bool)($neraca['is_balanced'] ?? true);
            $diff = (float)($neraca['difference'] ?? 0);
            $totAsset = (float)($neraca['total_assets']['amount'] ?? 0);
            $totPassiva = (float)($neraca['total_liabilities_and_equity']['amount'] ?? 0);

            $items = [
                ['parameter' => 'Total Aset (Aktiva)', 'value' => $totAsset, 'status' => 'OK'],
                ['parameter' => 'Total Kewajiban & Modal (Passiva)', 'value' => $totPassiva, 'status' => 'OK'],
                ['parameter' => 'Selisih Rekonsiliasi (Aktiva - Passiva)', 'value' => $diff, 'status' => $isBalanced ? 'SEIMBANG' : 'SELISIH'],
            ];

            return response()->json([
                'account_code'    => 'STATUS_BALANCE',
                'account_name'    => 'Status Keseimbangan Neraca (Audit)',
                'account_type'    => 'SUMMARY',
                'category_label'  => 'Audit Keseimbangan',
                'amount'          => $diff,
                'explanation'     => $isBalanced
                    ? 'Laporan Neraca berada dalam kondisi SEIMBANG (Balanced). Total Nilai Aset (Aktiva) sama persis dengan Total Liabilitas ditambah Modal (Passiva).'
                    : 'Terdeteksi selisih rekonsiliasi antara Aktiva dan Passiva. Sistem mendeteksi adanya mutasi yang membutuhkan pengecekan ulang pada pos kas atau persediaan.',
                'formula'         => 'Total Aset = Total Liabilitas + Total Modal',
                'components'      => [
                    ['label' => 'Total Aset (Aktiva)', 'value' => $totAsset, 'type' => 'success', 'format' => 'rupiah'],
                    ['label' => 'Total Kewajiban & Modal', 'value' => $totPassiva, 'type' => 'info', 'format' => 'rupiah'],
                    ['label' => 'Status Rekonsiliasi', 'value' => $isBalanced ? 'SEIMBANG (Rp0)' : "SELISIH Rp{$diff}", 'type' => $isBalanced ? 'success' : 'danger', 'format' => 'string'],
                ],
                'columns'         => [
                    ['key' => 'parameter', 'label' => 'Parameter Pemeriksaan', 'align' => 'left'],
                    ['key' => 'value', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                    ['key' => 'status', 'label' => 'Hasil Evaluasi', 'align' => 'center'],
                ],
                'items'           => $items,
                'action_link'     => '/reports/balance-sheet',
                'action_label'    => 'Tinjau Neraca',
            ]);
        }

        return response()->json([
            'account_code'   => $accountCode,
            'account_name'   => $accountName ?: 'Detail Akun Neraca',
            'explanation'    => 'Detail rincian untuk akun neraca ini.',
            'items'          => [],
        ]);
    }
}

