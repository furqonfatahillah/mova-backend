<?php

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=pos_maroa', 'root', '');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $count = $pdo->query("SELECT count(*) FROM `{$table}`")->fetchColumn();
    if ($count > 0) {
        echo "Table {$table}: {$count} rows\n";
    }
}
