<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('shift_name'); // e.g. "Shift 1 (Pagi)", "Shift 2 (Sore)"
            $table->string('start_time', 10)->nullable(); // e.g. "08:00"
            $table->string('end_time', 10)->nullable();   // e.g. "16:00"
            $table->json('assigned_user_ids')->nullable(); // Array of user IDs assigned to this shift
            $table->boolean('is_strict')->default(true);   // If true, only assigned users or owner can open this shift
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('shift_schedule_id')->nullable()->after('outlet_id')->constrained('shift_schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_schedule_id');
        });
        Schema::dropIfExists('shift_schedules');
    }
};
