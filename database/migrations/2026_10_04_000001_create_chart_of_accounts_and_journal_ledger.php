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
        // 1. Chart of Accounts (COA / Akun Pembukuan)
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->string('code', 20)->index();
            $table->string('name', 120);
            $table->enum('type', ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'])->index();
            $table->string('category', 50)->nullable()->index();
            $table->enum('normal_balance', ['DEBIT', 'CREDIT'])->default('DEBIT');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code'], 'uq_accounts_biz_code');
        });

        // 2. Header Jurnal Umum (Journal Entries)
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->string('entry_no', 60)->index();
            $table->date('entry_date')->index();
            $table->string('entry_type', 50)->index();
            $table->string('reference_type', 50)->nullable()->index();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->decimal('total_debit', 15, 2)->default(0);
            $table->decimal('total_credit', 15, 2)->default(0);
            $table->boolean('is_balanced')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'entry_date'], 'idx_je_biz_date');
            $table->index(['outlet_id', 'entry_date'], 'idx_je_outlet_date');
            $table->index(['reference_type', 'reference_id'], 'idx_je_ref');
        });

        // 3. Baris Jurnal Debit/Kredit (Journal Lines)
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->onDelete('cascade');
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->string('account_code', 20)->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->date('entry_date')->index();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->index(['account_code', 'entry_date'], 'idx_jl_code_date');
            $table->index(['outlet_id', 'account_code', 'entry_date'], 'idx_jl_outlet_code_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
