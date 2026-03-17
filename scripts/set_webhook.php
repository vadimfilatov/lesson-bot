<?php

declare(strict_types=1);

/**
 * Установка webhook для Telegram бота.
 *
 * Запуск: php scripts/set_webhook.php https://your-domain.com/index.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

$url = $argv[1] ?? null;

if ($url === null) {
    echo "Использование: php scripts/set_webhook.php <WEBHOOK_URL>\n";
    echo "Пример:        php scripts/set_webhook.php https://bot.example.com/index.php\n";
    exit(1);
}

// Валидация URL
if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
    echo "❌ URL должен начинаться с https://\n";
    exit(1);
}

$token = $config['bot_token'];
$apiUrl = "https://api.telegram.org/bot{$token}/setWebhook";

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['url' => $url],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($result['ok'] ?? false) {
    echo "✅ Webhook установлен: {$url}\n";
    echo "   Ответ: {$result['description']}\n";
} else {
    echo "❌ Ошибка установки webhook:\n";
    echo "   HTTP: {$httpCode}\n";
    echo "   Ответ: " . ($result['description'] ?? $response) . "\n";
    exit(1);
}
