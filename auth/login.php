<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';

// ---------- INPUT ----------
$input = json_decode(file_get_contents("php://input"), true);

$email = trim($input['email'] ?? '');
$password = trim($input['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "code" => "MISSING_FIELDS"
    ]);
    exit;
}

// ---------- QUERY ----------
$stmt = $pdo->prepare(   // ✅ FIX HERE
    "SELECT id, email, password, role FROM users WHERE email = ? LIMIT 1"
);
$stmt->execute([$email]);

$user = $stmt->fetch();

// ---------- USER NOT FOUND ----------
if (!$user) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "code" => "USER_NOT_FOUND"
    ]);
    exit;
}

// ---------- PASSWORD CHECK ----------
if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "code" => "WRONG_PASSWORD"
    ]);
    exit;
}

// ---------- TOKEN ----------
$token = bin2hex(random_bytes(32));

$stmt = $pdo->prepare(   // ✅ FIX HERE
    "INSERT INTO api_tokens (user_id, token) VALUES (?, ?)"
);
$stmt->execute([$user['id'], $token]);

// ---------- RESPONSE ----------
echo json_encode([
    "status" => "success",
    "token" => $token,
    "role" => strtolower($user['role']),
    "user_id" => (int)$user['id'],
]);