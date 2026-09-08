<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;

class OutletSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();
        $adminId = $admin?->id;

        $outlets = [
            [
                'code'       => 'OUT-001',
                'name'       => 'Outlet Pusat (Gudang Utama)',
                'address'    => 'Jl. Boulevard No. 1, Panakkukang, Makassar',
                'phone'      => '0812-3456-7890',
                'pic_name'   => 'Budi Santoso (Head Warehouse)',
                'is_main'    => true,
                'active'     => true,
                'created_by' => $adminId,
            ],
            [
                'code'       => 'OUT-002',
                'name'       => 'Outlet Cabang Pettarani',
                'address'    => 'Jl. A.P. Pettarani No. 45, Rappocini, Makassar',
                'phone'      => '0813-9876-5432',
                'pic_name'   => 'Andi Pratama (Store Manager)',
                'is_main'    => false,
                'active'     => true,
                'created_by' => $adminId,
            ],
            [
                'code'       => 'OUT-003',
                'name'       => 'Outlet Cabang Hertasning',
                'address'    => 'Jl. Letjen Hertasning No. 12, Rappocini, Makassar',
                'phone'      => '0811-2233-4455',
                'pic_name'   => 'Siti Rahma (Store Manager)',
                'is_main'    => false,
                'active'     => true,
                'created_by' => $adminId,
            ],
        ];

        foreach ($outlets as $data) {
            Outlet::updateOrCreate(['code' => $data['code']], $data);
        }
    }
}
