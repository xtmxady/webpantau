<?php

declare(strict_types=1);

$directory = sys_get_temp_dir() . '/webpantau-backup-' . bin2hex(random_bytes(8));
putenv('DATA_DIR=' . $directory);
require dirname(__DIR__) . '/app/backup.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function rejects(callable $callback, string $class = InvalidArgumentException::class): void
{
    try { $callback(); } catch (Throwable $error) {
        expect($error instanceof $class, 'Unexpected exception: ' . get_class($error));
        return;
    }
    throw new RuntimeException('Expected rejection');
}

function document(array $value): mixed
{
    return json_decode(backup_json($value), false, 32, JSON_THROW_ON_ERROR);
}

function fixture(): array
{
    $store = empty_store();
    $store['user'] = ['username' => 'owner', 'passwordHash' => 'must-stay-private', 'version' => 'v1'];
    $store['attempts'] = ['local' => [time()]];
    $store['services'] = [['id' => 'service-123', 'name' => 'example.com', 'client' => 'Toko Satu',
        'clientContact' => '+62 812-0000 / owner@example.com', 'website' => 'https://example.com',
        'provider' => 'Example registrar', 'type' => 'domain', 'cycle' => 'yearly', 'expires' => '2027-10-07',
        'cost' => '125000', 'notes' => 'Perpanjang sebelum jatuh tempo.']];
    $store['sent'] = ['service-123:2027-10-07:30', 'deleted-service:2026-10-07:0'];
    $store['telegram'] = ['token' => '123456:abcdefghijklmnopqrstuvwxyz', 'chatId' => '-10012345678', 'enabled' => true, 'days' => [30, 7, 0]];
    return $store;
}

function reset_store(): void
{
    with_store(static function (array &$store): void { $store = fixture(); }, true);
}

function remove_tree(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_dir($path) && !is_link($path)) { remove_tree($path); } else { unlink($path); }
    }
    rmdir($directory);
}

