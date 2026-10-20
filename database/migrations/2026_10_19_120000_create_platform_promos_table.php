<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Table platform_promos: Template promo yang dibuat oleh Superadmin Platform
        if (!Schema::hasTable('platform_promos')) {
            Schema::create('platform_promos', function (Blueprint $table) {
                $table->id();
                $table->string('title', 150);
                $table->string('code', 50)->unique();
                $table->text('description')->nullable();
                $table->enum('type', ['PERCENTAGE', 'FIXED'])->default('PERCENTAGE');
                $table->decimal('value', 15, 2)->default(0);
                $table->decimal('min_order_amount', 15, 2)->default(0);
                $table->decimal('max_discount_amount', 15, 2)->nullable();
                $table->integer('default_usage_limit')->default(50);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('banner_color', 30)->default('#4F46E5');
                $table->string('badge_text', 50)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // 2. Table platform_promo_businesses: Distribusi promo ke masing-masing bisnis
        if (!Schema::hasTable('platform_promo_businesses')) {
            Schema::create('platform_promo_businesses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('platform_promo_id')->constrained('platform_promos')->cascadeOnDelete();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
                $table->integer('usage_limit')->default(50);
                $table->integer('used_count')->default(0);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->enum('status', ['ACTIVE', 'EXPIRED', 'QUOTA_DEPLETED', 'REVOKED'])->default('ACTIVE');
                $table->text('notes')->nullable();
                $table->timestamp('distributed_at')->useCurrent();
                $table->foreignId('distributed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['platform_promo_id', 'business_id'], 'uniq_promo_business');
            });
        }

        // 3. Tambahkan kolom relasi platform_promo ke tabel discounts jika belum ada
        if (Schema::hasTable('discounts')) {
            Schema::table('discounts', function (Blueprint $table) {
                if (!Schema::hasColumn('discounts', 'platform_promo_id')) {
                    $table->foreignId('platform_promo_id')->nullable()->after('business_id')->constrained('platform_promos')->nullOnDelete();
                }
                if (!Schema::hasColumn('discounts', 'is_platform_promo')) {
                    $table->boolean('is_platform_promo')->default(false)->after('platform_promo_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('discounts')) {
            Schema::table('discounts', function (Blueprint $table) {
                if (Schema::hasColumn('discounts', 'platform_promo_id')) {
                    $table->dropForeign(['platform_promo_id']);
                    $table->dropColumn('platform_promo_id');
                }
                if (Schema::hasColumn('discounts', 'is_platform_promo')) {
                    $table->dropColumn('is_platform_promo');
                }
            });
        }

        Schema::dropIfExists('platform_promo_businesses');
        Schema::dropIfExists('platform_promos');
    }
};
