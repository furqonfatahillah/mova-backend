<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambahkan softDeletes pada tabel users jika belum ada
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->softDeletes()->after('updated_at');
            });
        }

        // 2. Buat tabel users_old untuk mengarsipkan data user yang dihapus
        if (!Schema::hasTable('users_old')) {
            Schema::create('users_old', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('original_user_id')->index();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('outlet_id')->nullable()->index();
                $table->unsignedBigInteger('role_id')->nullable();
                $table->string('role')->nullable();
                $table->string('name');
                $table->string('email');
                $table->string('phone')->nullable();
                $table->string('status')->nullable();
                $table->string('referral_code')->nullable();
                $table->longText('snapshot_data')->nullable()->comment('JSON snapshot seluruh atribut akun');
                $table->unsignedBigInteger('deleted_by')->nullable()->index()->comment('User ID eksekutor penghapus');
                $table->text('deletion_reason')->nullable();
                $table->timestamp('original_created_at')->nullable();
                $table->timestamp('deleted_at')->useCurrent();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_old');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
