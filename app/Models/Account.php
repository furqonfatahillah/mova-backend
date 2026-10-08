<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'business_id',
        'outlet_id',
        'code',
        'parent_code',
        'name',
        'type',
        'category',
        'normal_balance',
        'description',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_code', 'code');
    }

    public function subAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_code', 'code');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Get 2-digit outlet suffix based on order of creation in the business.
     */
    public static function getOutletSuffix(int $businessId, int $outletId): string
    {
        $outlets = Outlet::where('business_id', $businessId)->orderBy('id', 'asc')->pluck('id')->toArray();
        $pos = array_search($outletId, $outlets);
        $idx = ($pos !== false) ? ($pos + 1) : 1;
        return sprintf('%02d', $idx);
    }

    /**
     * Build sub-account code for a specific outlet.
     */
    public static function getSubAccountCode(string $parentCode, int $businessId, ?int $outletId): string
    {
        if (!$outletId) {
            return $parentCode;
        }
        $suffix = static::getOutletSuffix($businessId, $outletId);
        return "{$parentCode}.{$suffix}";
    }

    /**
     * Standard Template Chart of Accounts (COA) - Sesuai Master Akun Resmi
     */
    public static function defaultAccounts(): array
    {
        return [
            // 1. ASET (ASSETS)
            [
                'code'           => '1-11001',
                'name'           => 'KAS BESAR',
                'type'           => 'ASSET',
                'category'       => 'CASH',
                'normal_balance' => 'DEBIT',
                'description'    => 'Akun Kas Besar yang menampung setoran kasir outlet, kas brankas utama holding, dan dana yang dipegang manajer/owner.',
            ],
            [
                'code'           => '1-11002',
                'name'           => 'KAS KECIL KASIR',
                'type'           => 'ASSET',
                'category'       => 'CASH',
                'normal_balance' => 'DEBIT',
                'description'    => 'Akun Kas Kecil operasional di outlet/laci kasir (modal awal kasir + penerimaan penjualan tunai - pengeluaran kas kecil outlet).',
            ],
            [
                'code'           => '1-11003',
                'name'           => 'BANK',
                'type'           => 'ASSET',
                'category'       => 'BANK',
                'normal_balance' => 'DEBIT',
                'description'    => 'Rekening Bank operasional (BCA/Mandiri/BRI/BNI) untuk mutasi transfer, pencairan QRIS, dan transaksi non-tunai.',
            ],
            [
                'code'           => '1-11004',
                'name'           => 'KAS APLIKASI',
                'type'           => 'ASSET',
                'category'       => 'CASH',
                'normal_balance' => 'DEBIT',
                'description'    => 'Akun Kas Aplikasi yang menampung penerimaan transaksi non-tunai QRIS & E-Commerce / Online Delivery (GrabFood, GoFood, ShopeeFood) sebelum disetor/cair ke rekening bank operasional.',
            ],
            [
                'code'           => '1-12001',
                'name'           => 'PIUTANG USAHA',
                'type'           => 'ASSET',
                'category'       => 'RECEIVABLE',
                'normal_balance' => 'DEBIT',
                'description'    => 'Penjualan yang terjadi saat ada customer yang kasbon',
            ],
            [
                'code'           => '1-13001',
                'name'           => 'PERSEDIAAN BAHAN BAKU',
                'type'           => 'ASSET',
                'category'       => 'INVENTORY',
                'normal_balance' => 'DEBIT',
                'description'    => 'Akun yang nilainya berdasarkan nilai yang ada di menu kartu stok',
            ],
            [
                'code'           => '1-13002',
                'name'           => 'PERSEDIAAN PERLENGKAPAN',
                'type'           => 'ASSET',
                'category'       => 'INVENTORY',
                'normal_balance' => 'DEBIT',
                'description'    => 'Akun yang nilainya berdasarkan nilai yang ada di menu kartu stok',
            ],
            [
                'code'           => '1-13003',
                'name'           => 'PERSEDIAAN DALAM PERJALANAN',
                'type'           => 'ASSET',
                'category'       => 'INVENTORY',
                'normal_balance' => 'DEBIT',
                'description'    => 'Akun persediaan saat pembelian/transfer barang dalam jeda pengiriman. Bertambah saat barang dikirim dan berkurang saat barang telah diterima oleh cabang/pusat.',
            ],

            // 2. LIABILITAS (LIABILITIES)
            [
                'code'           => '2-21001',
                'name'           => 'HUTANG USAHA',
                'type'           => 'LIABILITY',
                'category'       => 'PAYABLE',
                'normal_balance' => 'CREDIT',
                'description'    => 'Akun yang nilainya sesuai dengan buku besar hutang (supplier) + jika gudang membeli persediaan/perlengkapan/biaya opex secara hutang)/- jika cabang telah membayar hutang tsb',
            ],
            [
                'code'           => '2-21002',
                'name'           => 'PENDAPATAN DITERIMA DI MUKA',
                'type'           => 'LIABILITY',
                'category'       => 'DEPOSIT',
                'normal_balance' => 'CREDIT',
                'description'    => 'Uang muka yang diterima oleh customer jika membayar tetapi belum memperoleh produk yang dijual',
            ],

            // 3. EKUITAS (EQUITY)
            [
                'code'           => '3-31001',
                'name'           => 'MODAL',
                'type'           => 'EQUITY',
                'category'       => 'CAPITAL',
                'normal_balance' => 'CREDIT',
                'description'    => 'Akun awal yang akan terkoneksi pada saat input data awal aset (kas, bank, ar penjualan, piutang usaha, persediaan bahan baku, persediaan perlengkapan)',
            ],
            [
                'code'           => '3-31002',
                'name'           => 'PRIVE',
                'type'           => 'EQUITY',
                'category'       => 'WITHDRAWAL',
                'normal_balance' => 'DEBIT',
                'description'    => 'Pengambilan pribadi owner dari kas/bank',
            ],
            [
                'code'           => '3-31003',
                'name'           => 'LABA PERIODE BERJALAN',
                'type'           => 'EQUITY',
                'category'       => 'CURRENT_PROFIT',
                'normal_balance' => 'CREDIT',
                'description'    => 'Nilainya akan sesuai dengan perhitungan di laporan laba/rugi',
            ],

            // 4. PENDAPATAN (REVENUE)
            [
                'code'           => '4-41001',
                'name'           => 'PENDAPATAN PENJUALAN PRODUK',
                'type'           => 'REVENUE',
                'category'       => 'SALES',
                'normal_balance' => 'CREDIT',
                'description'    => 'Nilainya akan bertambah sesuai dengan transaksi yang dilakukan oleh kasir (penjualan secara tunai, qris, bank, kasbon,) nilainya akan berkurang jika terdapat nota void',
            ],
            [
                'code'           => '4-41002',
                'name'           => 'PENDAPATAN LAIN-LAIN',
                'type'           => 'REVENUE',
                'category'       => 'OTHER_INCOME',
                'normal_balance' => 'CREDIT',
                'description'    => 'Pendapatan lain-lain dari POS (selisih lebih kasir dan pendapatan non-operasional lainnya)',
            ],
            [
                'code'           => '4-41003',
                'name'           => 'POTONGAN PENJUALAN DISKON',
                'type'           => 'REVENUE',
                'category'       => 'SALES_DISCOUNT',
                'normal_balance' => 'DEBIT',
                'description'    => 'Jika ada member yang mengklaim diskon, atau ada master diskon yang ditambahkan kemudian kasir menginput diskon pada saat transaksi',
            ],

            // 5. BIAYA POKOK PENJUALAN (HPP / COGS)
            [
                'code'           => '5-51001',
                'name'           => 'BIAYA BAHAN BAKU',
                'type'           => 'EXPENSE',
                'category'       => 'COGS',
                'normal_balance' => 'DEBIT',
                'description'    => 'Nilainya akan terkoneksi berdasarkan produk yang terjual (produk telah mempunyai resep gramasi).',
            ],
            [
                'code'           => '5-51002',
                'name'           => 'BIAYA KEMASAN DAN PACKAGING',
                'type'           => 'EXPENSE',
                'category'       => 'COGS',
                'normal_balance' => 'DEBIT',
                'description'    => 'Nilainya akan terkoneksi berdasarkan produk yang terjual (produk telah mempunyai resep gramasi).',
            ],
            [
                'code'           => '5-51003',
                'name'           => 'BIAYA KERUGIAN/WASTE BAHAN',
                'type'           => 'EXPENSE',
                'category'       => 'WASTE_LOSS',
                'normal_balance' => 'DEBIT',
                'description'    => 'Nilainya akan bertambah jika admin mencatat di kartu stok sebagai waste',
            ],
            [
                'code'           => '5-51006',
                'name'           => 'BIAYA KERUGIAN/WASTE PERLENGKAPAN',
                'type'           => 'EXPENSE',
                'category'       => 'WASTE_LOSS',
                'normal_balance' => 'DEBIT',
                'description'    => 'Nilainya akan bertambah jika admin mencatat di kartu stok sebagai waste untuk perlengkapan / kemasan',
            ],
            [
                'code'           => '5-51004',
                'name'           => 'BIAYA SELISIH PERSEDIAAN BAHAN BAKU',
                'type'           => 'EXPENSE',
                'category'       => 'OPNAME_DEFICIT',
                'normal_balance' => 'DEBIT',
                'description'    => 'Nilainya akan bertambah/berkurang apabila terjadi selisih stok opname (lebih/kurang)',
            ],
            [
                'code'           => '5-51005',
                'name'           => 'BIAYA SELISIH PERLENGKAPAN',
                'type'           => 'EXPENSE',
                'category'       => 'OPNAME_DEFICIT',
                'normal_balance' => 'DEBIT',
                'description'    => 'Nilainya akan bertambah/berkurang apabila terjadi selisih stok opname (lebih/kurang)',
            ],

            // 6. BIAYA OPERASIONAL (OPEX)
            [
                'code'           => '6-61001',
                'name'           => 'BIAYA GAJI KARYAWAN',
                'type'           => 'EXPENSE',
                'category'       => 'OPEX',
                'normal_balance' => 'DEBIT',
                'description'    => 'Pada saat penginputan biaya opex, jika admin memilih sesuai',
            ],
            [
                'code'           => '6-61002',
                'name'           => 'BIAYA LISTRIK',
                'type'           => 'EXPENSE',
                'category'       => 'OPEX',
                'normal_balance' => 'DEBIT',
                'description'    => 'Pada saat penginputan biaya opex, jika admin memilih sesuai',
            ],
            [
                'code'           => '6-61003',
                'name'           => 'BIAYA AIR',
                'type'           => 'EXPENSE',
                'category'       => 'OPEX',
                'normal_balance' => 'DEBIT',
                'description'    => 'Pada saat penginputan biaya opex, jika admin memilih sesuai',
            ],
            [
                'code'           => '6-61004',
                'name'           => 'BIAYA GAS',
                'type'           => 'EXPENSE',
                'category'       => 'OPEX',
                'normal_balance' => 'DEBIT',
                'description'    => 'Pada saat penginputan biaya opex, jika admin memilih sesuai',
            ],
            [
                'code'           => '6-61005',
                'name'           => 'BIAYA PEMELIHARAAN ASET',
                'type'           => 'EXPENSE',
                'category'       => 'OPEX',
                'normal_balance' => 'DEBIT',
                'description'    => 'Pada saat penginputan biaya opex, jika admin memilih sesuai',
            ],
            [
                'code'           => '6-61006',
                'name'           => 'BIAYA MARKETING',
                'type'           => 'EXPENSE',
                'category'       => 'OPEX',
                'normal_balance' => 'DEBIT',
                'description'    => 'Pada saat penginputan biaya opex, jika admin memilih sesuai',
            ],
            [
                'code'           => '6-61007',
                'name'           => 'BIAYA LOGISTIK & TRANSPORTASI',
                'type'           => 'EXPENSE',
                'category'       => 'OPEX',
                'normal_balance' => 'DEBIT',
                'description'    => 'Pada saat penginputan biaya opex, jika admin memilih sesuai',
            ],
            [
                'code'           => '6-61008',
                'name'           => 'BIAYA LAIN-LAIN',
                'type'           => 'EXPENSE',
                'category'       => 'OTHER_EXPENSE',
                'normal_balance' => 'DEBIT',
                'description'    => 'Biaya lain-lain dari POS (selisih kurang kasir dan beban non-operasional lainnya)',
            ],
            [
                'code'           => '6-61009',
                'name'           => 'BIAYA OPERASIONAL LAINNYA',
                'type'           => 'EXPENSE',
                'category'       => 'OPEX',
                'normal_balance' => 'DEBIT',
                'description'    => 'Biaya lain-lainnya',
            ],
        ];
    }

    /**
     * Ensure default accounts & branch sub-accounts exist for a business.
     */
    public static function seedForBusiness(int $businessId): void
    {
        $defaults = static::defaultAccounts();
        $outlets = Outlet::where('business_id', $businessId)->orderBy('id', 'asc')->get();

        // 0. Clean up obsolete legacy receivable accounts (AR QRIS and AR ECOMMERCE unified into 1-11004 KAS APLIKASI)
        $obsoleteCodes = ['1-12003', '1-12004', '1-12005', '1-12006', '1-12007'];
        static::where('business_id', $businessId)
            ->where(function($q) use ($obsoleteCodes) {
                $q->whereIn('code', $obsoleteCodes);
                foreach ($obsoleteCodes as $oc) {
                    $q->orWhere('code', 'like', "{$oc}.%");
                }
            })
            ->delete();

        foreach ($defaults as $acc) {
            // 1. Head Account (Akun Induk)
            $head = static::firstOrCreate(
                [
                    'business_id' => $businessId,
                    'code'        => $acc['code'],
                ],
                [
                    'parent_code'    => null,
                    'outlet_id'      => null,
                    'name'           => $acc['name'],
                    'type'           => $acc['type'],
                    'category'       => $acc['category'],
                    'normal_balance' => $acc['normal_balance'],
                    'description'    => isset($acc['description']) ? mb_substr($acc['description'], 0, 250) : null,
                    'is_system'      => true,
                    'is_active'      => true,
                ]
            );

            // 2. Child Sub-Accounts per Outlet (Anak Akun Cabang)
            foreach ($outlets as $index => $outlet) {
                $suffix = sprintf('%02d', $index + 1);
                $subCode = "{$acc['code']}.{$suffix}";
                $subName = "{$acc['name']} ({$outlet->name})";

                $sub = static::firstOrCreate(
                    [
                        'business_id' => $businessId,
                        'code'        => $subCode,
                    ],
                    [
                        'parent_code'    => $acc['code'],
                        'outlet_id'      => $outlet->id,
                        'name'           => $subName,
                        'type'           => $acc['type'],
                        'category'       => $acc['category'],
                        'normal_balance' => $acc['normal_balance'],
                        'description'    => "Sub-Akun cabang {$outlet->name} untuk {$acc['name']}",
                        'is_system'      => true,
                        'is_active'      => true,
                    ]
                );

                if ($sub->parent_code !== $acc['code'] || $sub->outlet_id !== $outlet->id) {
                    $sub->update([
                        'parent_code' => $acc['code'],
                        'outlet_id'   => $outlet->id,
                    ]);
                }
            }
        }

        // Auto-link any custom sub-accounts with dot notation (e.g. XXXXX.01) to parent and outlet
        $dotAccounts = static::where('business_id', $businessId)->where('code', 'like', '%.%')->get();
        foreach ($dotAccounts as $da) {
            $parts = explode('.', $da->code, 2);
            $pCode = $parts[0];
            $sIdx = (int)$parts[1];
            $targetOutletId = ($sIdx > 0 && isset($outlets[$sIdx - 1])) ? $outlets[$sIdx - 1]->id : null;
            if ($da->parent_code !== $pCode || ($targetOutletId && $da->outlet_id !== $targetOutletId)) {
                $da->update([
                    'parent_code' => $pCode,
                    'outlet_id'   => $targetOutletId ?: $da->outlet_id,
                ]);
            }
        }
    }
}

