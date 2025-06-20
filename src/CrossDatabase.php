<?php

namespace MongoLitePlus;

class CrossDatabase
{
    protected Client $client;
    protected array $cache = [];
    protected array $listeners = [];

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function getDocument(string $database, string $collection, string $id): ?array
    {
        $cacheKey = "$database.$collection.$id";

        if (!isset($this->cache[$cacheKey])) {
            $db = $this->client->getDatabase($database);
            $this->cache[$cacheKey] = $db->$collection->findOne(['_id' => $id]);
        }

        return $this->cache[$cacheKey];
    }

    public function getRelated(array $sourceDoc, array $relationDef): array
    {
        $result = [];

        foreach ($relationDef as $key => $target) {
            [$database, $collection] = explode('.', $target);

            if (isset($sourceDoc[$key])) {
                $result[$key] = $this->getDocument($database, $collection, $sourceDoc[$key]);
            }
        }

        return $result;
    }

    public function getManyRelated(string $sourceId, string $relationDef): array
    {
        $parts = explode(':', $relationDef);

        if (count($parts) < 3) {
            throw new \InvalidArgumentException("Invalid relation definition: $relationDef");
        }

        [$database, $collection, $field] = $parts;

        $db = $this->client->getDatabase($database);
        return $db->$collection->find([$field => $sourceId])->toArray();
    }

    public function preloadDocuments(array $ids, string $database, string $collection): void
    {
        if (empty($ids)) return;

        $db = $this->client->getDatabase($database);
        $docs = $db->$collection->find(['_id' => ['$in' => $ids]])->toArray();

        foreach ($docs as $doc) {
            $cacheKey = "$database.$collection.{$doc['_id']}";
            $this->cache[$cacheKey] = $doc;
        }
    }

    public function onUpdate(string $database, string $collection, callable $callback): void
    {
        $key = "$database.$collection";
        $this->listeners[$key][] = $callback;
    }

    public function handleUpdate(string $database, string $collection, string $id, array $newData): void
    {
        $key = "$database.$collection.$id";

        if (isset($this->cache[$key])) {
            $this->cache[$key] = array_merge($this->cache[$key], $newData);
        }

        if (isset($this->listeners["$database.$collection"])) {
            foreach ($this->listeners["$database.$collection"] as $callback) {
                $callback($id, $newData);
            }
        }
    }

    public function embedData(string $sourceDb, string $sourceColl, string $sourceId, array $embedRules): array
    {
        $sourceDoc = $this->getDocument($sourceDb, $sourceColl, $sourceId);

        if (!$sourceDoc) {
            return [];
        }

        $embedded = [];

        foreach ($embedRules as $embedKey => $rule) {
            $parts = explode(':', $rule);
            if (count($parts) < 3) continue;

            [$targetDb, $targetColl, $targetField] = $parts;

            if (isset($sourceDoc[$targetField])) {
                $targetId = $sourceDoc[$targetField];
                $embedded[$embedKey] = $this->getDocument($targetDb, $targetColl, $targetId);
            }
        }

        return array_merge($sourceDoc, $embedded);
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
