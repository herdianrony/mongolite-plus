<?php

namespace MongoLitePlus;

class CrossDatabase
{
    protected FeatureDatabase $featureDB;
    protected array $cache = [];
    protected array $listeners = [];

    public function __construct(FeatureDatabase $featureDB)
    {
        $this->featureDB = $featureDB;
    }

    public function getDocument(string $feature, string $collection, string $id): ?array
    {
        $cacheKey = "$feature.$collection.$id";

        if (!isset($this->cache[$cacheKey])) {
            $db = $this->featureDB->getDatabase($feature);
            $this->cache[$cacheKey] = $db->$collection->findOne(['_id' => $id]);
        }

        return $this->cache[$cacheKey];
    }

    public function getRelated(array $sourceDoc, array $relationDef): array
    {
        $result = [];

        foreach ($relationDef as $key => $target) {
            [$feature, $collection] = explode('.', $target);

            if (isset($sourceDoc[$key])) {
                $result[$key] = $this->getDocument($feature, $collection, $sourceDoc[$key]);
            }
        }

        return $result;
    }

    public function getManyRelated(string $sourceId, string $relationDef): array
    {
        // Perbaikan parsing relationDef
        $parts = explode(':', $relationDef);

        if (count($parts) < 3) {
            throw new \InvalidArgumentException("Invalid relation definition: $relationDef");
        }

        $feature = $parts[0];
        $collection = $parts[1];
        $field = $parts[2];

        $db = $this->featureDB->getDatabase($feature);
        return $db->$collection->find([$field => $sourceId])->toArray();
    }

    public function preloadDocuments(array $ids, string $feature, string $collection): void
    {
        $db = $this->featureDB->getDatabase($feature);
        $docs = $db->$collection->find(['_id' => ['$in' => $ids]])->toArray();

        foreach ($docs as $doc) {
            $cacheKey = "$feature.$collection.{$doc['_id']}";
            $this->cache[$cacheKey] = $doc;
        }
    }

    public function onUpdate(string $feature, string $collection, callable $callback): void
    {
        $key = "$feature.$collection";
        $this->listeners[$key][] = $callback;
    }

    public function handleUpdate(string $feature, string $collection, string $id, array $newData): void
    {
        $key = "$feature.$collection.$id";

        // Update cache
        if (isset($this->cache[$key])) {
            $this->cache[$key] = array_merge($this->cache[$key], $newData);
        }

        // Trigger listeners
        if (isset($this->listeners["$feature.$collection"])) {
            foreach ($this->listeners["$feature.$collection"] as $callback) {
                $callback($id, $newData);
            }
        }
    }

    public function embedData(string $sourceFeature, string $sourceCollection, string $sourceId, array $embedRules): array
    {
        $sourceDoc = $this->getDocument($sourceFeature, $sourceCollection, $sourceId);
        $embedded = [];

        foreach ($embedRules as $embedKey => $rule) {
            [$targetFeature, $targetCollection, $targetField] = explode(':', $rule);

            if (isset($sourceDoc[$targetField])) {
                $embedded[$embedKey] = $this->getDocument(
                    $targetFeature,
                    $targetCollection,
                    $sourceDoc[$targetField]
                );
            }
        }

        return array_merge($sourceDoc, $embedded);
    }
}
