<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ReceivablePayment;
use App\Models\Shift;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Perbaiki seluruh data pelunasan piutang yang keliru terhubung ke shift lama yang sudah ditutup sebelum pelunasan terjadi.
        // Pelunasan harus dihubungkan ke shift yang sedang berjalan saat pembayaran, atau ke shift berikutnya yang dibuka.
        $payments = ReceivablePayment::all();

        foreach ($payments as $p) {
            $currentShift = Shift::find($p->shift_id);
            if ($currentShift && $currentShift->closed_at && $p->created_at > $currentShift->closed_at) {
                // Cari shift yang sedang berjalan saat pembayaran dilakukan
                $targetShift = Shift::where('outlet_id', $p->outlet_id)
                    ->where('opened_at', '<=', $p->created_at)
                    ->where(function ($q) use ($p) {
                        $q->where('closed_at', '>=', $p->created_at)->orWhereNull('closed_at');
                    })
                    ->first();

                if (!$targetShift) {
                    // Jika tidak ada shift terbuka saat pembayaran, hubungkan ke shift selanjutnya yang dibuka setelah pembayaran
                    $targetShift = Shift::where('outlet_id', $p->outlet_id)
                        ->where('opened_at', '>=', $p->created_at)
                        ->orderBy('opened_at', 'asc')
                        ->first();
                }

                $p->update(['shift_id' => $targetShift ? $targetShift->id : null]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse needed
    }
};
