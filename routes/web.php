<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) {
        abort(404);
    }
    $file = file_get_contents($fullPath);
    $mime = mime_content_type($fullPath) ?: 'image/jpeg';
    return response($file, 200)
        ->header('Content-Type', $mime)
        ->header('Cache-Control', 'public, max-age=86400');
})->where('path', '.*');
