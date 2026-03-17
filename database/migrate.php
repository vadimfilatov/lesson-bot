<?php

declare(strict_types=1);

/**
 * Миграция: создание таблиц users и lessons.
 *
 * Запуск: php database/migrate.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

use App\Database;

$db = new Database($config['database_path']);

$db->exec('
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id INTEGER NOT NULL UNIQUE,
        phone TEXT NOT NULL,
        is_authorized INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )
');

$db->exec('
    CREATE TABLE IF NOT EXISTS lessons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        level TEXT NOT NULL CHECK (level IN (\'A\', \'B\', \'C\', \'D\')),
        -- D = A для детей
        hours REAL NOT NULL CHECK (hours > 0),
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
');

$db->exec('
    CREATE INDEX IF NOT EXISTS idx_lessons_user_date ON lessons(user_id, date)
');

echo "✅ Миграция выполнена успешно.\n";
echo "   База данных: {$config['database_path']}\n";
