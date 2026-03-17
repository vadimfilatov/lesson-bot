<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Database;
use App\TelegramApi;

class StatsHandler
{
    public function __construct(
        private Database $db,
        private TelegramApi $telegram,
        private array $config,
    ) {
    }

    /**
     * /stats — общая статистика.
     */
    public function handle(int $chatId, int $userId): void
    {
        $levelNames = $this->config['levels'];

        // Статистика за всё время (по уровням)
        $allTime = $this->db->execute(
            'SELECT level, SUM(hours) as total_hours, COUNT(*) as lesson_count
             FROM lessons WHERE user_id = ? GROUP BY level ORDER BY level',
            [$userId]
        )->fetchAll();

        // Статистика за текущий месяц
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $thisMonth = $this->db->execute(
            'SELECT SUM(hours) as total_hours, COUNT(*) as lesson_count
             FROM lessons WHERE user_id = ? AND date BETWEEN ? AND ?',
            [$userId, $monthStart, $monthEnd]
        )->fetch();

        if (empty($allTime)) {
            $this->telegram->sendMessage($chatId, '📭 Пока нет записанных уроков.');
            return;
        }

        $lines = ["📊 <b>Статистика</b>\n"];

        // За всё время
        $lines[] = "<b>Все время:</b>";
        $grandTotal = 0.0;
        $grandCount = 0;
        foreach ($allTime as $row) {
            $name = $levelNames[$row['level']] ?? $row['level'];
            $grandTotal += $row['total_hours'];
            $grandCount += $row['lesson_count'];
            $lines[] = "  {$name}: {$row['total_hours']}ч ({$row['lesson_count']} ур.)";
        }
        $lines[] = "  <b>Всего: {$grandTotal}ч, {$grandCount} уроков</b>";

        // За текущий месяц
        $monthHours = (float) ($thisMonth['total_hours'] ?? 0);
        $monthCount = (int) ($thisMonth['lesson_count'] ?? 0);
        $monthName = $this->getMonthName((int) date('m'));

        $lines[] = "\n<b>{$monthName} " . date('Y') . ":</b>";

        $lines[] = "  {$monthHours}ч, {$monthCount} уроков";

        $this->telegram->sendMessage($chatId, implode("\n", $lines));
    }

    private function getMonthName(int $month): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март',
            4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
            7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь',
            10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];
        return $months[$month] ?? '';
    }
}
