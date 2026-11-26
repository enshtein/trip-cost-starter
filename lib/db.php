<?php

/**
 * Возвращает единственное подключение к рабочей SQLite-базе.
 *
 * @return PDO
 */
function db()
{
    static $pdo = null;

    if ($pdo === null) {
        $file = getenv('APP_DB_FILE') ?: __DIR__ . '/../data/app.sqlite';
        if (!file_exists($file)) {
            die('База не создана. Запустите: php bin/reset-db.php');
        }
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    return $pdo;
}
