<?php
declare(strict_types=1);

function request_method(): string {
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function enforce_method(string $expectedMethod): void {
    if (request_method() !== strtoupper($expectedMethod)) {
        respond_with_json(['message' => 'Method not allowed'], 405);
    }
}

function ensure_user_role(array $user, array $roles, string $message = 'Forbidden'): void {
    $role = (string)($user['role'] ?? '');

    if (!in_array($role, $roles, true)) {
        respond_with_json(['message' => $message], 403);
    }
}

function respond_with_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function respond_with_exception(string $message, Throwable $exception, int $statusCode = 500, array $extra = []): void {
    respond_with_json(array_merge([
        'message' => $message,
        'error' => $exception->getMessage(),
    ], $extra), $statusCode);
}