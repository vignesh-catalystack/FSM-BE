<?php
declare(strict_types=1);

// ---------- HEADERS ----------
if (!headers_sent()) {
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// ---------- DB CONFIG ----------
$host = "127.0.0.1";
$db   = "new_fsm";
$user = "root";
$pass = "";
$charset = "utf8mb4";

// ---------- DSN ----------
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// ---------- OPTIONS ----------
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // ✅ FIX HERE
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}