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
        // 1. Create urgent_notes table
        Schema::create('urgent_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->default(1)->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            $table->string('order_number')->index();

            // Link to menu & ingredient
            $table->unsignedBigInteger('menu_id')->nullable()->index();
            $table->unsignedBigInteger('ingredient_id')->nullable()->index();
            $table->string('item_type', 30)->default('RECIPE'); // RECIPE, DIRECT, MODIFIER, BUNDLE
            $table->string('item_name'); // e.g. "Daging Ayam (Ayam Geprek x1)"

            // Stock quantities
            $table->decimal('required_qty', 15, 3)->default(0); // e.g. 150g
            $table->decimal('deducted_qty', 15, 3)->default(0); // e.g. 100g (fisik terpotong saat transaksi)
            $table->decimal('pending_qty', 15, 3)->default(0);  // e.g. 50g (kekurangan yang tergantung)
            $table->string('unit', 50)->default('gram');

            // Status: PENDING (Tergantung), RESOLVED (Lunas/Selesai), CANCELLED
            $table->string('status', 30)->default('PENDING')->index();
            $table->text('notes')->nullable();

            // Resolution info
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolution_notes')->nullable();
            $table->unsignedBigInteger('resolution_movement_id')->nullable();

            // Audit
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
            $table->foreign('outlet_id')->references('id')->on('outlets')->onDelete('set null');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('menu_id')->references('id')->on('menus')->onDelete('set null');
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->onDelete('set null');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        // 2. Add is_urgent_note & urgent_status to transactions table if not exists
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('transactions', 'is_urgent_note')) {
                    $table->boolean('is_urgent_note')->default(false)->after('payment_method')->index();
                }
                if (!Schema::hasColumn('transactions', 'urgent_status')) {
                    $table->string('urgent_status', 30)->default('NONE')->after('is_urgent_note')->index(); // NONE, PENDING, RESOLVED
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('urgent_notes');

        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (Schema::hasColumn('transactions', 'is_urgent_note')) {
                    $table->dropColumn('is_urgent_note');
                }
                if (Schema::hasColumn('transactions', 'urgent_status')) {
                    $table->dropColumn('urgent_status');
                }
            });
        }
    }
};
