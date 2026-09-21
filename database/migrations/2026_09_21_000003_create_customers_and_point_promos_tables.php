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
        // 1. Table `customers` (Master Member)
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->string('code', 30)->nullable();
                $table->string('name', 100);
                $table->string('phone', 30);
                $table->string('email', 100)->nullable();
                $table->text('address')->nullable();
                $table->date('birth_date')->nullable();
                $table->integer('total_points')->default(0);
                $table->integer('total_visits')->default(0);
                $table->decimal('total_spent', 15, 2)->default(0);
                $table->date('joined_at')->nullable();
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'phone']);
                $table->index(['business_id', 'code']);
                $table->index(['business_id', 'active']);
            });
        }

        // 2. Table `point_redemptions` (Riwayat Penukaran Poin Member)
        if (!Schema::hasTable('point_redemptions')) {
            Schema::create('point_redemptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
                $table->string('order_number', 40)->nullable();
                $table->integer('points_used')->default(0);
                $table->string('description', 255);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'customer_id']);
                $table->index(['business_id', 'order_number']);
            });
        }

        // 3. Add customer_id to `transactions`
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('transactions', 'customer_id')) {
                    $table->foreignId('customer_id')->nullable()->after('customer_name')->constrained('customers')->nullOnDelete();
                }
            });
        }

        // 4. Add point promo columns to `discounts`
        if (Schema::hasTable('discounts')) {
            Schema::table('discounts', function (Blueprint $table) {
                if (!Schema::hasColumn('discounts', 'requires_points')) {
                    $table->integer('requires_points')->nullable()->after('value');
                }
                if (!Schema::hasColumn('discounts', 'reward_type')) {
                    $table->string('reward_type', 30)->default('DISCOUNT')->after('requires_points'); // DISCOUNT or FREE_MENU
                }
                if (!Schema::hasColumn('discounts', 'reward_menu_id')) {
                    $table->foreignId('reward_menu_id')->nullable()->after('reward_type')->constrained('menus')->nullOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('discounts')) {
            Schema::table('discounts', function (Blueprint $table) {
                if (Schema::hasColumn('discounts', 'reward_menu_id')) {
                    $table->dropForeign(['reward_menu_id']);
                    $table->dropColumn('reward_menu_id');
                }
                if (Schema::hasColumn('discounts', 'reward_type')) {
                    $table->dropColumn('reward_type');
                }
                if (Schema::hasColumn('discounts', 'requires_points')) {
                    $table->dropColumn('requires_points');
                }
            });
        }

        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (Schema::hasColumn('transactions', 'customer_id')) {
                    $table->dropForeign(['customer_id']);
                    $table->dropColumn('customer_id');
                }
            });
        }

        Schema::dropIfExists('point_redemptions');
        Schema::dropIfExists('customers');
    }
};
