<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Database;
use App\TelegramApi;
use App\Services\SalaryService;

class SalaryHandler
{
    private SalaryService $salaryService;

    public function __construct(
        private Database $db,
        private TelegramApi $telegram,
        private array $config,
    ) {
        $this->salaryService = new SalaryService($db);
    }

    /**
     * /salary — текущий период
     * /salary 1-15 — первая половина
     * /salary 16-31 — вторая половина
     */
    public function handle(int $chatId, int $userId, string $args): void
    {
        $args = trim($args);

        $year = (int) date('Y');
        $month = (int) date('m');

        if ($args === '' || $args === '/salary') {
            // Текущий период
            $day = (int) date('d');
            if ($day <= 15) {
                $from = sprintf('%04d-%02d-01', $year, $month);
                $to = sprintf('%04d-%02d-15', $year, $month);
                $periodLabel = "01–15";
            } else {
                $from = sprintf('%04d-%02d-16', $year, $month);
                $to = date('Y-m-t');
                $periodLabel = "16–" . date('t');
            }
        } elseif (preg_match('/^1\s*-\s*15$/', $args)) {
            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = sprintf('%04d-%02d-15', $year, $month);
            $periodLabel = "01–15";
        } elseif (preg_match('/^16\s*-\s*3[01]$/', $args)) {
            $from = sprintf('%04d-%02d-16', $year, $month);
            $to = date('Y-m-t');
            $periodLabel = "16–" . date('t');
        } else {
            $this->telegram->sendMessage(
                $chatId,
                "❌ Неверный период.\n\nИспользуйте:\n/salary — текущий период\n/salary 1-15\n/salary 16-31"
            );
            return;
        }

        $rows = $this->salaryService->getSalaryByPeriod($userId, $from, $to);
        $rate = $this->config['hourly_rate'];

        $monthName = $this->getMonthName($month);

        if (empty($rows)) {
            $this->telegram->sendMessage(
                $chatId,
                "📭 Нет уроков за период {$periodLabel} {$monthName} {$year}."
            );
            return;
        }

        $lines = ["💰 <b>Зарплата за {$periodLabel} {$monthName} {$year}:</b>\n"];
        $totalHours = 0.0;

        foreach ($rows as $row) {
            $sum = $row['total_hours'] * $rate;
            $totalHours += $row['total_hours'];
            $lines[] = "  {$row['level']}: {$row['total_hours']}ч × {$rate}€ = {$sum}€ ({$row['lesson_count']} ур.)";
        }

        $totalSum = $totalHours * $rate;
        $lines[] = "\n<b>Итого: {$totalHours}ч = {$totalSum}€</b>";

        $this->telegram->sendMessage($chatId, implode("\n", $lines));
    }

    private function getMonthName(int $month): string
    {
        $months = [
            1 => 'января', 2 => 'февраля', 3 => 'марта',
            4 => 'апреля', 5 => 'мая', 6 => 'июня',
            7 => 'июля', 8 => 'августа', 9 => 'сентября',
            10 => 'октября', 11 => 'ноября', 12 => 'декабря',
        ];
        return $months[$month] ?? '';
    }
}
