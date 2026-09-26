<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Supplier;
use App\Models\Outlet;
use App\Models\Ingredient;
use App\Models\Transfer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseReportController extends Controller
{
    /**
     * Resolve target outlet filter based on user role and request.
     */
    private function resolveOutletScope(Request $request): array
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
            $outlet = Outlet::find($outletId);
            $isHolding = $outlet ? (bool)$outlet->is_main : false;
            $scope = $isHolding ? 'HOLDING' : 'OUTLET';
            return [$outletId, $scope, $isHolding];
        }

        $reqScope = strtoupper(trim($request->input('scope', 'ALL')));
        $reqOutletId = $request->input('outlet_id');

        if ($reqOutletId && $reqOutletId !== 'ALL' && $reqOutletId !== 'all') {
            $outletId = (int)$reqOutletId;
            $outlet = Outlet::find($outletId);
            $isHolding = $outlet ? (bool)$outlet->is_main : ($outletId == 1);
            $scope = $isHolding ? 'HOLDING' : 'OUTLET';
            return [$outletId, $scope, $isHolding];
        }

        return [null, in_array($reqScope, ['HOLDING', 'OUTLET']) ? $reqScope : 'ALL', null];
    }

    /**
     * Get business and outlet display names for header branding.
     */
    private function getContextNames(Request $request, ?int $outletId, string $scope): array
    {
        $user = $request->user();
        $business = $user?->business;
        $businessName = $business?->name ?: ($user?->business_name ?: 'MOVA POS');

        if ($outletId) {
            $ot = Outlet::find($outletId);
            $outletName = $ot ? $ot->name : "Outlet #{$outletId}";
        } elseif ($scope === 'HOLDING') {
            $holding = Outlet::where('is_main', true)->first();
            $outletName = $holding ? "Holding - {$holding->name}" : 'Holding (Pusat / Gudang Utama)';
        } elseif ($scope === 'OUTLET') {
            $outletName = 'Semua Outlet Cabang (Kas Only)';
        } else {
            $outletName = 'Konsolidasi Seluruh Unit (Holding & Outlet)';
        }

        return [$businessName, $outletName];
    }

    /**
     * Format date range into standard Indonesian label:
     * "Per DD MMMM YYYY s/d DD MMMM YYYY"
     */
    private function formatPeriodLabel(string $from, string $to): string
    {
        $monthNames = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        $f = Carbon::parse($from);
        $t = Carbon::parse($to);

        $fStr = sprintf('%02d %s %04d', $f->day, $monthNames[$f->month], $f->year);
        $tStr = sprintf('%02d %s %04d', $t->day, $monthNames[$t->month], $t->year);

        return "Per {$fStr} s/d {$tStr}";
    }

    /**
     * 1. LAPORAN TRANSAKSI PEMBELIAN (Detail Barang Masuk)
     * Matches user CSV format:
     * No., Tgl, Tgl.Dibuat, Dibuat Oleh, No.Ref, Warehouse, Supplier, Status Terima,
     * Kode Produk, Produk, QTY, Satuan, QTY Terkecil, Satuan Terkecil, Harga, Disc, Subtotal,
     * Disc Tambahan, PPN, Pengiriman, Pembelian, Dibayar, Utang
     */
    public function transactions(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        [$outletId, $scope, $isHolding] = $this->resolveOutletScope($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId, $scope);

        $query = StockMovement::with(['ingredient', 'outlet', 'user', 'payable.payments'])
            ->where('type', 'PURCHASE')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        // Apply outlet or scope filter
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        } elseif ($scope === 'HOLDING') {
            $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
            if (empty($holdingIds)) $holdingIds = [1];
            $query->whereIn('outlet_id', $holdingIds);
        } elseif ($scope === 'OUTLET') {
            $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
            if (empty($holdingIds)) $holdingIds = [1];
            $query->whereNotIn('outlet_id', $holdingIds);
            // Outlet Cabang rule: Kas Only
            $query->where('payment_type', 'CASH');
        }

        // Specific payment_type filter (CASH, BANK, HUTANG)
        if ($request->filled('payment_type') && $request->payment_type !== 'ALL' && $request->payment_type !== 'all') {
            $reqPay = strtoupper(trim($request->payment_type));
            if ($reqPay === 'BANK') {
                $query->whereIn('payment_type', ['BANK', 'TRANSFER', 'QRIS']);
            } else {
                $query->where('payment_type', $reqPay);
            }
        }

        // Specific supplier filter
        if ($request->filled('supplier_name') && $request->supplier_name !== 'ALL') {
            $query->where('supplier_name', 'like', "%{$request->supplier_name}%");
        }

        // Search text
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('purchase_no', 'like', "%{$s}%")
                  ->orWhere('supplier_name', 'like', "%{$s}%")
                  ->orWhere('note', 'like', "%{$s}%")
                  ->orWhereHas('ingredient', function ($iq) use ($s) {
                      $iq->where('name', 'like', "%{$s}%")
                         ->orWhere('code', 'like', "%{$s}%");
                  });
            });
        }

        $movements = $query->get();

        $rows = [];
        $totalDiscTambahan = 0.0;
        $totalPpn = 0.0;
        $totalPengiriman = 0.0;
        $totalPembelian = 0.0;
        $totalDibayar = 0.0;
        $totalUtang = 0.0;
        $totalKas = 0.0;
        $totalBank = 0.0;
        $totalHutangNominal = 0.0;

        $no = 1;
        foreach ($movements as $m) {
            $ing = $m->ingredient;
            $ot  = $m->outlet;
            $konversi = max((float)($ing?->konversi ?? 1), 1);
            $qtyPakai = (float)$m->qty;
            $qtyBeli  = round($qtyPakai / $konversi, 4);

            $unitBeli  = $ing?->unit_beli ?: ($ing?->unit_pakai ?: 'Unit');
            $unitPakai = $ing?->unit_pakai ?: 'Unit';

            $subtotal  = (float)$m->total_price;
            $pembelian = $subtotal;

            $payType = strtoupper($m->payment_type ?: 'CASH');
            $isRowHolding = $ot ? (bool)$ot->is_main : ((int)$m->outlet_id === 1);

            // Compute paid vs debt
            if ($payType === 'HUTANG') {
                $payable = $m->payable;
                $initialPaid = $payable ? (float)$payable->paid_amount : 0.0;
                $remainingUtang = $payable ? (float)$payable->remaining_amount : $subtotal;
                $dibayar = $initialPaid;
                $utang = $remainingUtang;
                $totalHutangNominal += $subtotal;
            } elseif (in_array($payType, ['BANK', 'TRANSFER', 'QRIS'])) {
                $dibayar = $subtotal;
                $utang = 0.0;
                $totalBank += $subtotal;
            } else {
                // CASH
                $dibayar = $subtotal;
                $utang = 0.0;
                $totalKas += $subtotal;
            }

            $totalPembelian += $pembelian;
            $totalDibayar   += $dibayar;
            $totalUtang     += $utang;

            $createdByName = $m->user?->name ?: ($m->created_by_name ?: 'System');
            $refNo = $m->purchase_no ?: ('SMV-' . str_pad($m->id, 5, '0', STR_PAD_LEFT));

            $rows[] = [
                'no'               => $no++,
                'id'               => $m->id,
                'tgl'              => Carbon::parse($m->date)->format('d/m/Y'),
                'tgl_raw'          => $m->date,
                'tgl_dibuat'       => $m->created_at ? $m->created_at->format('d/m/Y H:i') : Carbon::parse($m->date)->format('d/m/Y'),
                'dibuat_oleh'      => $createdByName,
                'no_ref'           => $refNo,
                'warehouse'        => $ot?->name ?: "Outlet #{$m->outlet_id}",
                'outlet_id'        => $m->outlet_id,
                'is_holding'       => $isRowHolding,
                'supplier'         => $m->supplier_name ?: 'Supplier Umum',
                'status_terima'    => 'Diterima',
                'kode_produk'      => $ing?->code ?: ('ING-' . $m->ingredient_id),
                'produk'           => $ing?->name ?: 'Item #' . $m->ingredient_id,
                'qty'              => $qtyBeli,
                'satuan'           => $unitBeli,
                'qty_terkecil'     => $qtyPakai,
                'satuan_terkecil'  => $unitPakai,
                'harga'            => (float)$m->unit_price,
                'disc'             => 0.0,
                'subtotal'         => $subtotal,
                'disc_tambahan'    => 0.0,
                'ppn'              => 0.0,
                'pengiriman'       => 0.0,
                'pembelian'        => $pembelian,
                'dibayar'          => $dibayar,
                'utang'            => $utang,
                'payment_type'     => $payType,
                'payable_id'       => $m->payable_id,
                'note'             => $m->note,
            ];
        }

        return response()->json([
            'business_name' => $businessName,
            'report_title'  => 'Laporan Transaksi Pembelian',
            'period'        => $this->formatPeriodLabel($from, $to),
            'outlet_name'   => $outletName,
            'scope'         => $scope,
            'is_holding'    => $isHolding,
            'policy_note'   => $scope === 'OUTLET'
                ? 'Outlet Cabang: Pembelian barang masuk hanya menggunakan KAS (Petty Cash Cabang).'
                : ($scope === 'HOLDING'
                    ? 'Holding: Pembelian mendukung Hutang (Tempo), Kas, dan Bank.'
                    : 'Konsolidasi: Holding (Hutang, Kas, Bank) dan Outlet Cabang (Kas Only).'),
            'summary' => [
                'total_disc_tambahan' => $totalDiscTambahan,
                'total_ppn'           => $totalPpn,
                'total_pengiriman'    => $totalPengiriman,
                'total_pembelian'     => $totalPembelian,
                'total_dibayar'       => $totalDibayar,
                'total_utang'         => $totalUtang,
                'total_kas'           => $totalKas,
                'total_bank'          => $totalBank,
                'total_hutang'        => $totalHutangNominal,
                'total_items'         => count($rows),
            ],
            'items' => $rows,
        ]);
    }

    /**
     * 2. LAPORAN PEMBELIAN PER PRODUK
     * Matches user CSV format:
     * No., Kode Produk, Nama Produk, Qty Beli, Qty Refund, Satuan, Harga, Disc, Total Nilai Beli, Total Nilai Refund
     */
    public function byProduct(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        [$outletId, $scope, $isHolding] = $this->resolveOutletScope($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId, $scope);

        $query = StockMovement::with(['ingredient', 'outlet'])
            ->where('type', 'PURCHASE')
            ->whereBetween('date', [$from, $to]);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        } elseif ($scope === 'HOLDING') {
            $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
            if (empty($holdingIds)) $holdingIds = [1];
            $query->whereIn('outlet_id', $holdingIds);
        } elseif ($scope === 'OUTLET') {
            $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
            if (empty($holdingIds)) $holdingIds = [1];
            $query->whereNotIn('outlet_id', $holdingIds);
            $query->where('payment_type', 'CASH');
        }

        if ($request->filled('payment_type') && $request->payment_type !== 'ALL' && $request->payment_type !== 'all') {
            $reqPay = strtoupper(trim($request->payment_type));
            if ($reqPay === 'BANK') {
                $query->whereIn('payment_type', ['BANK', 'TRANSFER', 'QRIS']);
            } else {
                $query->where('payment_type', $reqPay);
            }
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->whereHas('ingredient', function ($iq) use ($s) {
                $iq->where('name', 'like', "%{$s}%")
                   ->orWhere('code', 'like', "%{$s}%");
            });
        }

        $movements = $query->get();

        // Group by ingredient_id
        $grouped = [];
        foreach ($movements as $m) {
            $ingId = $m->ingredient_id;
            $ing = $m->ingredient;
            $konversi = max((float)($ing?->konversi ?? 1), 1);
            $qtyBeli = (float)$m->qty / $konversi;

            if (!isset($grouped[$ingId])) {
                $grouped[$ingId] = [
                    'ingredient_id' => $ingId,
                    'kode_produk'   => $ing?->code ?: ('ING-' . $ingId),
                    'nama_produk'   => $ing?->name ?: 'Item #' . $ingId,
                    'satuan'        => $ing?->unit_beli ?: ($ing?->unit_pakai ?: 'Unit'),
                    'qty_beli'      => 0.0,
                    'qty_refund'    => 0.0,
                    'disc'          => 0.0,
                    'total_nilai_beli'   => 0.0,
                    'total_nilai_refund' => 0.0,
                ];
            }

            $grouped[$ingId]['qty_beli'] += $qtyBeli;
            $grouped[$ingId]['total_nilai_beli'] += (float)$m->total_price;
        }

        $rows = [];
        $totalNilaiBeliAll = 0.0;
        $totalNilaiRefundAll = 0.0;
        $totalQtyBeliAll = 0.0;
        $no = 1;

        // Sort descending by total_nilai_beli
        uasort($grouped, fn($a, $b) => $b['total_nilai_beli'] <=> $a['total_nilai_beli']);

        foreach ($grouped as $g) {
            $qtyBeli = round($g['qty_beli'], 4);
            $totalNilai = round($g['total_nilai_beli'], 2);
            $avgHarga = $qtyBeli > 0 ? round($totalNilai / $qtyBeli, 2) : 0.0;

            $totalNilaiBeliAll += $totalNilai;
            $totalQtyBeliAll   += $qtyBeli;

            $rows[] = [
                'no'                 => $no++,
                'kode_produk'        => $g['kode_produk'],
                'nama_produk'        => $g['nama_produk'],
                'qty_beli'           => $qtyBeli,
                'qty_refund'         => 0.0,
                'satuan'             => $g['satuan'],
                'harga'              => $avgHarga,
                'disc'               => 0.0,
                'total_nilai_beli'   => $totalNilai,
                'total_nilai_refund' => 0.0,
            ];
        }

        return response()->json([
            'business_name' => $businessName,
            'report_title'  => 'LAPORAN PEMBELIAN PER PRODUK',
            'period'        => $this->formatPeriodLabel($from, $to),
            'outlet_name'   => $outletName,
            'scope'         => $scope,
            'summary' => [
                'total_nilai_beli'   => $totalNilaiBeliAll,
                'total_nilai_refund' => $totalNilaiRefundAll,
                'total_qty_beli'     => $totalQtyBeliAll,
                'total_products'     => count($rows),
            ],
            'items' => $rows,
        ]);
    }

    /**
     * 3. LAPORAN DAFTAR PEMBELIAN PER SUPPLIER
     * Matches user CSV format:
     * No., Supplier/Kode Produk, Tgl.Dibuat, Dibuat Oleh, No.Ref, Pembelian, Disc, Pajak, Pengiriman, Total
     */
    public function bySupplier(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        [$outletId, $scope, $isHolding] = $this->resolveOutletScope($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId, $scope);

        $query = StockMovement::with(['ingredient', 'outlet', 'user', 'payable'])
            ->where('type', 'PURCHASE')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date', 'desc');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        } elseif ($scope === 'HOLDING') {
            $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
            if (empty($holdingIds)) $holdingIds = [1];
            $query->whereIn('outlet_id', $holdingIds);
        } elseif ($scope === 'OUTLET') {
            $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
            if (empty($holdingIds)) $holdingIds = [1];
            $query->whereNotIn('outlet_id', $holdingIds);
            $query->where('payment_type', 'CASH');
        }

        if ($request->filled('supplier_name') && $request->supplier_name !== 'ALL') {
            $query->where('supplier_name', 'like', "%{$request->supplier_name}%");
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('supplier_name', 'like', "%{$s}%")
                  ->orWhere('purchase_no', 'like', "%{$s}%")
                  ->orWhereHas('ingredient', function ($iq) use ($s) {
                      $iq->where('name', 'like', "%{$s}%");
                  });
            });
        }

        $movements = $query->get();

        // Group by supplier_name
        $grouped = [];
        foreach ($movements as $m) {
            $supplier = trim($m->supplier_name ?: 'Supplier Umum');

            if (!isset($grouped[$supplier])) {
                $grouped[$supplier] = [
                    'supplier_name'   => $supplier,
                    'latest_date'     => $m->date,
                    'created_at'      => $m->created_at ? $m->created_at->format('d/m/Y H:i') : Carbon::parse($m->date)->format('d/m/Y'),
                    'dibuat_oleh'     => $m->user?->name ?: ($m->created_by_name ?: 'Admin'),
                    'ref_numbers'     => [],
                    'pembelian'       => 0.0,
                    'disc'            => 0.0,
                    'pajak'           => 0.0,
                    'pengiriman'      => 0.0,
                    'total'           => 0.0,
                    'dibayar'         => 0.0,
                    'sisa_hutang'     => 0.0,
                    'item_count'      => 0,
                    'items'           => [],
                ];
            }

            $ref = $m->purchase_no ?: ('SMV-' . str_pad($m->id, 5, '0', STR_PAD_LEFT));
            if (!in_array($ref, $grouped[$supplier]['ref_numbers'])) {
                $grouped[$supplier]['ref_numbers'][] = $ref;
            }

            $subtotal = (float)$m->total_price;
            $grouped[$supplier]['pembelian']  += $subtotal;
            $grouped[$supplier]['total']      += $subtotal;
            $grouped[$supplier]['item_count'] += 1;

            if (strtoupper($m->payment_type) === 'HUTANG' && $m->payable) {
                $grouped[$supplier]['dibayar']     += (float)$m->payable->paid_amount;
                $grouped[$supplier]['sisa_hutang'] += (float)$m->payable->remaining_amount;
            } else {
                $grouped[$supplier]['dibayar'] += $subtotal;
            }

            $ing = $m->ingredient;
            $konversi = max((float)($ing?->konversi ?? 1), 1);
            $grouped[$supplier]['items'][] = [
                'kode_produk'  => $ing?->code ?: ('ING-' . $m->ingredient_id),
                'nama_produk'  => $ing?->name ?: 'Item #' . $m->ingredient_id,
                'qty'          => round((float)$m->qty / $konversi, 4),
                'satuan'       => $ing?->unit_beli ?: ($ing?->unit_pakai ?: 'Unit'),
                'harga'        => (float)$m->unit_price,
                'subtotal'     => $subtotal,
                'payment_type' => $m->payment_type,
                'date'         => $m->date,
                'ref_no'       => $ref,
            ];
        }

        // Sort descending by total
        uasort($grouped, fn($a, $b) => $b['total'] <=> $a['total']);

        $rows = [];
        $grandTotal = 0.0;
        $grandDibayar = 0.0;
        $grandSisaHutang = 0.0;
        $no = 1;

        foreach ($grouped as $supplier => $g) {
            $grandTotal      += $g['total'];
            $grandDibayar    += $g['dibayar'];
            $grandSisaHutang += $g['sisa_hutang'];

            $rows[] = [
                'no'                    => $no++,
                'supplier'              => $supplier,
                'supplier_kode_produk'  => $supplier,
                'tgl_dibuat'            => $g['created_at'],
                'dibuat_oleh'           => $g['dibuat_oleh'],
                'no_ref'                => implode(', ', array_slice($g['ref_numbers'], 0, 3)) . (count($g['ref_numbers']) > 3 ? '...' : ''),
                'pembelian'             => round($g['pembelian'], 2),
                'disc'                  => 0.0,
                'pajak'                 => 0.0,
                'pengiriman'            => 0.0,
                'total'                 => round($g['total'], 2),
                'dibayar'               => round($g['dibayar'], 2),
                'sisa_hutang'           => round($g['sisa_hutang'], 2),
                'item_count'            => $g['item_count'],
                'products'              => $g['items'],
            ];
        }

        return response()->json([
            'business_name' => $businessName,
            'report_title'  => 'LAPORAN DAFTAR PEMBELIAN PER SUPPLIER',
            'period'        => $this->formatPeriodLabel($from, $to),
            'outlet_name'   => $outletName,
            'scope'         => $scope,
            'summary' => [
                'total_pembelian'   => $grandTotal,
                'total_dibayar'     => $grandDibayar,
                'total_sisa_hutang' => $grandSisaHutang,
                'total_suppliers'   => count($rows),
            ],
            'items' => $rows,
        ]);
    }

    /**
     * 4. LAPORAN HUTANG SUPPLIER (Accounts Payable Ledger)
     * Matches user CSV format:
     * No., Supplier/Tanggal, Tgl. Dibuat, Dibuat Oleh, No.Pembelian, No.Bayar, Jatuh Tempo, Hutang, Dibayar, Sisa Hutang, Total Hutang
     * Total Utang: "0,00"
     * NOTE: Hutang is specific to Holding (Outlet Pusat / Central Warehouse).
     */
    public function payables(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        [$outletId, $scope, $isHolding] = $this->resolveOutletScope($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId, $scope);

        // If scoped to outlet cabang only, hutang is not applicable (Kas Only)
        if ($scope === 'OUTLET') {
            return response()->json([
                'business_name' => $businessName,
                'report_title'  => 'LAPORAN HUTANG SUPPLIER',
                'period'        => $this->formatPeriodLabel($from, $to),
                'outlet_name'   => $outletName,
                'scope'         => 'OUTLET',
                'is_holding'    => false,
                'policy_note'   => 'Outlet Cabang tidak mengelola hutang supplier (Semua pembelian cabang adalah Kas Only). Silakan pilih Holding untuk melihat Buku Hutang Supplier.',
                'summary' => [
                    'total_hutang'      => 0.0,
                    'total_dibayar'     => 0.0,
                    'total_sisa_hutang' => 0.0,
                    'total_records'     => 0,
                ],
                'items' => [],
            ]);
        }

        $query = Payable::with(['payments.payer', 'outlet', 'creator', 'supplier', 'stockMovement'])
            ->whereBetween('issue_date', [$from, $to])
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('status') && $request->status !== 'ALL' && $request->status !== 'all') {
            if ($request->status === 'OVERDUE') {
                $query->where('status', '!=', 'PAID')
                      ->where('status', '!=', 'CANCELLED')
                      ->where('due_date', '<', now()->toDateString());
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('supplier_name') && $request->supplier_name !== 'ALL') {
            $query->where('supplier_name', 'like', "%{$request->supplier_name}%");
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('payable_no', 'like', "%{$s}%")
                  ->orWhere('purchase_no', 'like', "%{$s}%")
                  ->orWhere('supplier_name', 'like', "%{$s}%")
                  ->orWhere('notes', 'like', "%{$s}%");
            });
        }

        $payables = $query->get();

        $rows = [];
        $totalHutangAwal = 0.0;
        $totalDibayarAll = 0.0;
        $totalSisaHutangAll = 0.0;
        $no = 1;

        foreach ($payables as $p) {
            $supplierName = $p->supplier_name ?: 'Supplier Umum';
            $tglDibuat = $p->created_at ? $p->created_at->format('d/m/Y H:i') : Carbon::parse($p->issue_date)->format('d/m/Y');
            $dibuatOleh = $p->creator?->name ?: 'Admin';
            $noPembelian = $p->purchase_no ?: $p->payable_no;

            // Get payment numbers
            $noBayarList = $p->payments->pluck('payment_no')->toArray();
            $noBayar = !empty($noBayarList) ? implode(', ', $noBayarList) : '-';

            $jatuhTempo = Carbon::parse($p->due_date)->format('d/m/Y');
            $hutang = (float)$p->total_amount;
            $dibayar = (float)$p->paid_amount;
            $sisaHutang = (float)$p->remaining_amount;

            $totalHutangAwal   += $hutang;
            $totalDibayarAll   += $dibayar;
            $totalSisaHutangAll += $sisaHutang;

            $rows[] = [
                'no'               => $no++,
                'id'               => $p->id,
                'supplier_tanggal' => "{$supplierName} / " . Carbon::parse($p->issue_date)->format('d/m/Y'),
                'supplier_name'    => $supplierName,
                'issue_date'       => $p->issue_date,
                'tgl_dibuat'       => $tglDibuat,
                'dibuat_oleh'      => $dibuatOleh,
                'no_pembelian'     => $noPembelian,
                'payable_no'       => $p->payable_no,
                'no_bayar'         => $noBayar,
                'jatuh_tempo'      => $jatuhTempo,
                'due_date_raw'     => $p->due_date,
                'is_overdue'       => ($p->status !== 'PAID' && $p->status !== 'CANCELLED' && $p->due_date < now()->toDateString()),
                'hutang'           => $hutang,
                'dibayar'          => $dibayar,
                'sisa_hutang'      => $sisaHutang,
                'total_hutang'     => $sisaHutang, // Sisa kewajiban aktif
                'status'           => $p->status,
                'notes'            => $p->notes,
                'payments'         => $p->payments->map(fn($pm) => [
                    'payment_no'     => $pm->payment_no,
                    'payment_date'   => $pm->payment_date,
                    'amount'         => (float)$pm->amount,
                    'payment_method' => $pm->payment_method,
                    'payer_name'     => $pm->payer?->name ?: 'Staff',
                ]),
            ];
        }

        return response()->json([
            'business_name' => $businessName,
            'report_title'  => 'LAPORAN HUTANG SUPPLIER',
            'period'        => $this->formatPeriodLabel($from, $to),
            'outlet_name'   => $outletName,
            'scope'         => $scope,
            'is_holding'    => true,
            'policy_note'   => 'Fasilitas Hutang / Tempo Supplier dikelola terpusat oleh Holding / Gudang Utama.',
            'summary' => [
                'total_hutang'      => $totalHutangAwal,
                'total_dibayar'     => $totalDibayarAll,
                'total_sisa_hutang' => $totalSisaHutangAll,
                'total_utang'       => $totalSisaHutangAll,
                'total_records'     => count($rows),
            ],
            'items' => $rows,
        ]);
    }

    /**
     * 5. LAPORAN PENGIRIMAN PEMBELIAN (Goods In-Transit / Inbound Deliveries)
     * Matches user CSV format:
     * No., Supplier/Tanggal, Tgl. Dibuat, Dibuat Oleh, No.Ref, Kode Produk, Nama Produk, Qty, Satuan, Jumlah
     */
    public function shipments(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        [$outletId, $scope, $isHolding] = $this->resolveOutletScope($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId, $scope);

        // Fetch Inbound Transfers from External Suppliers (In-Transit or Completed)
        $transferQuery = Transfer::with(['destinationOutlet', 'creator', 'items.ingredient'])
            ->where('transfer_type', 'INBOUND')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        if ($outletId) {
            $transferQuery->where('destination_outlet_id', $outletId);
        } elseif ($scope === 'HOLDING') {
            $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
            if (empty($holdingIds)) $holdingIds = [1];
            $transferQuery->whereIn('destination_outlet_id', $holdingIds);
        } elseif ($scope === 'OUTLET') {
            $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
            if (empty($holdingIds)) $holdingIds = [1];
            $transferQuery->whereNotIn('destination_outlet_id', $holdingIds);
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $transferQuery->where(function ($q) use ($s) {
                $q->where('transfer_no', 'like', "%{$s}%")
                  ->orWhere('source_name', 'like', "%{$s}%")
                  ->orWhere('driver_name', 'like', "%{$s}%")
                  ->orWhere('vehicle_no', 'like', "%{$s}%")
                  ->orWhereHas('items.ingredient', function ($iq) use ($s) {
                      $iq->where('name', 'like', "%{$s}%");
                  });
            });
        }

        $transfers = $transferQuery->get();

        $rows = [];
        $totalJumlahAll = 0.0;
        $totalQtyAll = 0.0;
        $no = 1;

        foreach ($transfers as $trf) {
            $supplierName = $trf->source_name ?: 'Supplier Ekspedisi';
            $tglDibuat = $trf->created_at ? $trf->created_at->format('d/m/Y H:i') : Carbon::parse($trf->date)->format('d/m/Y');
            $dibuatOleh = $trf->creator?->name ?: 'Staff Pengadaan';
            $noRef = $trf->vehicle_no ? "{$trf->transfer_no} (Resi: {$trf->vehicle_no})" : $trf->transfer_no;

            foreach ($trf->items as $it) {
                $ing = $it->ingredient;
                $qty = (float)($it->input_qty ?: $it->qty);
                $satuan = $it->input_unit ?: ($it->unit ?: ($ing?->unit_beli ?: 'Unit'));
                $subtotal = (float)$it->total_price ?: ($qty * (float)$it->unit_price);

                $totalJumlahAll += $subtotal;
                $totalQtyAll    += $qty;

                $rows[] = [
                    'no'               => $no++,
                    'supplier_tanggal' => "{$supplierName} / " . Carbon::parse($trf->date)->format('d/m/Y'),
                    'supplier_name'    => $supplierName,
                    'tgl_dibuat'       => $tglDibuat,
                    'dibuat_oleh'      => $dibuatOleh,
                    'no_ref'           => $noRef,
                    'kode_produk'      => $ing?->code ?: ('ING-' . $it->ingredient_id),
                    'nama_produk'      => $ing?->name ?: 'Item #' . $it->ingredient_id,
                    'qty'              => $qty,
                    'satuan'           => $satuan,
                    'jumlah'           => $subtotal,
                    'status'           => $trf->status === 'IN_TRANSIT' ? 'Dalam Perjalanan' : ($trf->status === 'COMPLETED' ? 'Diterima' : $trf->status),
                    'ekspedisi'        => $trf->driver_name ?: 'Kurir Pengiriman',
                    'no_resi'          => $trf->vehicle_no ?: '-',
                    'warehouse'        => $trf->destinationOutlet?->name ?: 'Warehouse',
                ];
            }
        }

        // Also if there are directly received purchases that had courier/shipping in note
        if (empty($rows)) {
            $mvtQuery = StockMovement::with(['ingredient', 'outlet', 'user'])
                ->where('type', 'PURCHASE')
                ->whereBetween('date', [$from, $to])
                ->orderBy('date', 'desc');

            if ($outletId) {
                $mvtQuery->where('outlet_id', $outletId);
            } elseif ($scope === 'HOLDING') {
                $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
                if (empty($holdingIds)) $holdingIds = [1];
                $mvtQuery->whereIn('outlet_id', $holdingIds);
            } elseif ($scope === 'OUTLET') {
                $holdingIds = Outlet::where('is_main', true)->pluck('id')->toArray();
                if (empty($holdingIds)) $holdingIds = [1];
                $mvtQuery->whereNotIn('outlet_id', $holdingIds);
            }

            $movements = $mvtQuery->limit(50)->get();
            foreach ($movements as $m) {
                $ing = $m->ingredient;
                $konversi = max((float)($ing?->konversi ?? 1), 1);
                $qty = round((float)$m->qty / $konversi, 4);
                $satuan = $ing?->unit_beli ?: ($ing?->unit_pakai ?: 'Unit');
                $subtotal = (float)$m->total_price;

                $totalJumlahAll += $subtotal;
                $totalQtyAll    += $qty;

                $supplier = $m->supplier_name ?: 'Supplier Umum';
                $rows[] = [
                    'no'               => $no++,
                    'supplier_tanggal' => "{$supplier} / " . Carbon::parse($m->date)->format('d/m/Y'),
                    'supplier_name'    => $supplier,
                    'tgl_dibuat'       => $m->created_at ? $m->created_at->format('d/m/Y H:i') : Carbon::parse($m->date)->format('d/m/Y'),
                    'dibuat_oleh'      => $m->user?->name ?: 'Staff Pengadaan',
                    'no_ref'           => $m->purchase_no ?: ('SMV-' . str_pad($m->id, 5, '0', STR_PAD_LEFT)),
                    'kode_produk'      => $ing?->code ?: ('ING-' . $m->ingredient_id),
                    'nama_produk'      => $ing?->name ?: 'Item #' . $m->ingredient_id,
                    'qty'              => $qty,
                    'satuan'           => $satuan,
                    'jumlah'           => $subtotal,
                    'status'           => 'Diterima',
                    'ekspedisi'        => 'Pengiriman Langsung',
                    'no_resi'          => '-',
                    'warehouse'        => $m->outlet?->name ?: 'Warehouse',
                ];
            }
        }

        return response()->json([
            'business_name' => $businessName,
            'report_title'  => 'LAPORAN PENGIRIMAN PEMBELIAN',
            'period'        => $this->formatPeriodLabel($from, $to),
            'outlet_name'   => $outletName,
            'scope'         => $scope,
            'summary' => [
                'total_jumlah' => $totalJumlahAll,
                'total_qty'    => $totalQtyAll,
                'total_items'  => count($rows),
            ],
            'items' => $rows,
        ]);
    }
}
