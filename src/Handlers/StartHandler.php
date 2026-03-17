<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Database;
use App\TelegramApi;

class StartHandler
{
    public function __construct(
        private Database $db,
        private TelegramApi $telegram,
        private array $config,
    ) {
    }

    /**
     * Команда /start — показать кнопку авторизации или приветствие.
     */
    public function handle(int $chatId, int $telegramId): void
    {
        // Проверяем, уже ли авторизован
        $stmt = $this->db->execute(
            'SELECT id FROM users WHERE telegram_id = ? AND is_authorized = 1',
            [$telegramId]
        );

        if ($stmt->fetch()) {
            $this->telegram->sendMessage(
                $chatId,
                "✅ Вы уже авторизованы!\n\nОтправляйте уроки в формате:\n<b>A 1.5</b>\n<b>B 1</b>\n<b>C 0.5 2026-03-15</b>\n\nКоманды:\n/salary — зарплата за период\n/stats — статистика\n/undo — отменить последний урок"
            );
            return;
        }

        // Показываем кнопку для отправки контакта
        $keyboard = [
            'keyboard' => [[
                [
                    'text' => '📱 Отправить номер телефона',
                    'request_contact' => true,
                ],
            ]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];

        $this->telegram->sendMessage(
            $chatId,
            "👋 Добро пожаловать!\n\nДля авторизации отправьте свой номер телефона, нажав кнопку ниже.",
            $keyboard
        );
    }

    /**
     * Обработка полученного контакта.
     */
    public function handleContact(int $chatId, int $telegramId, array $contact): void
    {
        // Защита: проверяем что пользователь отправил СВОЙ контакт
        if (($contact['user_id'] ?? null) !== $telegramId) {
            $this->telegram->sendMessage($chatId, '❌ Отправьте <b>свой</b> контакт, а не чужой.');
            return;
        }

        $phone = $contact['phone_number'] ?? '';
        $normalizedPhone = $this->normalizePhone($phone);

        // Проверяем white-list
        $allowedPhones = array_map(
            fn(string $p) => $this->normalizePhone($p),
            $this->config['allowed_phones']
        );

        if (!in_array($normalizedPhone, $allowedPhones, true)) {
            $this->telegram->removeKeyboard(
                $chatId,
                '⛔ Ваш номер телефона не в списке разрешённых. Доступ запрещён.'
            );
            return;
        }

        // Проверяем, может уже существует
        $stmt = $this->db->execute(
            'SELECT id FROM users WHERE telegram_id = ?',
            [$telegramId]
        );

        if ($stmt->fetch()) {
            // Обновляем
            $this->db->execute(
                'UPDATE users SET phone = ?, is_authorized = 1 WHERE telegram_id = ?',
                [$phone, $telegramId]
            );
        } else {
            // Создаём
            $this->db->execute(
                'INSERT INTO users (telegram_id, phone, is_authorized) VALUES (?, ?, 1)',
                [$telegramId, $phone]
            );
        }

        $this->telegram->removeKeyboard(
            $chatId,
            "✅ Авторизация успешна!\n\nТеперь отправляйте уроки:\n<b>A 1.5</b> — уровень A, 1.5 часа\n<b>B 1 2026-03-15</b> — с датой\n\nКоманды: /salary /stats /undo"
        );
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
