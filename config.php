<?php
function app_env_load(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }
    return $values;
}

$APP_ENV = app_env_load(__DIR__ . '/.env');

function app_env(string $key, string $default = ''): string {
    global $APP_ENV;
    return array_key_exists($key, $APP_ENV) ? $APP_ENV[$key] : $default;
}

$DB_HOST = app_env('DB_HOST', '127.0.0.1');
$DB_NAME = app_env('DB_NAME', 'dailyse2026');
$DB_USER = app_env('DB_USER', 'root');
$DB_PASS = app_env('DB_PASS', '');
$APP_NAME = app_env('APP_NAME', 'Daily SE 2026');
$APP_TIMEZONE = app_env('APP_TIMEZONE', 'Asia/Makassar');

date_default_timezone_set($APP_TIMEZONE);
