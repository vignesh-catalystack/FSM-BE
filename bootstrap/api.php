<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/http.php';
require_once __DIR__ . '/../support/request.php';
require_once __DIR__ . '/../config/db.php';

/*
|--------------------------------------------------------------------------
| GLOBAL HEADERS
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| TIMEZONE (CRITICAL FOR TRACKING)
|--------------------------------------------------------------------------
*/

date_default_timezone_set('UTC');

/*
|--------------------------------------------------------------------------
| PDO HARDENING (IMPORTANT)
|--------------------------------------------------------------------------
*/

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| RESPONSE HELPER (if not already defined)
|--------------------------------------------------------------------------
*/

if (!function_exists('respond_with_json')) {
    function respond_with_json(array $data, int $status = 200): void {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}