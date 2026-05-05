<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/api.php';

$payload = read_json_input();
$token = trim((string)($payload['token'] ?? ''));
$newPassword = trim((string)($payload['password'] ?? ''));

if ($token === '' || $newPassword === '') {
    respond_with_json(['message' => 'Invalid request'], 400);
}

$tokenHash = hash('sha256', $token);
$currentTime = date('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    '
    SELECT *
    FROM password_resets
    WHERE token_hash = ?
      AND used = 0
      AND expires_at > ?
'
);
$stmt->execute([$tokenHash, $currentTime]);
$reset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reset) {
    respond_with_json(['message' => 'Invalid or expired token'], 400);
}

$passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

$pdo->prepare(
    '
    UPDATE users
    SET password = ?
    WHERE id = ?
'
)->execute([$passwordHash, (int)$reset['user_id']]);

$pdo->prepare(
    '
    UPDATE password_resets
    SET used = 1
    WHERE id = ?
'
)->execute([(int)$reset['id']]);

respond_with_json(['message' => 'Password updated successfully']);