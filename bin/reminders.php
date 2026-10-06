#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/telegram.php';
try {
    $count = run_reminders();
    echo date(DATE_ATOM) . " — {$count} pengingat dikirim.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Pengingat gagal. Periksa konfigurasi Telegram, jaringan, dan file data.' . PHP_EOL);
    exit(1);
}
