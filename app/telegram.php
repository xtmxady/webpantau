<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function send_telegram(array $config, string $text): void
{
    if (empty($config['token']) || empty($config['chatId'])) {
        throw new RuntimeException('Simpan token bot dan chat ID terlebih dahulu.');
    }
    $handle = curl_init('https://api.telegram.org/bot' . $config['token'] . '/sendMessage');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => $config['chatId'], 'text' => $text], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($response === false) {
        throw new RuntimeException('Tidak dapat terhubung ke Telegram. Periksa akses jaringan.');
    }
    $result = json_decode($response, true);
    if ($status !== 200 || !($result['ok'] ?? false)) {
        throw new RuntimeException('Telegram menolak pengiriman. Periksa token, chat ID, dan kirim /start ke bot.');
    }
}

/** Called only by CLI cron. Injectable transport allows testing without network messages. */
function run_reminders(?callable $transport = null): int
{
    $transport ??= 'send_telegram';
    $lock = fopen(data_directory() . '/reminders.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $sent = 0;
    try {
        $snapshot = read_store();
        if (!$snapshot['telegram']['enabled']) {
            return 0;
        }
        foreach ($snapshot['services'] as $candidate) {
            // Serialize against edits and other workers to avoid sending an obsolete date.
            $delivered = with_store(function (array &$store) use ($candidate, $transport): bool {
                if (!$store['telegram']['enabled']) {
                    return false;
                }
                $matches = array_values(array_filter($store['services'], static fn(array $s): bool => $s['id'] === $candidate['id']));
                if (!$matches) {
                    return false;
                }
                $service = $matches[0];
                $days = days_remaining($service['expires']);
                $key = $service['id'] . ':' . $service['expires'] . ':' . $days;
                if (!in_array($days, $store['telegram']['days'], true) || in_array($key, $store['sent'], true)) {
                    return false;
                }
                $type = $service['type'] === 'domain' ? 'Domain' : 'Server';
                $time = $days === 0 ? 'Jatuh tempo hari ini' : "Tersisa {$days} hari";
                $baseCost = (float) $service['cost'];
                $taxAmount = round($baseCost * 0.11);
                $cost = number_format($baseCost, 0, ',', '.');
                $tax = number_format($taxAmount, 0, ',', '.');
                $total = number_format($baseCost + $taxAmount, 0, ',', '.');
                $cycle = ($service['cycle'] ?? 'yearly') === 'monthly' ? 'bulan' : 'tahun';
                $transport($store['telegram'], "🔔 {$type} {$service['name']}\nKlien: {$service['client']}\nJatuh tempo: {$service['expires']}\n{$time}\nBiaya / {$cycle}: Rp {$cost}\nPPN 11%: Rp {$tax}\nTotal bayar / {$cycle}: Rp {$total}\nPerbarui tanggal di Webpantau setelah diperpanjang.");
                $store['sent'][] = $key;
                $store['sent'] = array_slice($store['sent'], -5000);
                return true;
            }, true);
            $sent += (int) $delivered;
        }
        return $sent;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
