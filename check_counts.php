<?php

$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
foreach (['pos_maroa', 'db_kasir', 'penjualan', 'laravel'] as $db) {
    try {
        $count = $pdo->query("SELECT count(*) FROM `{$db}`.transactions")->fetchColumn();
        echo "Database {$db}: {$count} transactions\n";
    } catch (\Exception $e) {
        echo "Database {$db}: " . $e->getMessage() . "\n";
    }
}
