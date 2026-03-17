<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Database;
use App\TelegramApi;

class UndoHandler
{
    public function __construct(
        private Database $db,
        private TelegramApi $telegram,
    ) {
    }

    /**
     * /undo — удаляет последний добавленный урок пользователя.
     */
    public function handle(int $chatId, int $userId): void
    {
        // Находим последний урок
        $lesson = $this->db->execute(
            'SELECT id, date, level, hours FROM lessons
             WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        )->fetch();

        if (!$lesson) {
            $this->telegram->sendMessage($chatId, '📭 Нечего отменять — уроков нет.');
            return;
        }

        // Удаляем
        $this->db->execute('DELETE FROM lessons WHERE id = ?', [$lesson['id']]);

        $this->telegram->sendMessage(
            $chatId,
            "🗑 Удалён последний урок:\n\n📚 {$lesson['level']} — {$lesson['hours']}ч ({$lesson['date']})"
        );
    }
}
