<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah default subsidy di template platform_promos
        if (Schema::hasTable('platform_promos')) {
            Schema::table('platform_promos', function (Blueprint $table) {
                if (!Schema::hasColumn('platform_promos', 'default_subsidy_amount')) {
                    $table->decimal('default_subsidy_amount', 15, 2)->default(0)->after('default_usage_limit');
                }
            });
        }

        // 2. Tambah kolom approval & kompensasi di platform_promo_businesses
        if (Schema::hasTable('platform_promo_businesses')) {
            Schema::table('platform_promo_businesses', function (Blueprint $table) {
                if (!Schema::hasColumn('platform_promo_businesses', 'approval_status')) {
                    $table->enum('approval_status', ['PENDING', 'ACCEPTED', 'REJECTED'])->default('PENDING')->after('status');
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approval_status');
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'approved_by')) {
                    $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'rejected_at')) {
                    $table->timestamp('rejected_at')->nullable()->after('approved_by');
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'rejected_by')) {
                    $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('rejected_by');
                }

                // Financial & compensation fields
                if (!Schema::hasColumn('platform_promo_businesses', 'amount_payable')) {
                    $table->decimal('amount_payable', 15, 2)->default(0)->after('rejection_reason');
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'amount_paid')) {
                    $table->decimal('amount_paid', 15, 2)->default(0)->after('amount_payable');
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'payment_status')) {
                    $table->enum('payment_status', ['UNPAID', 'PARTIALLY_PAID', 'PAID'])->default('UNPAID')->after('amount_paid');
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'last_payment_date')) {
                    $table->date('last_payment_date')->nullable()->after('payment_status');
                }
                if (!Schema::hasColumn('platform_promo_businesses', 'payment_notes')) {
                    $table->text('payment_notes')->nullable()->after('last_payment_date');
                }
            });

            // Set existing active distributions to ACCEPTED so they are backward-compatible
            DB::table('platform_promo_businesses')
                ->where('status', 'ACTIVE')
                ->update(['approval_status' => 'ACCEPTED']);
        }

        // 3. Tabel log pembayaran kompensasi dari platform ke bisnis
        if (!Schema::hasTable('platform_promo_payments')) {
            Schema::create('platform_promo_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('platform_promo_business_id')->constrained('platform_promo_businesses')->cascadeOnDelete();
                $table->decimal('amount', 15, 2);
                $table->date('payment_date');
                $table->string('payment_method', 50)->nullable()->default('Transfer Bank');
                $table->string('reference_no', 100)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_promo_payments');

        if (Schema::hasTable('platform_promo_businesses')) {
            Schema::table('platform_promo_businesses', function (Blueprint $table) {
                if (Schema::hasColumn('platform_promo_businesses', 'rejected_by')) {
                    $table->dropForeign(['rejected_by']);
                }
                if (Schema::hasColumn('platform_promo_businesses', 'approved_by')) {
                    $table->dropForeign(['approved_by']);
                }
                $cols = [
                    'payment_notes', 'last_payment_date', 'payment_status', 'amount_paid', 'amount_payable',
                    'rejection_reason', 'rejected_by', 'rejected_at', 'approved_by', 'approved_at', 'approval_status'
                ];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('platform_promo_businesses', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('platform_promos')) {
            Schema::table('platform_promos', function (Blueprint $table) {
                if (Schema::hasColumn('platform_promos', 'default_subsidy_amount')) {
                    $table->dropColumn('default_subsidy_amount');
                }
            });
        }
    }
};
