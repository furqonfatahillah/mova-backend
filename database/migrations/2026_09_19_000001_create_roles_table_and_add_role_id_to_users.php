<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create master 'roles' table
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50)->unique();
                $table->string('label', 100);
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('level')->default(10);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // 2. Insert standard master roles
            $now = now();
            $defaultRoles = [
                [
                    'name'        => 'superadmin_platform',
                    'label'       => 'Superadmin Platform',
                    'description' => 'Akses tertinggi ke seluruh platform SaaS, pengelolaan tenant, dan lisensi cloud MOVA.',
                    'level'       => 100,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'owner_website',
                    'label'       => 'Owner Website / SaaS Provider',
                    'description' => 'Penyedia layanan SaaS MOVA POS dengan hak supervisi tenant.',
                    'level'       => 95,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'owner_bisnis',
                    'label'       => 'Owner Bisnis (Tenant)',
                    'description' => 'Pemilik perusahaan/usaha penyewa MOVA POS, wewenang penuh atas seluruh cabang, menu, HPP, keuangan, dan release opname.',
                    'level'       => 80,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'owner',
                    'label'       => 'Owner Usaha',
                    'description' => 'Alias pemilik usaha (kompatibilitas data tenant).',
                    'level'       => 80,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'admin',
                    'label'       => 'Administrator Usaha',
                    'description' => 'Administrator tingkat usaha/perusahaan.',
                    'level'       => 75,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'owner_outlet',
                    'label'       => 'Owner Cabang Outlet',
                    'description' => 'Pemilik atau kepala pengelola cabang outlet tertentu.',
                    'level'       => 50,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'manager_outlet',
                    'label'       => 'Manager Cabang Outlet',
                    'description' => 'Manager operasional cabang outlet.',
                    'level'       => 40,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'manager',
                    'label'       => 'Manager Operasional',
                    'description' => 'Manager operasional (kompatibilitas data cabang).',
                    'level'       => 40,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'pegawai',
                    'label'       => 'Pegawai Operasional',
                    'description' => 'Staff operasional cabang (dapur, gudang, input stok).',
                    'level'       => 20,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'name'        => 'kasir',
                    'label'       => 'Kasir POS',
                    'description' => 'Staff kasir penjualan dan pencatatan transaksi POS.',
                    'level'       => 10,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
            ];

            DB::table('roles')->insert($defaultRoles);
        }

        // 3. Add role_id column to users table
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('role_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('roles')
                    ->nullOnDelete();
            });

            // 4. Backfill existing users' role_id based on their current 'role' string
            $roleMap = DB::table('roles')->pluck('id', 'name')->toArray();

            $users = DB::table('users')->select('id', 'role')->get();
            foreach ($users as $u) {
                $rName = strtolower(trim((string)$u->role));
                $matchedRoleId = $roleMap[$rName]
                    ?? ($rName === 'superadmin' ? ($roleMap['superadmin_platform'] ?? null) : null)
                    ?? ($roleMap['pegawai'] ?? null);

                if ($matchedRoleId) {
                    DB::table('users')
                        ->where('id', $u->id)
                        ->update(['role_id' => $matchedRoleId]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('role_id');
            });
        }

        Schema::dropIfExists('roles');
    }
};
