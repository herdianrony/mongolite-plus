<?php

namespace MongoLitePlus;

use PDO;
use Exception;
use MongoLitePlus\InvalidEncryptionKeyException;

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
            try {
                // Set key with escaping
                $safeKey = str_replace("'", "''", $this->encryptionKey);
                $this->pdo->exec("PRAGMA key = '$safeKey'");
                $this->pdo->exec("PRAGMA cipher_compatibility = 4");

                // Check if SQLCipher is available
                $cipherVersion = $this->pdo->query("PRAGMA cipher_version")->fetchColumn();
                if (!$cipherVersion) {
                    throw new Exception("SQLCipher not available");
                }

                // Validate decryption key
                $result = $this->pdo->query("SELECT count(*) FROM sqlite_master")->fetchColumn();
                if ((int)$result === 0) {
                    throw new InvalidEncryptionKeyException("Database cannot be decrypted or is empty");
                }
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'file is not a database')) {
                    throw new InvalidEncryptionKeyException();
                }
                throw new Exception("SQLCipher error: " . $e->getMessage());
            }
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

    public function getFilePath(): string
    {
        return $this->filePath;
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
            $this->pdo->query("SELECT count(*) FROM sqlite_master")->fetch();
            $safeNewKey = str_replace("'", "''", $newKey);
            $this->pdo->exec("PRAGMA rekey = '$safeNewKey'");
            $this->encryptionKey = $newKey;
            $this->pdo->query("SELECT count(*) FROM sqlite_master")->fetch();
            return true;
        } catch (\PDOException $e) {
            throw new Exception("Key change failed: " . $e->getMessage());
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
            if ($table === 'sqlite_sequence' || $table === '_relations' || str_contains($table, '_index')) {
                continue;
            }

            try {
                $count = $this->pdo->query("SELECT COUNT(*) FROM " . $this->quote($table))->fetchColumn();
                $size = $this->calculateTableSize($table);
            } catch (\PDOException $e) {
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

    protected function quote(string $s): string
    {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    public static function encryptExisting(string $sourcePath, string $targetPath, string $key): bool
    {
        $keySafe = str_replace("'", "''", $key);
        $cmd = <<<EOD
echo "ATTACH DATABASE '$targetPath' AS encrypted KEY '$keySafe';
SELECT sqlcipher_export('encrypted');
DETACH DATABASE encrypted;" | sqlcipher '$sourcePath'
EOD;
        shell_exec($cmd);
        return file_exists($targetPath);
    }

    public static function isEncrypted(string $path): bool
    {
        $output = shell_exec("strings " . escapeshellarg($path) . " | grep 'SQLite format 3'");
        return trim($output) === '';
    }
}
