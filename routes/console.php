<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('stock:recalculate-moving-averages', function () {
    $this->info('Recalculating Moving Averages for all ingredients across all outlets...');
    $ingredients = \App\Models\Ingredient::all();
    $outlets = \App\Models\Outlet::all();
    $mainOutlet = $outlets->firstWhere('is_main', true) ?: $outlets->first();

    foreach ($ingredients as $ing) {
        $visited = [];
        if ($mainOutlet) {
            $ing->recomputeMovingAverageFromHistory((int)$mainOutlet->id, $visited);
        }
        foreach ($outlets as $out) {
            if (!in_array((int)$out->id, $visited)) {
                $ing->recomputeMovingAverageFromHistory((int)$out->id, $visited);
            }
        }
    }
    $this->info('Completed successfully! All transfer prices and destination moving averages are in sync.');
})->purpose('Recalculate Moving Averages and cascade transfers for all ingredients');

