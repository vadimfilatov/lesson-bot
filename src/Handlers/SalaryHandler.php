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
     * /hours — текущий период
     * /hours 1-15 — первая половина (+ отдельно 1-16)
     * /hours 16-31 — вторая половина
     */
    public function handle(int $chatId, int $userId, string $args): void
    {
        $args = trim($args);
        $levelNames = $this->config['levels'];

        $year = (int) date('Y');
        $month = (int) date('m');
        $monthName = $this->getMonthName($month);
        $isFirstHalf = false;

        if ($args === '' || $args === '/hours') {
            $day = (int) date('d');
            $isFirstHalf = ($day <= 15);
        } elseif (preg_match('/^1\s*-\s*1[56]$/', $args)) {
            $isFirstHalf = true;
        } elseif (preg_match('/^16\s*-\s*3[01]$/', $args)) {
            $isFirstHalf = false;
        } else {
            $this->telegram->sendMessage(
                $chatId,
                "❌ Неверный период.\n\nИспользуйте:\n/hours — текущий период\n/hours 1-15\n/hours 16-31"
            );
            return;
        }

        if ($isFirstHalf) {
            // Период 1–15
            $from15 = sprintf('%04d-%02d-01', $year, $month);
            $to15 = sprintf('%04d-%02d-15', $year, $month);
            $rows15 = $this->salaryService->getSalaryByPeriod($userId, $from15, $to15);

            // Период 1–16
            $to16 = sprintf('%04d-%02d-16', $year, $month);
            $rows16 = $this->salaryService->getSalaryByPeriod($userId, $from15, $to16);

            if (empty($rows15) && empty($rows16)) {
                $this->telegram->sendMessage($chatId, "📭 Нет уроков за 01–16 {$monthName} {$year}.");
                return;
            }

            $lines = [];

            // Блок 1–15
            $lines[] = "🕐 <b>Часы за 01–15 {$monthName} {$year}:</b>\n";
            $this->appendHoursBlock($lines, $rows15, $levelNames);

            // Блок 1–16
            $lines[] = "\n🕐 <b>Часы за 01–16 {$monthName} {$year}:</b>\n";
            $this->appendHoursBlock($lines, $rows16, $levelNames);

            $this->telegram->sendMessage($chatId, implode("\n", $lines));
        } else {
            // Период 16–конец
            $from = sprintf('%04d-%02d-16', $year, $month);
            $to = date('Y-m-t');
            $periodLabel = "16–" . date('t');

            $rows = $this->salaryService->getSalaryByPeriod($userId, $from, $to);

            if (empty($rows)) {
                $this->telegram->sendMessage($chatId, "📭 Нет уроков за {$periodLabel} {$monthName} {$year}.");
                return;
            }

            $lines = ["🕐 <b>Часы за {$periodLabel} {$monthName} {$year}:</b>\n"];
            $this->appendHoursBlock($lines, $rows, $levelNames);

            $this->telegram->sendMessage($chatId, implode("\n", $lines));
        }
    }

    private function appendHoursBlock(array &$lines, array $rows, array $levelNames): void
    {
        $totalHours = 0.0;
        foreach ($rows as $row) {
            $name = $levelNames[$row['level']] ?? $row['level'];
            $totalHours += $row['total_hours'];
            $lines[] = "  {$name}: {$row['total_hours']}ч ({$row['lesson_count']} ур.)";
        }
        $lines[] = "  <b>Итого: {$totalHours}ч</b>";
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
