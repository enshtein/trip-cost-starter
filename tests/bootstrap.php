<?php

$testDbFile = sys_get_temp_dir() . '/trip-cost-starter-phpunit.sqlite';
if (file_exists($testDbFile)) {
    unlink($testDbFile);
}

putenv('APP_DB_FILE=' . $testDbFile);

$pdo = new PDO('sqlite:' . $testDbFile);
$pdo->exec(file_get_contents(__DIR__ . '/../data/schema.sql'));

require_once __DIR__ . '/../lib/bootstrap.php';
