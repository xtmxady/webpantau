<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fafaf8">
    <title>Webpantau — Kendali layanan website</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/details.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
    <div id="app"><div class="loading">Menyiapkan ruang kerja…</div></div>
    <div id="dialog-root"></div>
    <div id="toast-root" aria-live="polite"></div>
    <noscript>Aktifkan JavaScript untuk menggunakan dashboard ini.</noscript>
</body>
</html>
