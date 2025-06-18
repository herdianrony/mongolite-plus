<?php

namespace MongoLitePlus;

use PDO;

class Database
{
    protected PDO $pdo;
    protected string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->connect();
    }

    protected function connect(): void
    {
        $this->pdo = new PDO("sqlite:{$this->filePath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("PRAGMA journal_mode = WAL");
        $this->pdo->exec("PRAGMA synchronous = NORMAL");
        $this->pdo->exec("PRAGMA cache_size = -100000"); // 100MB cache
    }

    public function __get(string $name): Collection
    {
        return new Collection($this->pdo, $name);
    }

    public function listCollections(): array
    {
        $stmt = $this->pdo->query("
            SELECT name FROM sqlite_master
            WHERE type = 'table'
            AND name NOT LIKE '%_index'
            AND name NOT LIKE '%_relations'
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function backup(string $backupDir): string
    {
        if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

        $backupFile = $backupDir . '/' . basename($this->filePath) . '_' . date('Ymd_His');
        copy($this->filePath, $backupFile);

        return $backupFile;
    }
}
