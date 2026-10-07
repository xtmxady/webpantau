<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/telegram.php';
require_once dirname(__DIR__) . '/app/backup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

try {
    start_session();
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    $body = [];
    if ($method !== 'GET') {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            respond(['error' => 'Gunakan JSON.'], 415);
        }
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            respond(['error' => 'Sesi formulir kedaluwarsa. Muat ulang halaman.'], 403);
        }
        $limit = $path === '/api/backup/validate' && $method === 'POST' ? BACKUP_REQUEST_BYTES : 32768;
        $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
        if (strlen($raw) > $limit) {
            respond(['error' => 'Permintaan terlalu besar.'], 413);
        }
        $body = json_decode($raw ?: '{}', true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            respond(['error' => 'Permintaan tidak valid.'], 400);
        }
    }
    $currentUser = read_store()['user'];
    $authenticated = isset($_SESSION['username'], $_SESSION['expires'])
        && $currentUser !== null && $_SESSION['username'] === $currentUser['username']
        && hash_equals($currentUser['version'] ?? '', $_SESSION['authVersion'] ?? '');
    if ($path === '/api/auth' && $method === 'GET') {
        $store = read_store();
        respond(['initialized' => $store['user'] !== null, 'authenticated' => $authenticated,
            'username' => $authenticated ? $_SESSION['username'] : null, 'csrf' => $_SESSION['csrf']]);
    }
    if ($path === '/api/auth/logout' && $method === 'POST') {
        $_SESSION = [];
        session_regenerate_id(true);
        respond(['ok' => true]);
    }
    if (in_array($path, ['/api/auth/setup', '/api/auth/login'], true) && $method === 'POST') {
        $username = $body['username'] ?? '';
        $password = $body['password'] ?? '';
        if (!is_string($username) || strlen($username) < 3 || strlen($username) > 60
            || !is_string($password) || strlen($password) < 12 || strlen($password) > 256) {
            respond(['error' => 'Nama pengguna minimal 3 karakter dan kata sandi minimal 12 karakter.'], 400);
        }
        $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $blocked = with_store(function (array &$store) use ($ip): bool {
            foreach ($store['attempts'] ?? [] as $key => $times) {
                $recent = array_values(array_filter($times, static fn(int $t): bool => $t > time() - 900));
                if ($recent) {
                    $store['attempts'][$key] = $recent;
                } else {
                    unset($store['attempts'][$key]);
                }
            }
            if (count($store['attempts'][$ip] ?? []) >= 10) {
                return true;
            }
            $store['attempts'][$ip][] = time();
            return false;
        }, true);
        if ($blocked) {
            respond(['error' => 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.'], 429);
        }
        if ($path === '/api/auth/setup') {
            $setupKey = getenv('SETUP_KEY') ?: '';
            if ($setupKey !== '' && !hash_equals($setupKey, $body['setupKey'] ?? '')) {
                respond(['error' => 'Kunci pendaftaran tidak cocok.'], 403);
            }
            $created = with_store(function (array &$store) use ($username, $password): bool {
                if ($store['user'] !== null) {
                    return false;
                }
                $store['user'] = ['username' => $username, 'passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'version' => bin2hex(random_bytes(16))];
                return true;
            }, true);
            if (!$created) {
                respond(['error' => 'Akun pemilik sudah dibuat.'], 409);
            }
        } else {
            $user = read_store()['user'];
            if (isset($user['hash']) && !isset($user['passwordHash'])) {
                respond(['error' => 'Data akun berasal dari versi Node.js. Ikuti panduan migrasi di README.'], 409);
            }
            if (!$user || !password_verify($password, $user['passwordHash']) || $username !== $user['username']) {
                respond(['error' => 'Nama pengguna atau kata sandi salah.'], 401);
            }
        }
        with_store(function (array &$store) use ($ip): void { unset($store['attempts'][$ip]); }, true);
        session_regenerate_id(true);
        $_SESSION['username'] = $username;
        $_SESSION['authVersion'] = read_store()['user']['version'] ?? '';
        $_SESSION['expires'] = time() + 604800;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        respond(['ok' => true]);
    }
    if (!$authenticated) {
        respond(['error' => 'Silakan masuk kembali.'], 401);
    }
    if ($path === '/api/backup/export' && $method === 'GET') {
        $includeTelegram = $_GET['includeTelegram'] ?? '0';
        if (!is_string($includeTelegram) || !in_array($includeTelegram, ['0', '1'], true)) {
            throw new InvalidArgumentException('Pilihan backup Telegram tidak valid.');
        }
        $backup = with_store(static fn(array $store): array => make_backup($store, $includeTelegram === '1'));
        header('Content-Disposition: attachment; filename="webpantau-backup-' . gmdate('Ymd-His') . '.json"');
        echo backup_json($backup);
        exit;
    }
    if ($path === '/api/backup/validate' && $method === 'POST') {
        $request = backup_object(json_decode($raw, false, 32, JSON_THROW_ON_ERROR), ['backup']);
        respond(stage_backup($request['backup'], session_id()));
    }
    if ($path === '/api/backup/import' && $method === 'POST') {
        $request = backup_object(json_decode($raw, false, 32, JSON_THROW_ON_ERROR), ['importId', 'confirm']);
        if (!is_string($request['importId']) || $request['confirm'] !== true) {
            throw new InvalidArgumentException('Konfirmasi penggantian data diperlukan.');
        }
        respond(import_backup($request['importId'], session_id()));
    }
    if ($path === '/api/services' && $method === 'GET') {
        respond(read_store()['services']);
    }
    if ($path === '/api/services' && $method === 'POST') {
        $service = validate_service($body);
        $service['id'] = bin2hex(random_bytes(16));
        with_store(function (array &$store) use ($service): void { $store['services'][] = $service; }, true);
        respond($service, 201);
    }
    if (preg_match('~^/api/services/([a-zA-Z0-9-]+)$~', $path, $matches) && in_array($method, ['PUT', 'DELETE'], true)) {
        $id = $matches[1];
        $service = $method === 'PUT' ? validate_service($body) + ['id' => $id] : null;
        $found = with_store(function (array &$store) use ($id, $service): bool {
            foreach ($store['services'] as $index => $row) {
                if ($row['id'] === $id) {
                    if ($service) {
                        $store['services'][$index] = $service;
                    } else {
                        array_splice($store['services'], $index, 1);
                    }
                    return true;
                }
            }
            return false;
        }, true);
        respond($found ? ['ok' => true] : ['error' => 'Layanan tidak ditemukan.'], $found ? 200 : 404);
    }
    if ($path === '/api/telegram' && $method === 'GET') {
        $telegram = read_store()['telegram'];
        $telegram['hasToken'] = $telegram['token'] !== '';
        unset($telegram['token']);
        respond($telegram);
    }
    if ($path === '/api/telegram' && $method === 'PUT') {
        $token = $body['token'] ?? '';
        $chat = $body['chatId'] ?? '';
        $days = $body['days'] ?? null;
        $enabled = $body['enabled'] ?? null;
        if (!is_string($token) || ($token !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token))
            || !is_string($chat) || ($chat !== '' && !preg_match('/^(-?\d+|@[A-Za-z0-9_]{5,})$/', $chat))
            || !is_bool($enabled) || !is_array($days) || count($days) > 12
            || array_filter($days, static fn($d): bool => !is_int($d) || $d < 0 || $d > 365)) {
            throw new InvalidArgumentException('Token, chat ID, atau jadwal pengingat tidak valid.');
        }
        with_store(function (array &$store) use ($token, $chat, $days, $enabled): void {
            $next = ['token' => $token ?: $store['telegram']['token'], 'chatId' => $chat,
                'enabled' => $enabled, 'days' => array_values(array_unique($days))];
            rsort($next['days']);
            if ($enabled && (!$next['token'] || !$chat || !$days)) {
                throw new InvalidArgumentException('Isi token, chat ID, dan jadwal sebelum mengaktifkan pengingat.');
            }
            $store['telegram'] = $next;
        }, true);
        respond(['ok' => true]);
    }
    if ($path === '/api/telegram/test' && $method === 'POST') {
        // Release the session lock while waiting for the external API.
        session_write_close();
        try {
            send_telegram(read_store()['telegram'], '✓ Webpantau terhubung. Pengingat domain dan server akan dikirim ke chat ini.');
            respond(['ok' => true]);
        } catch (RuntimeException $error) {
            respond(['error' => $error->getMessage()], 400);
        }
    }
    respond(['error' => 'Endpoint tidak ditemukan.'], 404);
} catch (BackupConflictException $error) {
    respond(['error' => $error->getMessage()], 409);
} catch (InvalidArgumentException | JsonException $error) {
    respond(['error' => $error instanceof JsonException ? 'Format JSON tidak valid.' : $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('Webpantau request failed: ' . get_class($error));
    respond(['error' => 'Terjadi kesalahan server. Periksa izin dan integritas file data.'], 500);
}
