<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class SalaryService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Получает агрегированные данные по уровням за период.
     *
     * @return array<array{level: string, total_hours: float, lesson_count: int}>
     */
    public function getSalaryByPeriod(int $userId, string $from, string $to): array
    {
        return $this->db->execute(
            'SELECT level, SUM(hours) as total_hours, COUNT(*) as lesson_count
             FROM lessons
             WHERE user_id = ? AND date BETWEEN ? AND ?
             GROUP BY level
             ORDER BY level',
            [$userId, $from, $to]
        )->fetchAll();
    }
}
