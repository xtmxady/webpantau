<?php

declare(strict_types=1);

umask(0077);
date_default_timezone_set(getenv('TZ') ?: 'Asia/Makassar');

function data_directory(): string
{
    $directory = getenv('DATA_DIR') ?: dirname(__DIR__) . '/data';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Direktori data tidak dapat dibuat.');
    }
    $resolved = realpath($directory);
    $public = realpath(dirname(__DIR__) . '/public');
    if ($resolved === false || $resolved === $public || str_starts_with($resolved, $public . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('DATA_DIR harus berada di luar public.');
    }
    return $resolved;
}

function empty_store(): array
{
    return [
        'user' => null,
        'services' => [],
        'telegram' => ['token' => '', 'chatId' => '', 'enabled' => false, 'days' => [30, 14, 7, 3, 0]],
        'sent' => [],
        'attempts' => [],
    ];
}

/** A separate lock file stays stable while the JSON file is atomically replaced. */
function with_store(callable $callback, bool $write = false): mixed
{
    $directory = data_directory();
    $lock = fopen($directory . '/dashboard.lock', 'c');
    if ($lock === false || !flock($lock, $write ? LOCK_EX : LOCK_SH)) {
        throw new RuntimeException('Data tidak dapat dikunci.');
    }
    $temporary = null;
    try {
        $file = $directory . '/dashboard.json';
        $store = file_exists($file)
            ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
            : empty_store();
        if (!is_array($store) || !array_key_exists('user', $store) || !isset($store['services'], $store['telegram'], $store['sent'])) {
            throw new RuntimeException('Format data tidak valid.');
        }
        $result = $callback($store);
        if ($write) {
            $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $temporary = tempnam($directory, '.dashboard-');
            if ($temporary === false || file_put_contents($temporary, $json) !== strlen($json)) {
                throw new RuntimeException('Data tidak dapat disimpan.');
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $file)) {
                throw new RuntimeException('Data tidak dapat diganti.');
            }
        }
        return $result;
    } finally {
        if ($temporary !== null && file_exists($temporary)) {
            unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function read_store(): array
{
    return with_store(static fn(array $store): array => $store);
}

function days_remaining(string $date, ?DateTimeImmutable $now = null): int
{
    $timezone = new DateTimeZone(date_default_timezone_get());
    $today = ($now ?: new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
    $expiry = new DateTimeImmutable($date, $timezone);
    return (int) $today->diff($expiry)->format('%r%a');
}

function start_session(): void
{
    $directory = data_directory() . '/sessions';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '604800');
    session_save_path($directory);
    session_name('webpantau_session');
    session_set_cookie_params([
        'lifetime' => 604800,
        'path' => '/',
        'secure' => getenv('COOKIE_SECURE') === 'true' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (isset($_SESSION['expires']) && $_SESSION['expires'] < time()) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function validate_service(array $body): array
{
    $service = [];
    foreach (['name', 'client', 'clientContact', 'website', 'provider', 'type', 'cycle', 'expires', 'cost', 'notes'] as $key) {
        $service[$key] = is_string($body[$key] ?? null) ? trim($body[$key]) : '';
        if (strlen($service[$key]) > 2000) {
            throw new InvalidArgumentException('Isian terlalu panjang.');
        }
    }
    if (!is_string($body['clientContact'] ?? '') || strlen($service['clientContact']) > 200) {
        throw new InvalidArgumentException('Kontak klien maksimal 200 karakter.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $service['expires']);
    if ($service['name'] === '' || $service['client'] === '' || strlen($service['name']) > 120
        || strlen($service['client']) > 120 || !in_array($service['type'], ['domain', 'server'], true)
        || !in_array($service['cycle'], ['monthly', 'yearly'], true) || !$date
        || $date->format('Y-m-d') !== $service['expires'] || !is_numeric($service['cost'])
        || !is_finite((float) $service['cost']) || (float) $service['cost'] < 0) {
        throw new InvalidArgumentException('Lengkapi nama, klien, tanggal, dan biaya yang valid.');
    }
    if ($service['website'] !== '') {
        if (!preg_match('~^https?://~i', $service['website'])) {
            $service['website'] = 'https://' . $service['website'];
        }
        if (!filter_var($service['website'], FILTER_VALIDATE_URL)
            || !in_array(strtolower(parse_url($service['website'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL website tidak valid.');
        }
    }
    return $service;
}
