<?php

namespace MongoLitePlus;

class FeatureDatabase
{
    protected string $basePath;
    protected array $databases = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0777, true);
        }
    }

    public function getDatabase(string $feature): Database
    {
        if (!isset($this->databases[$feature])) {
            $dbFile = $this->basePath . '/' . $feature . '.sqlite';
            $this->databases[$feature] = new Database($dbFile);
        }
        return $this->databases[$feature];
    }

    public function backupFeature(string $feature, string $backupDir): string
    {
        $db = $this->getDatabase($feature);
        return $db->backup($backupDir);
    }

    public function backupAll(string $backupDir): array
    {
        $backups = [];
        foreach ($this->listFeatures() as $feature) {
            $backups[$feature] = $this->backupFeature($feature, $backupDir);
        }
        return $backups;
    }

    public function listFeatures(): array
    {
        $features = [];
        foreach (glob($this->basePath . '/*.sqlite') as $file) {
            $features[] = basename($file, '.sqlite');
        }
        return $features;
    }

    public function migrateData(string $fromFeature, string $toFeature, string $collection, callable $filter): int
    {
        $source = $this->getDatabase($fromFeature)->$collection;
        $target = $this->getDatabase($toFeature)->$collection;

        $docs = $source->find($filter)->toArray();
        $ids = $target->insertMany($docs);

        return count($ids);
    }
}
