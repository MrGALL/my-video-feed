<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private readonly PDO $pdo;

    public function __construct(
        private readonly string $driver,
        string $dsn,
        string $user = '',
        string $pass = '',
    ) {
        $this->pdo = new PDO($dsn, $user, $pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function execute(string $sql, array $params = []): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /** Prefixes an "INTO ... VALUES (...)" clause with the driver's ignore-duplicate syntax. */
    public function insertIgnore(string $intoClause, array $params = []): void
    {
        $prefix = $this->driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->execute("{$prefix} {$intoClause}", $params);
    }

    public function runScript(string $sql): void
    {
        // Strip -- comments first: their prose may contain ';'.
        $stripped = preg_replace('/--.*$/m', '', $sql);
        foreach (explode(';', $stripped) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
    }
}
