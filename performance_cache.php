<?php

function performance_cache_path(): string
{
    return __DIR__ . '/cache/private/performance_dashboard.json';
}

function performance_cache_dir(): string
{
    return dirname(performance_cache_path());
}

function performance_cache_read(): ?array
{
    $path = performance_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $payload = json_decode((string)file_get_contents($path), true);
    return is_array($payload) ? $payload : null;
}

function performance_cache_write(array $payload): void
{
    $directory = performance_cache_dir();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder cache performa gagal dibuat: ' . $directory);
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Folder cache performa tidak bisa ditulis oleh PHP/web server: ' . $directory);
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Data cache performa gagal diubah menjadi JSON.');
    }

    $path = performance_cache_path();
    $temporaryPath = $path . '.tmp';
    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('File cache performa sementara gagal ditulis.');
    }
    if (!@rename($temporaryPath, $path)) {
        $backupPath = $path . '.bak';
        @unlink($backupPath);
        $hasOldCache = is_file($path);
        if ($hasOldCache && !@rename($path, $backupPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Cache lama tidak dapat dipindahkan saat mengaktifkan cache baru.');
        }
        if (!@rename($temporaryPath, $path)) {
            if ($hasOldCache && is_file($backupPath)) {
                @rename($backupPath, $path);
            }
            @unlink($temporaryPath);
            throw new RuntimeException('File cache performa gagal diaktifkan.');
        }
        @unlink($backupPath);
    }
}

function performance_cache_generated_label(?array $cache): string
{
    $value = (string)($cache['generated_at'] ?? '');
    if ($value === '') {
        return '-';
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return '-';
    }
    static $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    return $date->format('d') . ' ' . ($months[$date->format('m')] ?? $date->format('m'))
        . ' ' . $date->format('Y H:i') . ' WITA';
}

function performance_cache_is_today(?array $cache): bool
{
    $value = (string)($cache['generated_at'] ?? '');
    if ($value === '') {
        return false;
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d') === today();
    } catch (Throwable $e) {
        return false;
    }
}
