<?php
require_once __DIR__ . '/../config/db.php';

/**
 * Get Authorization header reliably across environments.
 */
function getAuthorizationHeader(): ?string {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $value = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
        if ($value !== '') {
            return $value;
        }
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === 'authorization') {
                $trimmed = trim((string)$value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }
    }

    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $value = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

/**
 * Extract bearer token from header. Case-insensitive.
 */
function extractBearerToken(?string $authHeader): ?string {
    if ($authHeader === null || trim($authHeader) === '') {
        return null;
    }

    if (!preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authHeader, $matches)) {
        return null;
    }

    $token = trim((string)($matches[1] ?? ''));
    return $token === '' ? null : $token;
}

/**
 * Authenticate user via Bearer token.
 */
function authenticate(PDO $pdo): array {
    $authHeader = getAuthorizationHeader();

    if ($authHeader === null) {
        http_response_code(401);
        echo json_encode(['message' => 'Authorization header missing']);
        exit;
    }

    $token = extractBearerToken($authHeader);
    if ($token === null) {
        http_response_code(401);
        echo json_encode(['message' => 'Invalid token format']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT users.id, users.role
             FROM api_tokens
             INNER JOIN users ON users.id = api_tokens.user_id
             WHERE api_tokens.token = ?
             LIMIT 1'
        );

        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);
            echo json_encode(['message' => 'Invalid token']);
            exit;
        }

        return $user;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'message' => 'Authentication error',
            'error' => $e->getMessage(),
        ]);
        exit;
    }
}