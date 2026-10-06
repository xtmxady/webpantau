<?php

declare(strict_types=1);

$directory = sys_get_temp_dir() . '/webpantau-unit-' . bin2hex(random_bytes(8));
putenv('DATA_DIR=' . $directory);
require dirname(__DIR__) . '/app/telegram.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tests = [
    'Calendar boundaries use Makassar timezone' => function (): void {
        expect(days_remaining('2026-10-08', new DateTimeImmutable('2026-10-06T17:00:00Z')) === 1, 'Wrong local day');
        expect(days_remaining('2026-10-06', new DateTimeImmutable('2026-10-06T17:00:00Z')) === -1, 'Wrong overdue day');
    },
    'Service input rejects impossible dates and unsafe URLs' => function (): void {
        $row = ['name' => 'example.com', 'client' => 'Example', 'type' => 'domain', 'cycle' => 'yearly', 'expires' => '2027-02-28', 'cost' => '150000'];
        expect(validate_service($row + ['website' => 'example.com'])['website'] === 'https://example.com', 'URL normalization');
        foreach ([array_replace($row, ['expires' => '2027-02-30']), array_replace($row, ['cost' => '-1']), $row + ['website' => 'javascript:alert(1)']] as $invalid) {
            $rejected = false;
            try { validate_service($invalid); } catch (InvalidArgumentException) { $rejected = true; }
            expect($rejected, 'Invalid input accepted');
        }
    },
    'Failed writes leave existing data untouched; file is private' => function (): void {
        with_store(function (array &$store): void { $store['services'] = []; }, true);
        expect((fileperms(data_directory() . '/dashboard.json') & 0777) === 0600, 'File permissions');
        $before = file_get_contents(data_directory() . '/dashboard.json');
        try {
            with_store(function (array &$store): void { $store['user'] = ['username' => 'partial']; throw new RuntimeException('Rejected'); }, true);
        } catch (RuntimeException) {}
        expect(file_get_contents(data_directory() . '/dashboard.json') === $before, 'Failed callback altered data');
    },
    'Reminder sends once, follows renewed dates, and records only successful delivery' => function (): void {
        with_store(function (array &$store): void {
            $store['telegram'] = ['token' => 'test-token', 'chatId' => '123', 'enabled' => true, 'days' => [1, 0]];
            $store['services'] = [['id' => 'test-service', 'name' => 'example.com', 'client' => 'Example', 'type' => 'domain', 'expires' => date('Y-m-d'), 'cost' => '150000']];
        }, true);
        $messages = [];
        $transport = function (array $cfg, string $text) use (&$messages): void { $messages[] = $text; };
        expect(run_reminders($transport) === 1, 'First reminder missing');
        expect(run_reminders($transport) === 0, 'Duplicate reminder sent');
        expect(str_contains($messages[0], 'Jatuh tempo hari ini'), 'Message date mismatch');
        with_store(function (array &$store): void { $store['services'][0]['expires'] = date('Y-m-d', strtotime('+1 day')); }, true);
        $before = read_store()['sent'];
        try { run_reminders(static function (): void { throw new RuntimeException('Network failure'); }); } catch (RuntimeException) {}
        expect(read_store()['sent'] === $before, 'Failed delivery marked sent');
        expect(run_reminders($transport) === 1, 'Renewal not reflected');
        with_store(function (array &$store): void { $store['telegram']['enabled'] = false; $store['sent'] = []; }, true);
        expect(run_reminders($transport) === 0, 'Disabled scheduler sent a message');
    },
    'Corrupt JSON is not silently reset or overwritten' => function (): void {
        file_put_contents(data_directory() . '/dashboard.json', '{broken');
        $failed = false;
        try { with_store(static fn(array $store): array => $store, true); } catch (JsonException) { $failed = true; }
        expect($failed, 'Corrupt data accepted');
        expect(file_get_contents(data_directory() . '/dashboard.json') === '{broken', 'Corrupt file overwritten');
    },
];

$failures = 0;
try {
    foreach ($tests as $name => $test) {
        try { $test(); echo "PASS {$name}\n"; }
        catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
    }
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
}
echo count($tests) . " tests, {$failures} failures\n";
exit($failures ? 1 : 0);
