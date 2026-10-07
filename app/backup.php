<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const BACKUP_REQUEST_BYTES = 5 * 1024 * 1024;
// Leave room for the {"backup": ...} request wrapper.
const BACKUP_DOCUMENT_BYTES = BACKUP_REQUEST_BYTES - 1024;
const BACKUP_SERVICE_LIMIT = 5000;
const BACKUP_SENT_LIMIT = 5000;
const BACKUP_IMPORT_TTL = 600;

final class BackupConflictException extends RuntimeException {}

function backup_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function backup_object(mixed $value, array $required, array $optional = []): array
{
    if (!$value instanceof stdClass) {
        throw new InvalidArgumentException('Format objek backup tidak valid.');
    }
    $fields = get_object_vars($value);
    if (array_diff($required, array_keys($fields)) || array_diff(array_keys($fields), [...$required, ...$optional])) {
        throw new InvalidArgumentException('Kolom backup tidak lengkap atau tidak didukung.');
    }
    return $fields;
}

function backup_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** Validate without coercing values, dropping fields, or normalizing stored records. */
function validate_backup(mixed $document): array
{
    if (strlen(backup_json($document)) > BACKUP_DOCUMENT_BYTES) {
        throw new InvalidArgumentException('File backup melebihi batas 5 MB.');
    }
    $envelope = backup_object($document, ['format', 'version', 'exportedAt', 'data']);
    if ($envelope['format'] !== 'webpantau-backup' || $envelope['version'] !== 1) {
        throw new InvalidArgumentException('Format atau versi backup tidak didukung.');
    }
    $timestamp = $envelope['exportedAt'];
    if (!is_string($timestamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $timestamp)) {
        throw new InvalidArgumentException('Tanggal backup tidak valid.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
        throw new InvalidArgumentException('Tanggal backup tidak valid.');
    }
    $data = backup_object($envelope['data'], ['services', 'sent'], ['telegram']);
    if (!is_array($data['services']) || !array_is_list($data['services']) || count($data['services']) > BACKUP_SERVICE_LIMIT
        || !is_array($data['sent']) || !array_is_list($data['sent']) || count($data['sent']) > BACKUP_SENT_LIMIT) {
        throw new InvalidArgumentException('Backup maksimal 5.000 layanan dan 5.000 riwayat pengingat.');
    }
    $services = [];
    $ids = [];
    foreach ($data['services'] as $row) {
        $service = backup_object($row, ['id', 'name', 'client', 'website', 'provider', 'type', 'cycle', 'expires', 'cost', 'notes'], ['clientContact']);
        foreach ($service as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Semua isian layanan dalam backup harus berupa teks.');
            }
        }
        $id = $service['id'];
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D', $id) || isset($ids[$id])) {
            throw new InvalidArgumentException('ID layanan tidak valid atau berulang.');
        }
        $ids[$id] = true;
        $validated = validate_service($service);
        // validate_service is also used for forms, where trimming/URL normalization is useful.
        // A backup must already contain valid stored values so restoration is lossless.
        foreach ($validated as $key => $value) {
            if ($key === 'clientContact' && !array_key_exists($key, $service)) {
                continue;
            }
            if ($service[$key] !== $value) {
                throw new InvalidArgumentException('Backup memuat isian layanan yang belum valid.');
            }
        }
        $services[] = $service;
    }
    $markers = [];
    foreach ($data['sent'] as $marker) {
        if (!is_string($marker) || !preg_match('/^[A-Za-z0-9-]{1,64}:(\d{4}-\d{2}-\d{2}):(0|[1-9]\d{0,2})$/D', $marker, $matches)
            || !backup_valid_date($matches[1]) || (int) $matches[2] > 365 || isset($markers[$marker])) {
            throw new InvalidArgumentException('Riwayat pengingat tidak valid atau berulang.');
        }
        $markers[$marker] = true;
    }
    $data['services'] = $services;
    if (array_key_exists('telegram', $data)) {
        $cfg = backup_object($data['telegram'], ['token', 'chatId', 'enabled', 'days']);
        if (!is_string($cfg['token']) || strlen($cfg['token']) > 256
            || ($cfg['token'] !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/D', $cfg['token']))
            || !is_string($cfg['chatId']) || strlen($cfg['chatId']) > 128
            || ($cfg['chatId'] !== '' && !preg_match('/^(-?\d+|@[A-Za-z0-9_]{5,})$/D', $cfg['chatId']))
            || !is_bool($cfg['enabled']) || !is_array($cfg['days']) || !array_is_list($cfg['days']) || count($cfg['days']) > 12) {
            throw new InvalidArgumentException('Pengaturan Telegram dalam backup tidak valid.');
        }
        $days = [];
        foreach ($cfg['days'] as $day) {
            if (!is_int($day) || $day < 0 || $day > 365 || isset($days[$day])) {
                throw new InvalidArgumentException('Jadwal Telegram tidak valid atau berulang.');
            }
            $days[$day] = true;
        }
        if ($cfg['enabled'] && ($cfg['token'] === '' || $cfg['chatId'] === '' || !$cfg['days'])) {
            throw new InvalidArgumentException('Pengaturan Telegram aktif tidak lengkap.');
        }
        $data['telegram'] = $cfg;
    }
    $envelope['data'] = $data;
    return $envelope;
}

