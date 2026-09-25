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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->string('bank_name', 100);
            $table->string('bank_code', 20)->nullable();
            $table->string('account_number', 50);
            $table->string('account_holder', 255);
            $table->string('branch', 255)->nullable();
            $table->text('qr_image_url')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
            $table->foreign('outlet_id')->references('id')->on('outlets')->onDelete('set null');
        });

        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->unique();
            $table->string('active_gateway', 30)->default('none'); // none, midtrans, xendit
            $table->string('environment', 20)->default('sandbox');  // sandbox, production
            $table->boolean('enable_qris')->default(true);
            $table->boolean('enable_va')->default(true);

            // Midtrans credentials
            $table->text('midtrans_server_key')->nullable();
            $table->text('midtrans_client_key')->nullable();
            $table->string('midtrans_merchant_id', 100)->nullable();

            // Xendit credentials
            $table->text('xendit_secret_key')->nullable();
            $table->text('xendit_public_key')->nullable();
            $table->text('xendit_webhook_token')->nullable();

            // Fee & settlement settings
            $table->string('qris_fee_absorbed_by', 20)->default('merchant'); // merchant, customer
            $table->string('va_fee_absorbed_by', 20)->default('customer');   // merchant, customer
            $table->boolean('auto_settlement')->default(true);

            // Diagnostic & Ping
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 30)->nullable();
            $table->text('last_test_message')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_settings');
        Schema::dropIfExists('bank_accounts');
    }
};
