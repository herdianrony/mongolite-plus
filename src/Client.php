<?php

namespace MongoLitePlus;

class Client
{
    protected string $dataPath;
    protected array $databases = [];

    public function __construct(string $dataPath)
    {
        $this->dataPath = rtrim($dataPath, DIRECTORY_SEPARATOR);
        if (!is_dir($this->dataPath)) {
            mkdir($this->dataPath, 0777, true);
        }
    }

    public function __get(string $name): Database
    {
        return $this->getDatabase($name);
    }

    public function getDatabase(string $name, ?string $encryptionKey = null): Database
    {
        $cacheKey = $name . ($encryptionKey ? '_' . md5($encryptionKey) : '');
        if (!isset($this->databases[$cacheKey])) {
            $file = $this->dataPath . DIRECTORY_SEPARATOR . $name . '.sqlite';
            $this->databases[$cacheKey] = new Database($file, $encryptionKey);
        }
        return $this->databases[$cacheKey];
    }

    public function listDatabases(): array
    {
        $dbs = [];
        foreach (glob($this->dataPath . '/*.sqlite') as $file) {
            $dbs[] = basename($file, '.sqlite');
        }
        return $dbs;
    }

    public function backupDatabase(string $name, string $backupDir, int $keepLast = 5): string
    {
        $db = $this->getDatabase($name);
        $backupFile = $db->backup($backupDir);

        $this->cleanupOldBackups($db->getFilePath(), $backupDir, $keepLast);

        return $backupFile;
    }

    protected function cleanupOldBackups(string $dbFilePath, string $backupDir, int $keepLast): void
    {
        $backups = glob($backupDir . '/' . basename($dbFilePath) . '*');
        if (count($backups) > $keepLast) {
            usort($backups, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            for ($i = $keepLast; $i < count($backups); $i++) {
                unlink($backups[$i]);
            }
        }
    }

    public function backupAll(string $backupDir, int $keepLast = 5): array
    {
        $backups = [];
        foreach ($this->listDatabases() as $dbName) {
            $backups[$dbName] = $this->backupDatabase($dbName, $backupDir, $keepLast);
        }
        return $backups;
    }

    public function migrateData(
        string $fromDb,
        string $toDb,
        string $collection,
        callable $filter
    ): int {
        $source = $this->getDatabase($fromDb)->$collection;
        $target = $this->getDatabase($toDb)->$collection;

        $docs = $source->find($filter)->toArray();
        if (empty($docs)) {
            return 0;
        }

        $ids = $target->insertMany($docs);
        return count($ids);
    }

    public function getShardedCollection(
        string $database,
        string $collection,
        string $shardKey
    ): Collection {
        $shard = abs(crc32($shardKey)) % 10;
        $shardDbName = $database . '_shard' . $shard;
        $db = $this->getDatabase($shardDbName);
        return $db->$collection;
    }

    public function crossDatabase(): CrossDatabase
    {
        return new CrossDatabase($this);
    }
}
