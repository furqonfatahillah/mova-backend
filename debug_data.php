<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "DB Name: " . Illuminate\Support\Facades\DB::connection()->getDatabaseName() . "\n";
echo "Transactions count: " . App\Models\Transaction::count() . "\n";
echo "Receivable payments count: " . App\Models\ReceivablePayment::count() . "\n";
echo "Shifts count: " . App\Models\Shift::count() . "\n";

foreach (App\Models\Transaction::all() as $t) {
    echo "Trx #{$t->id} | {$t->invoice_number} | status:{$t->status} | method:{$t->payment_method} | total:{$t->total_price} | paid:{$t->amount_paid} | shift:{$t->shift_id}\n";
}

foreach (App\Models\ReceivablePayment::all() as $rp) {
    echo "RP #{$rp->id} | {$rp->payment_number} | method:{$rp->payment_method} | amount:{$rp->amount} | shift:{$rp->shift_id}\n";
}

foreach (App\Models\Shift::all() as $s) {
    echo "Shift #{$s->id} | {$s->shift_name} | status:{$s->status} | initial:{$s->initial_cash} | closing:{$s->closing_cash} | sys:{$s->system_cash}\n";
}
