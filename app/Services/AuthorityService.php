<?php

namespace App\Services;

class AuthorityService
{
    /**
     * Master definitions of all granular authorities & permissions
     */
    public const MODULES = [
        'pos' => [
            'id'          => 'pos',
            'group_label' => 'Kasir & Transaksi POS',
            'icon'        => 'ShoppingCart',
            'description' => 'Wewenang pengoperasian mesin kasir, potongan harga, dan proses transaksi',
            'permissions' => [
                'pos_checkout' => [
                    'key'         => 'pos_checkout',
                    'label'       => 'Akses Kasir POS',
                    'description' => 'Membuka antarmuka kasir dan memproses pesanan / pembayaran penjualan',
                ],
                'pos_custom_discount' => [
                    'key'         => 'pos_custom_discount',
                    'label'       => 'Diskon Manual Kasir',
                    'description' => 'Memberikan potongan nominal / persen diskon khusus di keranjang kasir',
                ],
                'pos_open_bill' => [
                    'key'         => 'pos_open_bill',
                    'label'       => 'Open Bill & Meja (Keep Bill)',
                    'description' => 'Menyimpan transaksi menggantung / reservasi meja pesanan',
                ],
                'pos_split_bill' => [
                    'key'         => 'pos_split_bill',
                    'label'       => 'Split Bill (Pecah Pembayaran)',
                    'description' => 'Memecah pembayaran satu nota pesanan menjadi beberapa pembayaran',
                ],
                'pos_shift_recap' => [
                    'key'         => 'pos_shift_recap',
                    'label'       => 'Shift Kasir & Cetak Rekapan',
                    'description' => 'Membuka shift, menutup shift, dan mencetak laporan closing kasir',
                ],
                'pos_shift_deposit_approval' => [
                    'key'         => 'pos_shift_deposit_approval',
                    'label'       => 'Approval Setoran Shift Kasir',
                    'description' => 'Memverifikasi dan menyetujui selisih setoran kasir saat tutup shift',
                ],
                'pos_urgent_note' => [
                    'key'         => 'pos_urgent_note',
                    'label'       => 'Nota Urgent / Defisit Manual',
                    'description' => 'Menerbitkan nota manual darurat saat stok bahan baku dapur belum tercatat',
                ],
                'pos_void_request' => [
                    'key'         => 'pos_void_request',
                    'label'       => 'Pengajuan Void Nota',
                    'description' => 'Mengajukan permohonan pembatalan nota pesanan ke atasan',
                ],
                'pos_void_approve' => [
                    'key'         => 'pos_void_approve',
                    'label'       => 'Approval Void Nota',
                    'description' => 'Menyetujui atau menolak pembatalan transaksi nota yang diajukan kasir',
                ],
            ],
        ],
        'inventory' => [
            'id'          => 'inventory',
            'group_label' => 'Inventori & Gudang Cabang',
            'icon'        => 'Package',
            'description' => 'Wewenang pencatatan stok fisik, transfer antar outlet, dan bahan terbuang',
            'permissions' => [
                'stock_opname_input' => [
                    'key'         => 'stock_opname_input',
                    'label'       => 'Input Hitung Fisik (Opname)',
                    'description' => 'Melakukan pencatatan hitung stok fisik bahan baku & perlengkapan',
                ],
                'stock_opname_release' => [
                    'key'         => 'stock_opname_release',
                    'label'       => 'Release / Terapkan Opname',
                    'description' => 'Merilis dan menyesuaikan saldo stok di sistem berdasarkan hasil opname',
                ],
                'stock_transfer' => [
                    'key'         => 'stock_transfer',
                    'label'       => 'Kirim Transfer Antar Cabang',
                    'description' => 'Membuat surat jalan dan mengirim stok bahan ke cabang outlet lain',
                ],
                'stock_transfer_approve' => [
                    'key'         => 'stock_transfer_approve',
                    'label'       => 'Terima & Verifikasi Transfer',
                    'description' => 'Memvalidasi stok kiriman masuk dari cabang pengirim',
                ],
                'stock_waste' => [
                    'key'         => 'stock_waste',
                    'label'       => 'Catat Bahan Terbuang (Waste)',
                    'description' => 'Mencatat bahan basi, tumpah, atau rusak saat operasional',
                ],
                'stock_batch_prep' => [
                    'key'         => 'stock_batch_prep',
                    'label'       => 'Produksi Dapur (Batch Prep)',
                    'description' => 'Mencatat olahan setengah jadi (sirup, saus, adonan prep)',
                ],
                'stock_card_view' => [
                    'key'         => 'stock_card_view',
                    'label'       => 'Buku Kartu Stok & Mutasi',
                    'description' => 'Melihat alur mutasi kartu stok keluar masuk barang di gudang',
                ],
            ],
        ],
        'master' => [
            'id'          => 'master',
            'group_label' => 'Master Produk, Resep & Pelanggan',
            'icon'        => 'UtensilsCrossed',
            'description' => 'Wewenang konfigurasi katalog produk, bahan, harga, dan pelanggan',
            'permissions' => [
                'master_menu' => [
                    'key'         => 'master_menu',
                    'label'       => 'Master Menu, Resep & Harga',
                    'description' => 'Menambah dan mengedit produk, formula resep, varian harga & bundling',
                ],
                'master_ingredient' => [
                    'key'         => 'master_ingredient',
                    'label'       => 'Master Bahan Baku & HPP',
                    'description' => 'Mengatur data bahan baku mentah, satuan, dan target modal HPP',
                ],
                'master_perlengkapan' => [
                    'key'         => 'master_perlengkapan',
                    'label'       => 'Master Kemasan & Perlengkapan',
                    'description' => 'Mengatur cup, sedotan, kresek, dan barang kemasan operasional',
                ],
                'master_discount' => [
                    'key'         => 'master_discount',
                    'label'       => 'Master Promo & Diskon Toko',
                    'description' => 'Membuat promo diskon bertingkat, voucher kupon, dan diskon jam tertentu',
                ],
                'master_customer' => [
                    'key'         => 'master_customer',
                    'label'       => 'Kelola Member Pelanggan',
                    'description' => 'Melihat data nomor pelanggan, riwayat poin loyalitas, dan reward',
                ],
                'master_outlet' => [
                    'key'         => 'master_outlet',
                    'label'       => 'Profil Cabang Outlet',
                    'description' => 'Melihat dan memperbarui informasi identitas cabang outlet',
                ],
                'master_payment' => [
                    'key'         => 'master_payment',
                    'label'       => 'Pengaturan Rekening & QRIS',
                    'description' => 'Mengatur rekening bank penerima, QRIS statis, dan payment gateway',
                ],
            ],
        ],
        'finance' => [
            'id'          => 'finance',
            'group_label' => 'Keuangan, Kas & Akuntansi',
            'icon'        => 'Landmark',
            'description' => 'Wewenang pencatatan beban kas, laporan laba rugi, dan jurnal keuangan',
            'permissions' => [
                'finance_opex' => [
                    'key'         => 'finance_opex',
                    'label'       => 'Biaya Operasional (OPEX)',
                    'description' => 'Mencatat pengeluaran harian, listrik, air, gaji, dan kas kecil outlet',
                ],
                'finance_profit_loss' => [
                    'key'         => 'finance_profit_loss',
                    'label'       => 'Laporan Laba Rugi (P&L)',
                    'description' => 'Melihat ringkasan omset penjualan, HPP kotor, beban opex, dan laba bersih',
                ],
                'finance_cash_flow' => [
                    'key'         => 'finance_cash_flow',
                    'label'       => 'Laporan Arus Kas (Cash Flow)',
                    'description' => 'Melihat laporan arus kas masuk dan kas keluar operasional & pendanaan',
                ],
                'finance_kas_aplikasi' => [
                    'key'         => 'finance_kas_aplikasi',
                    'label'       => 'Rekonsiliasi Kas Aplikasi Online',
                    'description' => 'Mengecek saldo tertahan dan pencairan dari GrabFood, GoFood, ShopeeFood',
                ],
                'finance_neraca' => [
                    'key'         => 'finance_neraca',
                    'label'       => 'Laporan Neraca (Balance Sheet)',
                    'description' => 'Melihat posisi saldo aset, kewajiban hutang, dan modal ekuitas usaha',
                ],
                'finance_journal' => [
                    'key'         => 'finance_journal',
                    'label'       => 'Jurnal Umum & Buku Besar Akuntansi',
                    'description' => 'Melihat pembukuan debit-kredit jurnal umum dan audit jurnal abnormal',
                ],
                'finance_payables' => [
                    'key'         => 'finance_payables',
                    'label'       => 'Buku Hutang Supplier',
                    'description' => 'Melihat daftar hutang dagang supplier dan mencatat pelunasan cicilan',
                ],
                'finance_receivables' => [
                    'key'         => 'finance_receivables',
                    'label'       => 'Buku Piutang Pelanggan',
                    'description' => 'Melihat dan menagih piutang bon/pesanan pelanggan',
                ],
            ],
        ],
        'reports' => [
            'id'          => 'reports',
            'group_label' => 'Analisis Bisnis & Laporan',
            'icon'        => 'BarChart2',
            'description' => 'Wewenang analisis metrik bisnis, audit penjualan, dan laporan varian',
            'permissions' => [
                'analytics_dashboard' => [
                    'key'         => 'analytics_dashboard',
                    'label'       => 'Dashboard Metrik Bisnis',
                    'description' => 'Melihat grafik pendapatan, tren omset, dan ringkasan eksekutif cabang',
                ],
                'report_sales' => [
                    'key'         => 'report_sales',
                    'label'       => 'Laporan Penjualan Lengkap',
                    'description' => 'Melihat rincian seluruh riwayat penjualan dan ekspor ke Excel/PDF',
                ],
                'report_purchase' => [
                    'key'         => 'report_purchase',
                    'label'       => 'Laporan Pembelian Barang',
                    'description' => 'Melihat rekap belanja stok bahan baku dan perlengkapan',
                ],
                'analytics_variance' => [
                    'key'         => 'analytics_variance',
                    'label'       => 'Analisis Varian Bahan & Menu',
                    'description' => 'Mendeteksi selisih pemakaian bahan nyata di lapangan vs hitungan resep',
                ],
                'analytics_profitability' => [
                    'key'         => 'analytics_profitability',
                    'label'       => 'Profitability & Margin Engineering',
                    'description' => 'Melihat klasifikasi menu Stars, Plowhorses, Puzzles, dan margin laba',
                ],
                'analytics_root_cause' => [
                    'key'         => 'analytics_root_cause',
                    'label'       => 'Root Cause Analysis',
                    'description' => 'Menganalisis akar penyebab kebocoran biaya dan bahan',
                ],
            ],
        ],
        'users' => [
            'id'          => 'users',
            'group_label' => 'Pengguna & Hak Otoritas',
            'icon'        => 'Users',
            'description' => 'Wewenang administrasi kepegawaian, persetujuan akun, dan pembagian otoritas',
            'permissions' => [
                'manage_users' => [
                    'key'         => 'manage_users',
                    'label'       => 'Kelola Akun Pegawai',
                    'description' => 'Menambah akun baru, mengedit data pegawai, dan menyetujui akun pending',
                ],
                'manage_authority' => [
                    'key'         => 'manage_authority',
                    'label'       => 'Pengaturan Otoritas & Hak Akses',
                    'description' => 'Memberikan hak otoritas custom kepada akun di unit bisnis',
                ],
                'manage_coin_billing' => [
                    'key'         => 'manage_coin_billing',
                    'label'       => 'Billing & Koin Bisnis',
                    'description' => 'Melihat kuota koin transaksi dan melakukan pengajuan isi ulang',
                ],
            ],
        ],
    ];

