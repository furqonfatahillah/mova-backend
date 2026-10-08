<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$biz = App\Models\Business::first();

$entries = App\Models\JournalEntry::where('business_id', $biz->id)->with('lines')->get();
echo "Total Journal Entries: " . $entries->count() . "\n";
foreach ($entries as $e) {
    echo "JE #{$e->id} ({$e->entry_no}): date={$e->entry_date}, type={$e->entry_type}, desc={$e->description}\n";
    foreach ($e->lines as $l) {
        echo "   -> Line #{$l->id}: account={$l->account_code}, outlet={$l->outlet_id}, D={$l->debit}, K={$l->credit}, memo={$l->memo}\n";
    }
}
