#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    // Receive through stdin, never via a command argument visible in process listings.
    $password = stream_get_contents(STDIN, 257);
    if (strlen($password) < 12 || strlen($password) > 256) {
        throw new InvalidArgumentException('Kata sandi harus 12–256 karakter.');
    }
    with_store(function (array &$store) use ($password): void {
        if (empty($store['user']['username'])) {
            throw new RuntimeException('Buat akun pemilik melalui dashboard terlebih dahulu.');
        }
        $store['user'] = [
            'username' => $store['user']['username'],
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'version' => bin2hex(random_bytes(16)),
        ];
    }, true);
    echo "Kata sandi diperbarui. Masuk kembali melalui dashboard.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
