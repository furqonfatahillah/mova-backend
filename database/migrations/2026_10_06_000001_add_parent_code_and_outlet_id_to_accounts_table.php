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
        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'parent_code')) {
                $table->string('parent_code', 20)->nullable()->after('code')->index();
            }
            if (!Schema::hasColumn('accounts', 'outlet_id')) {
                $table->unsignedBigInteger('outlet_id')->nullable()->after('business_id')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'outlet_id')) {
                $table->dropColumn('outlet_id');
            }
            if (Schema::hasColumn('accounts', 'parent_code')) {
                $table->dropColumn('parent_code');
            }
        });
    }
};
