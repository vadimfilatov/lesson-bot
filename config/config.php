<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// Загружаем .env
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
$dotenv->required(['TELEGRAM_BOT_TOKEN', 'ALLOWED_PHONES']);

return [
    // Telegram Bot API token
    'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN']
        ?? throw new RuntimeException('TELEGRAM_BOT_TOKEN not set'),

    // Разрешённые номера телефонов (без +, только цифры)
    'allowed_phones' => array_filter(
        array_map('trim', explode(',', $_ENV['ALLOWED_PHONES'] ?? ''))
    ),

    // Ставка за час (€)
    'hourly_rate' => (float) ($_ENV['HOURLY_RATE'] ?? 25),

    // Часовой пояс
    'timezone' => $_ENV['TIMEZONE'] ?? 'Europe/Berlin',

    // Путь к базе данных
    'database_path' => dirname(__DIR__) . '/database/bot.sqlite',
];
