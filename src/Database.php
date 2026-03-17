<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $this->pdo = new PDO("sqlite:$path", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Включаем WAL-режим и foreign keys
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    public function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }
}
