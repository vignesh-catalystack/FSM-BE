<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';

$payload = read_json_input();
$email = trim((string)($payload['email'] ?? ''));

if ($email === '') {
    respond_with_json(['message' => 'Email required'], 400);
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    respond_with_json(['message' => 'If email exists, reset link sent']);
}

$pdo->prepare(
    '
    UPDATE password_resets
    SET used = 1
    WHERE user_id = ?
'
)->execute([(int)$user['id']]);

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', strtotime('+20 minutes'));

$stmt = $pdo->prepare(
    '
    INSERT INTO password_resets (user_id, token_hash, expires_at)
    VALUES (?, ?, ?)
'
);
$stmt->execute([(int)$user['id'], $tokenHash, $expires]);

respond_with_json([
    'message' => 'Reset link generated',
    'debug_token' => $token,
]);