    /**
     * Get flat array of all permission keys
     */
    public static function getAllPermissionKeys(): array
    {
        $keys = [];
        foreach (self::MODULES as $mod) {
            foreach ($mod['permissions'] as $pKey => $pDef) {
                $keys[] = $pKey;
            }
        }
        return $keys;
    }

    /**
     * Presets templates for quick 1-click authority assignment
     */
    public static function getPresets(): array
    {
        return [
            'kasir' => [
                'id'          => 'kasir',
                'name'        => 'Kasir Standar',
                'description' => 'Fokus penjualan POS, nota urgent, shift closing, dan input opname tanpa akses laporan keuangan.',
                'color'       => '#10b981',
                'permissions' => [
                    'pos_checkout',
                    'pos_open_bill',
                    'pos_split_bill',
                    'pos_shift_recap',
                    'pos_urgent_note',
                    'pos_void_request',
                    'stock_opname_input',
                    'master_customer',
                ],
            ],
            'barista_kitchen' => [
                'id'          => 'barista_kitchen',
                'name'        => 'Kitchen / Barista / Dapur',
                'description' => 'Fokus produksi batch dapar, waste tracking, terima/kirim transfer bahan, opname, dan cek kartu stok.',
                'color'       => '#f59e0b',
                'permissions' => [
                    'pos_checkout',
                    'pos_urgent_note',
                    'stock_opname_input',
                    'stock_transfer',
                    'stock_transfer_approve',
                    'stock_waste',
                    'stock_batch_prep',
                    'stock_card_view',
                ],
            ],
            'manager_outlet' => [
                'id'          => 'manager_outlet',
                'name'        => 'Manager Cabang Outlet',
                'description' => 'Wewenang penuh operasional cabang: approval void, release opname, transfer, OPEX kas kecil, & laporan penjualan.',
                'color'       => '#3b82f6',
                'permissions' => [
                    // POS
                    'pos_checkout',
                    'pos_custom_discount',
                    'pos_open_bill',
                    'pos_split_bill',
                    'pos_shift_recap',
                    'pos_shift_deposit_approval',
                    'pos_urgent_note',
                    'pos_void_request',
                    'pos_void_approve',
                    // Inventory
                    'stock_opname_input',
                    'stock_opname_release',
                    'stock_transfer',
                    'stock_transfer_approve',
                    'stock_waste',
                    'stock_batch_prep',
                    'stock_card_view',
                    // Master
                    'master_menu',
                    'master_ingredient',
                    'master_perlengkapan',
                    'master_discount',
                    'master_customer',
                    'master_outlet',
                    // Finance
                    'finance_opex',
                    'finance_kas_aplikasi',
                    'finance_payables',
                    'finance_receivables',
                    // Reports
                    'analytics_dashboard',
                    'report_sales',
                    'report_purchase',
                    'analytics_variance',
                    // Users
                    'manage_users',
                ],
            ],
            'supervisor_keuangan' => [
                'id'          => 'supervisor_keuangan',
                'name'        => 'Staff / Supervisor Keuangan',
                'description' => 'Akses lengkap laporan finansial, OPEX, kas aplikasi, buku hutang-piutang, laba rugi, dan jurnal umum.',
                'color'       => '#8b5cf6',
                'permissions' => [
                    'finance_opex',
                    'finance_profit_loss',
                    'finance_cash_flow',
                    'finance_kas_aplikasi',
                    'finance_neraca',
                    'finance_journal',
                    'finance_payables',
                    'finance_receivables',
                    'analytics_dashboard',
                    'report_sales',
                    'report_purchase',
                    'master_payment',
                ],
            ],
            'full_access' => [
                'id'          => 'full_access',
                'name'        => 'Semua Akses (Full Authority)',
                'description' => 'Memberikan seluruh hak akses dan wewenang fitur sistem MOVA POS tanpa batasan.',
                'color'       => '#ec4899',
                'permissions' => self::getAllPermissionKeys(),
            ],
        ];
    }

    /**
     * Get default permissions for a role when user's custom permissions is null
     */
    public static function getDefaultPermissionsForRole(?string $role): array
    {
        $role = strtolower(trim((string)$role));
        $all = self::getAllPermissionKeys();

        // Platform & Business Owner have full access
        if (in_array($role, ['owner_bisnis', 'owner', 'admin', 'owner_website', 'superadmin_platform', 'superadmin'])) {
            return $all;
        }

        $presets = self::getPresets();

        if (in_array($role, ['owner_outlet', 'manager_outlet', 'manager'])) {
            return $presets['manager_outlet']['permissions'];
        }

        if ($role === 'kasir') {
            return $presets['kasir']['permissions'];
        }

        // Default pegawai / staff
        return $presets['barista_kitchen']['permissions'];
    }
}