function make_backup(array $store, bool $includeTelegram = false): array
{
    $data = ['services' => $store['services'], 'sent' => $store['sent']];
    if ($includeTelegram) {
        $data['telegram'] = $store['telegram'];
    }
    $envelope = ['format' => 'webpantau-backup', 'version' => 1, 'exportedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'data' => $data];
    return validate_backup(json_decode(backup_json($envelope), false, 32, JSON_THROW_ON_ERROR));
}

function backup_revision(array $store): string
{
    return hash('sha256', backup_json([$store['services'], $store['telegram'], $store['sent']]));
}

function backup_directory(string $name): string
{
    if (!in_array($name, ['imports', 'backups'], true)) {
        throw new LogicException('Unknown backup directory.');
    }
    $directory = data_directory() . '/' . $name;
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory))
        || !chmod($directory, 0700)) {
        throw new RuntimeException('Direktori backup tidak dapat disiapkan.');
    }
    return $directory;
}

/** Exclusive create plus read-back verification; a failure never touches the active store. */
function backup_write_private(string $path, string $json): void
{
    $handle = fopen($path, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('File backup tidak dapat dibuat.');
    }
    $success = false;
    try {
        if (!chmod($path, 0600)) {
            throw new RuntimeException('Izin backup tidak dapat diatur.');
        }
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Backup tidak dapat ditulis lengkap.');
            }
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle)) || !rewind($handle)
            || stream_get_contents($handle) !== $json || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Backup tidak lolos pemeriksaan penyimpanan.');
        }
        $success = true;
    } finally {
        fclose($handle);
        if (!$success) {
            unlink($path);
        }
    }
}

function cleanup_staged_backups(string $directory): void
{
    foreach (glob($directory . '/*.json') ?: [] as $path) {
        if (!preg_match('/^[a-f0-9]{64}\.json$/D', basename($path)) || is_link($path) || !is_file($path)
            || filemtime($path) > time() - BACKUP_IMPORT_TTL || filesize($path) > BACKUP_REQUEST_BYTES) {
            continue;
        }
        try {
            $staged = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($staged) && ($staged['stageFormat'] ?? '') === 'webpantau-import'
                && is_int($staged['expires'] ?? null) && $staged['expires'] < time()) {
                unlink($path);
            }
        } catch (JsonException) {
            // Unrecognized files are not ours to delete.
        }
    }
}

function stage_backup(mixed $document, string $sessionId): array
{
    $envelope = validate_backup($document);
    $directory = backup_directory('imports');
    cleanup_staged_backups($directory);
    return with_store(function (array $store) use ($envelope, $sessionId, $directory): array {
        $id = bin2hex(random_bytes(32));
        $staged = ['stageFormat' => 'webpantau-import', 'expires' => time() + BACKUP_IMPORT_TTL,
            'session' => hash('sha256', $sessionId), 'revision' => backup_revision($store), 'backup' => $envelope];
        backup_write_private($directory . '/' . $id . '.json', backup_json($staged));
        return ['importId' => $id, 'serviceCount' => count($envelope['data']['services']),
            'currentServiceCount' => count($store['services']), 'includesTelegram' => isset($envelope['data']['telegram']),
            'exportedAt' => $envelope['exportedAt']];
    });
}

function import_backup(string $id, string $sessionId): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
        throw new InvalidArgumentException('ID impor tidak valid.');
    }
    $path = backup_directory('imports') . '/' . $id . '.json';
    if (is_link($path) || !is_file($path) || filesize($path) > BACKUP_REQUEST_BYTES) {
        throw new BackupConflictException('Pratinjau impor tidak tersedia. Pilih dan periksa file kembali.');
    }
    $stage = json_decode(file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
    if (!$stage instanceof stdClass || ($stage->stageFormat ?? '') !== 'webpantau-import'
        || !is_int($stage->expires ?? null) || $stage->expires <= time()
        || !is_string($stage->session ?? null) || !hash_equals($stage->session, hash('sha256', $sessionId))) {
        throw new BackupConflictException('Pratinjau impor kedaluwarsa atau sesi berbeda. Periksa file kembali.');
    }
    $envelope = validate_backup($stage->backup ?? null);
    $result = with_store(function (array &$store) use ($stage, $envelope): array {
        if (!is_string($stage->revision ?? null) || !hash_equals($stage->revision, backup_revision($store))) {
            throw new BackupConflictException('Data berubah setelah file diperiksa. Periksa file kembali sebelum mengganti data.');
        }
        $snapshot = make_backup($store, true);
        $name = 'before-import-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.json';
        backup_write_private(backup_directory('backups') . '/' . $name, backup_json($snapshot));
        $store['services'] = $envelope['data']['services'];
        $store['sent'] = $envelope['data']['sent'];
        $includesTelegram = isset($envelope['data']['telegram']);
        if ($includesTelegram) {
            $store['telegram'] = $envelope['data']['telegram'];
            $store['telegram']['enabled'] = false;
        }
        return ['ok' => true, 'serviceCount' => count($store['services']), 'recoveryBackup' => $name,
            'telegramDisabled' => $includesTelegram];
    }, true);
    unlink($path);
    return $result;
}
