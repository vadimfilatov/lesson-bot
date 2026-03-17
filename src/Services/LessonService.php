<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class LessonService
{
    public function __construct(private Database $db)
    {
    }

    public function addLesson(int $userId, string $date, string $level, float $hours): int
    {
        $this->db->execute(
            'INSERT INTO lessons (user_id, date, level, hours) VALUES (?, ?, ?, ?)',
            [$userId, $date, $level, $hours]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * Проверяет, был ли такой же урок добавлен менее 60 секунд назад (защита от дублей).
     */
    public function isDuplicate(int $userId, string $date, string $level, float $hours): bool
    {
        $stmt = $this->db->execute(
            "SELECT id FROM lessons
             WHERE user_id = ? AND date = ? AND level = ? AND hours = ?
               AND created_at >= datetime('now', '-60 seconds')
             LIMIT 1",
            [$userId, $date, $level, $hours]
        );
        return $stmt->fetch() !== false;
    }
}
