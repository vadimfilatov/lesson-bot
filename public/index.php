<?php

declare(strict_types=1);

// Telegram Bot Webhook Entry Point

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\TelegramApi;
use App\Router;

// Всегда отвечаем 200 OK (Telegram перестаёт повторять запросы)
http_response_code(200);
header('Content-Type: application/json');

try {
    // Загружаем конфигурацию
    $config = require __DIR__ . '/../config/config.php';

    // Устанавливаем часовой пояс
    date_default_timezone_set($config['timezone']);

    // Читаем входящий JSON от Telegram
    $input = file_get_contents('php://input');
    if (empty($input)) {
        echo json_encode(['status' => 'no input']);
        exit;
    }

    $update = json_decode($input, true);
    if ($update === null) {
        echo json_encode(['status' => 'invalid json']);
        exit;
    }

    // Инициализируем зависимости
    $db = new Database($config['database_path']);
    $telegram = new TelegramApi($config['bot_token']);
    $router = new Router($db, $telegram, $config);

    // Обрабатываем update
    $router->handleUpdate($update);

    echo json_encode(['status' => 'ok']);
} catch (\Throwable $e) {
    // Логируем ошибку, но не показываем пользователю
    error_log("Bot error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
    echo json_encode(['status' => 'error']);
}
