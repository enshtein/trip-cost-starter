<?php

date_default_timezone_set('Europe/Moscow');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/var/log/app.log');

/** @return bool */
function app_is_api_request()
{
    return strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;
}

/** Выводит нейтральный ответ 500 для страницы или API. @return void */
function app_unexpected_error_response()
{
    if (headers_sent()) {
        return;
    }

    http_response_code(500);
    if (app_is_api_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Внутренняя ошибка сервера.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Ошибка сервера</title>';
    echo '<p>Произошла внутренняя ошибка. Попробуйте повторить действие позже.</p>';
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    error_log(sprintf('PHP error [%d]: %s in %s:%d', $severity, $message, $file, $line));
    return true;
});

set_exception_handler(function (Throwable $exception) {
    error_log(sprintf(
        'Uncaught %s: %s in %s:%d%s%s',
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        PHP_EOL,
        $exception->getTraceAsString()
    ));
    app_unexpected_error_response();
});

register_shutdown_function(function () {
    $error = error_get_last();
    $fatalErrors = array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR, E_RECOVERABLE_ERROR);
    if (!$error || !in_array($error['type'], $fatalErrors, true)) {
        return;
    }

    error_log(sprintf('Fatal PHP error [%d]: %s in %s:%d', $error['type'], $error['message'], $error['file'], $error['line']));
    app_unexpected_error_response();
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/export_1c.php';
require_once __DIR__ . '/trip_split.php';
