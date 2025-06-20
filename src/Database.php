<?php

namespace MongoLitePlus;

use PDO;
use Exception;

class Database
{
    protected PDO $pdo;
    protected string $filePath;
    protected ?string $encryptionKey;
    protected array $replicas = [];
    protected ?string $lastBackupTime = null;

    public function __construct(string $filePath, ?string $encryptionKey = null)
    {
        $this->filePath = $filePath;
        $this->encryptionKey = $encryptionKey;
        $this->connect();
    }

    protected function connect(): void
    {
        $dsn = "sqlite:{$this->filePath}";
        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($this->encryptionKey) {
            $this->pdo->exec("PRAGMA key = '{$this->encryptionKey}'");
            $this->pdo->exec("PRAGMA cipher_compatibility = 4");
        }

        $this->pdo->exec("PRAGMA journal_mode = WAL");
        $this->pdo->exec("PRAGMA synchronous = NORMAL");
        $this->pdo->exec("PRAGMA cache_size = -100000");
    }

    public function __get(string $name): Collection
    {
        return new Collection($this, $name);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
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
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0777, true)) {
                throw new Exception("Failed to create backup directory");
            }
        }

        $backupFile = $backupDir . '/' . pathinfo($this->filePath, PATHINFO_FILENAME)
            . '_' . date('Ymd_His') . '.sqlite';

        if (!copy($this->filePath, $backupFile)) {
            throw new Exception("Failed to create database backup");
        }

        $this->lastBackupTime = date('c');
        return $backupFile;
    }

    public function changeEncryptionKey(string $newKey): bool
    {
        try {
            $this->pdo->exec("PRAGMA rekey = '{$newKey}'");
            $this->encryptionKey = $newKey;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function addReplica(string $replicaPath): void
    {
        $this->replicas[] = $replicaPath;
    }

    public function replicate(): void
    {
        foreach ($this->replicas as $replica) {
            if (file_exists($this->filePath)) {
                copy($this->filePath, $replica);
            }
        }
    }

    public function healthCheck(): array
    {
        return [
            'status' => 'OK',
            'size' => filesize($this->filePath),
            'tables' => array_filter(
                $this->listCollections(),
                fn($table) => !in_array($table, ['sqlite_sequence'])
            ),
            'last_backup' => $this->lastBackupTime,
            'replicas' => count($this->replicas)
        ];
    }

    public function getMetrics(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $metrics = [];
        foreach ($tables as $table) {
            // Skip system tables
            if ($table === 'sqlite_sequence' || $table === '_relations') {
                continue;
            }

            // Skip index tables
            if (strpos($table, '_index') !== false) {
                continue;
            }

            try {
                $count = $this->pdo->query("SELECT COUNT(*) FROM " . $this->quote($table))->fetchColumn();
                $size = $this->calculateTableSize($table);
            } catch (\PDOException $e) {
                // Skip tables that can't be queried
                continue;
            }

            $metrics[$table] = [
                'count' => $count,
                'size' => $size
            ];
        }

        return $metrics;
    }

    protected function calculateTableSize(string $table): int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT SUM(LENGTH(doc)) FROM " . $this->quote($table));
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    protected function getTableSize(string $table): int
    {
        $stmt = $this->pdo->prepare("
            SELECT SUM(pgsize) 
            FROM dbstat 
            WHERE name = :table
        ");
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn();
    }

    protected function quote(string $s): string
    {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
