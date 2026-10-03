<?php

$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
$dbs = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
echo "DATABASES:\n";
print_r($dbs);
