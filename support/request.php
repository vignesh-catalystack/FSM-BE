<?php
declare(strict_types=1);

function read_json_input(): array {
    static $cachedBody;

    if ($cachedBody !== null) {
        return $cachedBody;
    }

    $decoded = json_decode(file_get_contents('php://input'), true);
    $cachedBody = is_array($decoded) ? $decoded : [];

    return $cachedBody;
}

function request_sources(): array {
    return [
        read_json_input(),
        $_POST,
    ];
}

function request_int_value(array $sources, array $keys, int $default = 0): int {
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            return (int)$source[$key];
        }
    }

    return $default;
}

function request_string_value(array $sources, array $keys, string $default = ''): string {
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            return trim((string)$source[$key]);
        }
    }

    return $default;
}

function request_float_value(array $sources, array $keys): ?float {
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $raw = $source[$key];
            if ($raw === null || $raw === '') {
                continue;
            }

            if (is_numeric($raw)) {
                return (float)$raw;
            }
        }
    }

    return null;
}

function request_datetime_value(array $sources, array $keys): ?string {
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $raw = trim((string)$source[$key]);
            if ($raw === '') {
                continue;
            }

            try {
                $date = new DateTimeImmutable($raw);

                return $date
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable $exception) {
                continue;
            }
        }
    }

    return null;
}

function query_int_value(string $key, ?int $default = null): ?int {
    if (!array_key_exists($key, $_GET)) {
        return $default;
    }

    $raw = trim((string)$_GET[$key]);
    if ($raw === '') {
        return $default;
    }

    return (int)$raw;
}