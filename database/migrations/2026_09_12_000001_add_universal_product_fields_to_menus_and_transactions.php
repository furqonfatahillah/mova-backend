<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            // RECIPE: F&B / Bill of Materials (pemotongan stok via resep bahan)
            // DIRECT: Barang Jadi / Retail (stok produk langsung, tanpa resep)
            // SERVICE: Jasa / Layanan / Non-Stok (selalu tersedia, tanpa potong stok)
            if (!Schema::hasColumn('menus', 'item_type')) {
                $table->string('item_type', 20)->default('RECIPE')->after('category');
            }
            if (!Schema::hasColumn('menus', 'track_stock')) {
                $table->boolean('track_stock')->default(true)->after('item_type');
            }
            if (!Schema::hasColumn('menus', 'stock')) {
                $table->decimal('stock', 15, 2)->default(0)->after('track_stock');
            }
            if (!Schema::hasColumn('menus', 'min_stock')) {
                $table->decimal('min_stock', 15, 2)->default(0)->after('stock');
            }
            if (!Schema::hasColumn('menus', 'cost_price')) {
                $table->decimal('cost_price', 15, 2)->default(0)->after('price');
            }
            if (!Schema::hasColumn('menus', 'unit')) {
                $table->string('unit', 20)->default('porsi')->after('cost_price');
            }
            if (!Schema::hasColumn('menus', 'barcode')) {
                $table->string('barcode', 50)->nullable()->index()->after('code');
            }
            if (!Schema::hasColumn('menus', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
        });

        // Tabel saldo stok barang retail / direct per outlet
        if (!Schema::hasTable('outlet_menus')) {
            Schema::create('outlet_menus', function (Blueprint $table) {
                $table->id();
                $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
                $table->decimal('stock', 15, 2)->default(0);
                $table->decimal('min_stock', 15, 2)->default(0);
                $table->decimal('price', 15, 2)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(['outlet_id', 'menu_id']);
            });
        }

        // Ubah recipe_version pada tabel transactions menjadi nullable
        Schema::table('transactions', function (Blueprint $table) {
            $table->integer('recipe_version')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_menus');

        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn([
                'item_type',
                'track_stock',
                'stock',
                'min_stock',
                'cost_price',
                'unit',
                'barcode',
                'description',
            ]);
        });
    }
};
