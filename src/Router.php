<?php

declare(strict_types=1);

namespace App;

use App\Handlers\StartHandler;
use App\Handlers\LessonHandler;
use App\Handlers\SalaryHandler;
use App\Handlers\StatsHandler;
use App\Handlers\UndoHandler;

class Router
{
    private AuthMiddleware $auth;
    private StartHandler $startHandler;
    private LessonHandler $lessonHandler;
    private SalaryHandler $salaryHandler;
    private StatsHandler $statsHandler;
    private UndoHandler $undoHandler;

    public function __construct(
        private Database $db,
        private TelegramApi $telegram,
        private array $config,
    ) {
        $this->auth = new AuthMiddleware($db);
        $this->startHandler = new StartHandler($db, $telegram, $config);
        $this->lessonHandler = new LessonHandler($db, $telegram);
        $this->salaryHandler = new SalaryHandler($db, $telegram, $config);
        $this->statsHandler = new StatsHandler($db, $telegram, $config);
        $this->undoHandler = new UndoHandler($db, $telegram);
    }

    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        if ($message === null) {
            return; // Игнорируем callback_query, edited_message и т.д.
        }

        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;

        if ($chatId === null || $fromId === null) {
            return;
        }

        // 1. Обработка контакта (авторизация) — до проверки auth
        if (isset($message['contact'])) {
            $this->startHandler->handleContact($chatId, $fromId, $message['contact']);
            return;
        }

        $text = trim($message['text'] ?? '');
        if ($text === '') {
            return;
        }

        // 2. Команда /start — до проверки auth
        if (str_starts_with($text, '/start')) {
            $this->startHandler->handle($chatId, $fromId);
            return;
        }

        // 3. Middleware: проверка авторизации для всех остальных
        $user = $this->auth->getAuthorizedUser($fromId);
        if ($user === null) {
            $this->telegram->sendMessage(
                $chatId,
                '⛔ Вы не авторизованы. Отправьте /start для авторизации.'
            );
            return;
        }

        $userId = (int) $user['id'];

        // 4. Маршрутизация команд
        if (str_starts_with($text, '/hours')) {
            $args = trim(substr($text, strlen('/hours')));
            $this->salaryHandler->handle($chatId, $userId, $args);
            return;
        }

        if ($text === '/stats') {
            $this->statsHandler->handle($chatId, $userId);
            return;
        }

        if ($text === '/undo') {
            $this->undoHandler->handle($chatId, $userId);
            return;
        }

        if ($text === '/help') {
            $this->sendHelp($chatId);
            return;
        }

        // 5. Попытка распознать как урок
        if (LessonHandler::isLessonMessage($text)) {
            $this->lessonHandler->handle($chatId, $userId, $text);
            return;
        }

        // 6. Неизвестная команда / сообщение
        $this->telegram->sendMessage(
            $chatId,
            "❓ Не понимаю.\n\nОтправьте урок: <b>A 1.5</b>\nИли команду: /hours /stats /undo /help"
        );
    }

    private function sendHelp(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, <<<HTML
📖 <b>Помощь</b>

<b>Запись урока:</b>
  <code>A 1.5</code> — уровень A, 1.5 часа, сегодня
  <code>B 1 2026-03-15</code> — с указанием даты
  <code>D 1</code> — A для детей

<b>Уровни:</b> A, B, C, D (A дети)

<b>Команды:</b>
/hours — часы за текущий период
/hours 1-15 — первая половина месяца
/hours 16-31 — вторая половина
/stats — общая статистика
/undo — отменить последний урок
/help — эта справка
HTML);
    }
}
