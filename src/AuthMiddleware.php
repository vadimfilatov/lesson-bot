<?php

declare(strict_types=1);

namespace App;

class AuthMiddleware
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Проверяет, авторизован ли пользователь по telegram_id.
     * Возвращает user row или null.
     */
    public function getAuthorizedUser(int $telegramId): ?array
    {
        $stmt = $this->db->execute(
            'SELECT * FROM users WHERE telegram_id = ? AND is_authorized = 1',
            [$telegramId]
        );
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Быстрая проверка: авторизован или нет.
     */
    public function isAuthorized(int $telegramId): bool
    {
        return $this->getAuthorizedUser($telegramId) !== null;
    }
}
