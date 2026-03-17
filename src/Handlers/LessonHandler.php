<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Database;
use App\TelegramApi;
use App\Services\LessonService;

class LessonHandler
{
    private LessonService $lessonService;

    public function __construct(
        private Database $db,
        private TelegramApi $telegram,
    ) {
        $this->lessonService = new LessonService($db);
    }

    /**
     * Парсит сообщение вида "A 1.5" или "B 1 2026-03-15" и создаёт урок.
     */
    public function handle(int $chatId, int $userId, string $text): void
    {
        $text = trim($text);

        // Regex: LEVEL HOURS [DATE]
        $pattern = '/^([ABC])\s+(0\.5|1(?:\.0)?|1\.5)(?:\s+(\d{4}-\d{2}-\d{2}))?$/i';

        if (!preg_match($pattern, $text, $matches)) {
            $this->telegram->sendMessage(
                $chatId,
                "❓ Не понимаю формат.\n\nПримеры:\n<b>A 1.5</b>\n<b>B 1</b>\n<b>C 0.5 2026-03-15</b>\n\nДопустимые уровни: A, B, C\nДопустимые часы: 0.5, 1, 1.5"
            );
            return;
        }

        $level = strtoupper($matches[1]);
        $hours = (float) $matches[2];
        $date = $matches[3] ?? date('Y-m-d');

        // Валидация даты
        if (isset($matches[3])) {
            $dateError = $this->validateDate($date);
            if ($dateError !== null) {
                $this->telegram->sendMessage($chatId, $dateError);
                return;
            }
        }

        // Проверка на дубликат (тот же user + date + level + hours за последние 60 сек)
        if ($this->lessonService->isDuplicate($userId, $date, $level, $hours)) {
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ Такой урок уже записан менее минуты назад.\nЕсли нужно добавить ещё — подождите 60 секунд или измените параметры."
            );
            return;
        }

        // Сохраняем урок
        $this->lessonService->addLesson($userId, $date, $level, $hours);

        $this->telegram->sendMessage(
            $chatId,
            "✅ Урок записан!\n\n📚 Уровень: <b>{$level}</b>\n⏱ Часы: <b>{$hours}</b>\n📅 Дата: <b>{$date}</b>"
        );
    }

    /**
     * Проверяет, может ли текст быть командой добавления урока.
     */
    public static function isLessonMessage(string $text): bool
    {
        return (bool) preg_match('/^[ABC]\s+(0\.5|1(?:\.0)?|1\.5)/i', trim($text));
    }

    private function validateDate(string $date): ?string
    {
        // Проверяем формат
        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            return '❌ Неверный формат даты. Используйте: <b>YYYY-MM-DD</b>';
        }

        [$year, $month, $day] = array_map('intval', $parts);
        if (!checkdate($month, $day, $year)) {
            return '❌ Несуществующая дата.';
        }

        $dateObj = new \DateTimeImmutable($date);
        $now = new \DateTimeImmutable('today');

        // Не больше 7 дней в будущее
        $maxFuture = $now->modify('+7 days');
        if ($dateObj > $maxFuture) {
            return '❌ Дата слишком далеко в будущем (максимум +7 дней).';
        }

        // Не старше 30 дней
        $maxPast = $now->modify('-30 days');
        if ($dateObj < $maxPast) {
            return '❌ Дата слишком далеко в прошлом (максимум −30 дней).';
        }

        return null;
    }
}
