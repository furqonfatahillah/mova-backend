<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Outlet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        // Update owner to owner_website
        $owner = User::where('email', 'owner@maroa.com')->orWhere('email', 'admin@posmaroa.id')->first();
        if ($owner) {
            $owner->update([
                'role'   => 'owner_website',
                'status' => 'active',
            ]);
        }

        $pettarani = Outlet::where('code', 'OUT-002')->first();
        $hertasning = Outlet::where('code', 'OUT-003')->first();

        // Create sample Owner Outlet for Pettarani
        if ($pettarani) {
            User::updateOrCreate(
                ['email' => 'ownerpettarani@posmaroa.id'],
                [
                    'name'        => 'Pak Hendra (Owner Cabang Pettarani)',
                    'password'    => Hash::make('password123'),
                    'role'        => 'owner_outlet',
                    'outlet_id'   => $pettarani->id,
                    'status'      => 'active',
                    'approved_at' => now(),
                ]
            );
        }

        // Create sample Owner Outlet for Hertasning
        if ($hertasning) {
            User::updateOrCreate(
                ['email' => 'ownerhertasning@posmaroa.id'],
                [
                    'name'        => 'Ibu Dewi (Owner Cabang Hertasning)',
                    'password'    => Hash::make('password123'),
                    'role'        => 'owner_outlet',
                    'outlet_id'   => $hertasning->id,
                    'status'      => 'active',
                    'approved_at' => now(),
                ]
            );
        }
    }
}