$tests = [
    'Export omits account and token by default; optional Telegram and legacy contact roundtrip' => function (): void {
        $source = fixture();
        $plain = make_backup($source);
        expect(array_keys($plain['data']) === ['services', 'sent'], 'Unexpected private data');
        expect(!str_contains(backup_json($plain), 'must-stay-private'), 'Password exported');
        expect(!str_contains(backup_json($plain), $source['telegram']['token']), 'Token exported by default');
        $full = make_backup($source, true);
        expect($full['data']['telegram'] === $source['telegram'], 'Telegram changed');
        expect(validate_backup(document($full)) === $full, 'Roundtrip changed data');
        unset($source['services'][0]['clientContact']);
        expect(!array_key_exists('clientContact', make_backup($source)['data']['services'][0]), 'Legacy contact changed');
    },
    'Malformed fields, coercions, IDs, dates, limits, and object/list confusion are rejected' => function (): void {
        $base = make_backup(fixture(), true);
        $bad = [];
        $copy = $base; $copy['version'] = '1'; $bad[] = $copy;
        $copy = $base; $copy['version'] = 2; $bad[] = $copy;
        $copy = $base; $copy['data']['user'] = ['username' => 'other']; $bad[] = $copy;
        $copy = $base; $copy['exportedAt'] = '2027-02-30T00:00:00Z'; $bad[] = $copy;
        $copy = $base; $copy['data']['services'][] = $copy['data']['services'][0]; $bad[] = $copy;
        foreach (['id' => '../../bad', 'cost' => 123, 'clientContact' => null, 'expires' => '2027-02-30', 'website' => 'javascript:alert(1)', 'name' => ' padded '] as $key => $value) {
            $copy = $base; $copy['data']['services'][0][$key] = $value; $bad[] = $copy;
        }
        $copy = $base; $copy['data']['services'][0]['unknown'] = 'discard me'; $bad[] = $copy;
        $copy = $base; unset($copy['data']['services'][0]['notes']); $bad[] = $copy;
        $copy = $base; $copy['data']['services'] = (object) []; $bad[] = $copy;
        $copy = $base; $copy['data']['sent'][] = $copy['data']['sent'][0]; $bad[] = $copy;
        $copy = $base; $copy['data']['sent'] = ['service-123:2027-02-30:0']; $bad[] = $copy;
        $copy = $base; $copy['data']['telegram']['enabled'] = 'false'; $bad[] = $copy;
        $copy = $base; $copy['data']['telegram']['days'] = [30, '7']; $bad[] = $copy;
        $copy = $base; $copy['data']['telegram']['days'] = [7, 7]; $bad[] = $copy;
        $copy = $base; $copy['data']['telegram']['days'] = (object) ['0' => 7]; $bad[] = $copy;
        $copy = $base; $copy['data']['sent'] = array_fill(0, BACKUP_SENT_LIMIT + 1, 'service-123:2027-10-07:0'); $bad[] = $copy;
        $copy = $base; $copy['data']['services'] = array_fill(0, BACKUP_SERVICE_LIMIT + 1, $copy['data']['services'][0]); $bad[] = $copy;
        $copy = $base; $copy['data']['services'][0]['notes'] = str_repeat('x', BACKUP_REQUEST_BYTES); $bad[] = $copy;
        $before = file_get_contents(data_directory() . '/dashboard.json');
        foreach ($bad as $invalid) { rejects(fn() => stage_backup(document($invalid), 'session-a')); }
        expect(file_get_contents(data_directory() . '/dashboard.json') === $before, 'Invalid backup changed store');
        $huge = fixture(); $huge['services'] = array_fill(0, BACKUP_SERVICE_LIMIT + 1, $huge['services'][0]);
        rejects(fn() => make_backup($huge));
    },
    'Import preserves owner/attempts, disables imported Telegram, and verifies private recovery snapshot' => function (): void {
        $old = read_store();
        $incoming = make_backup(fixture(), true);
        $incoming['data']['services'][0]['clientContact'] = 'new@example.com';
        $incoming['data']['telegram']['chatId'] = '987654321';
        $stage = stage_backup(document($incoming), 'session-a');
        expect($stage['serviceCount'] === 1 && $stage['currentServiceCount'] === 1 && $stage['includesTelegram'], 'Wrong summary');
        expect(!str_contains(backup_json($stage), fixture()['telegram']['token']), 'Summary leaked token');
        $result = import_backup($stage['importId'], 'session-a');
        $store = read_store();
        expect($store['services'] === $incoming['data']['services'] && $store['sent'] === $incoming['data']['sent'], 'Restored data mismatch');
        expect($store['user'] === $old['user'] && $store['attempts'] === $old['attempts'], 'Account changed');
        expect(!$store['telegram']['enabled'] && $store['telegram']['chatId'] === '987654321', 'Telegram safety mismatch');
        $path = data_directory() . '/backups/' . $result['recoveryBackup'];
        expect((fileperms($path) & 0777) === 0600 && (fileperms(dirname($path)) & 0777) === 0700, 'Recovery permissions');
        $recovery = json_decode(file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
        expect(validate_backup($recovery)['data'] === make_backup($old, true)['data'], 'Recovery snapshot mismatch');
        rejects(fn() => import_backup($stage['importId'], 'session-a'), BackupConflictException::class);
        $restore = stage_backup($recovery, 'session-a');
        import_backup($restore['importId'], 'session-a');
        expect(read_store()['services'] === $old['services'], 'Recovery did not restore services');
        expect(read_store()['telegram']['token'] === $old['telegram']['token'], 'Recovery did not restore token');
    },
    'Backup without Telegram preserves settings; staging is bound to session and revision' => function (): void {
        $backup = make_backup(fixture());
        $old = read_store();
        $stage = stage_backup(document($backup), 'session-a');
        rejects(fn() => import_backup($stage['importId'], 'session-b'), BackupConflictException::class);
        foreach (['services', 'telegram', 'sent'] as $section) {
            reset_store();
            $stage = stage_backup(document($backup), 'session-a');
            with_store(static function (array &$store) use ($section): void {
                if ($section === 'services') { $store['services'][0]['notes'] = 'Edited after preview'; }
                elseif ($section === 'telegram') { $store['telegram']['enabled'] = false; }
                else { $store['sent'] = []; }
            }, true);
            $before = file_get_contents(data_directory() . '/dashboard.json');
            rejects(fn() => import_backup($stage['importId'], 'session-a'), BackupConflictException::class);
            expect(file_get_contents(data_directory() . '/dashboard.json') === $before, 'Stale import changed data');
        }
        reset_store();
        $stage = stage_backup(document($backup), 'session-a');
        $result = import_backup($stage['importId'], 'session-a');
        expect(!$result['telegramDisabled'] && read_store()['telegram'] === $old['telegram'], 'Absent Telegram replaced settings');
    },
    'Expired stages are rejected and cleanup preserves unknown files' => function (): void {
        $stage = stage_backup(document(make_backup(fixture())), 'session-a');
        $path = data_directory() . '/imports/' . $stage['importId'] . '.json';
        $contents = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $contents['expires'] = time() - 1;
        file_put_contents($path, backup_json($contents));
        touch($path, time() - BACKUP_IMPORT_TTL - 1);
        rejects(fn() => import_backup($stage['importId'], 'session-a'), BackupConflictException::class);
        $unknown = dirname($path) . '/' . str_repeat('a', 64) . '.json';
        file_put_contents($unknown, '{"notes":"not a staged backup"}');
        touch($unknown, time() - BACKUP_IMPORT_TTL - 1);
        cleanup_staged_backups(dirname($path));
        expect(!file_exists($path) && file_exists($unknown), 'Cleanup removed unknown file or missed expired stage');
    },
    'Failed automatic backup prevents import and preserves active data' => function (): void {
        $backup = make_backup(fixture());
        $backup['data']['services'] = [];
        $stage = stage_backup(document($backup), 'session-a');
        $directory = data_directory() . '/backups';
        rename($directory, $directory . '-saved');
        file_put_contents($directory, 'block directory creation');
        $before = file_get_contents(data_directory() . '/dashboard.json');
        set_error_handler(static fn(): bool => true);
        try { rejects(fn() => import_backup($stage['importId'], 'session-a'), RuntimeException::class); }
        finally { restore_error_handler(); unlink($directory); rename($directory . '-saved', $directory); }
        expect(file_get_contents(data_directory() . '/dashboard.json') === $before, 'Failed snapshot changed active data');
        expect(is_file(data_directory() . '/imports/' . $stage['importId'] . '.json'), 'Failed snapshot consumed stage');
    },
];

$failures = 0;
try {
    foreach ($tests as $name => $test) {
        reset_store();
        try { $test(); echo "PASS {$name}\n"; }
        catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
    }
} finally { remove_tree($directory); }
echo count($tests) . " backup tests, {$failures} failures\n";
exit($failures ? 1 : 0);
