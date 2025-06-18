<?php

namespace MongoLitePlus;

use PDO;
use Exception;
use PDOException;

class Collection
{
    protected PDO $pdo;
    protected string $name;

    public function __construct(PDO $pdo, string $name)
    {
        $this->pdo = $pdo;
        $this->name = $name;

        $this->ensureTable();
        $this->ensureIndexTable();
        $this->ensureRelationsTable();
    }

    protected function quote(string $s): string
    {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    protected function ensureTable(): void
    {
        $tbl = $this->quote($this->name);
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS $tbl (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                doc TEXT NOT NULL
            )
        ");
    }

    protected function ensureIndexTable(): void
    {
        $idx = $this->quote($this->name . '_index');
        $tbl = $this->quote($this->name);
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS $idx (
                parent_id INTEGER NOT NULL,
                field TEXT NOT NULL,
                value TEXT,
                type INTEGER,
                FOREIGN KEY(parent_id) REFERENCES $tbl(id) ON DELETE CASCADE
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$this->name}_parent ON $idx (parent_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$this->name}_field_value ON $idx (field, value)");
    }

    protected function ensureRelationsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS _relations (
                from_id TEXT NOT NULL,
                from_collection TEXT NOT NULL,
                to_id TEXT NOT NULL,
                to_collection TEXT NOT NULL,
                UNIQUE(from_id, to_id) ON CONFLICT REPLACE
            )
        ");
    }

    public function insert(array $doc): string
    {
        $now = time();
        $doc['_id'] = $doc['_id'] ?? $this->generateUUID();
        $doc['_created_at'] = $now;
        $doc['_updated_at'] = $now;

        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
        $tbl = $this->quote($this->name);

        $stmt = $this->pdo->prepare("INSERT INTO $tbl (doc) VALUES (:doc)");
        $stmt->execute([':doc' => $json]);
        $newId = (int)$this->pdo->lastInsertId();

        $this->insertIndexes($newId, $doc);
        return $doc['_id'];
    }

    public function insertMany(array $docs): array
    {
        $this->pdo->beginTransaction();
        $ids = [];
        try {
            foreach ($docs as $doc) {
                $ids[] = $this->insert($doc);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $ids;
    }

    protected function insertIndexes(int $parentId, array $doc): void
    {
        $idx = $this->quote($this->name . '_index');
        $this->pdo->prepare("DELETE FROM $idx WHERE parent_id = :pid")
            ->execute([':pid' => $parentId]);

        $ins = $this->pdo->prepare("INSERT INTO $idx (parent_id, field, value, type) VALUES (:pid, :field, :value, :type)");

        $flattened = $this->flattenDocument($doc);
        foreach ($flattened as $field => $value) {
            if (is_array($value) || is_object($value) || $value === null) {
                continue;
            }

            $type = match (true) {
                is_int($value) => 1,
                is_float($value) => 2,
                is_bool($value) => 3,
                default => 0
            };

            $stringValue = $this->convertValueForIndex($value);

            $ins->execute([
                ':pid' => $parentId,
                ':field' => $field,
                ':value' => $stringValue,
                ':type' => $type
            ]);
        }
    }

    protected function flattenDocument(array $doc, string $prefix = ''): array
    {
        $result = [];
        foreach ($doc as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if ($key === 'id') continue;

            if (is_array($value)) {
                if (!array_is_list($value)) {
                    $result = array_merge($result, $this->flattenDocument($value, $fullKey));
                } else {
                    foreach ($value as $k => $v) {
                        if (is_scalar($v)) {
                            $result["{$fullKey}.{$k}"] = $v;
                        }
                    }
                }
            } else if (is_scalar($value)) {
                $result[$fullKey] = $value;
            }
        }
        return $result;
    }

    private function convertValueForIndex($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }

    public function find(array|callable|null $criteria = null): Cursor
    {
        if (is_callable($criteria)) {
            return $this->findWithPhpFilter($criteria);
        }

        $tbl = $this->quote($this->name);
        $idx = $this->quote($this->name . '_index');

        $sql = "SELECT p.id, p.doc FROM $tbl p";
        $bind = [];
        $conditions = [];
        $joins = [];

        if (is_array($criteria) && $criteria) {
            $i = 0;
            foreach ($criteria as $field => $condition) {
                if (is_array($condition)) {
                    foreach ($condition as $operator => $value) {
                        $alias = "idx{$i}";
                        $bindKey = ":v{$i}";

                        $joins[] = "JOIN $idx $alias ON p.id = $alias.parent_id AND $alias.field = :f{$i}";
                        $bind[":f{$i}"] = $field;

                        switch ($operator) {
                            case '$gt':
                                $conditions[] = "$alias.value > $bindKey";
                                break;
                            case '$lt':
                                $conditions[] = "$alias.value < $bindKey";
                                break;
                            case '$gte':
                                $conditions[] = "$alias.value >= $bindKey";
                                break;
                            case '$lte':
                                $conditions[] = "$alias.value <= $bindKey";
                                break;
                            case '$ne':
                                $conditions[] = "$alias.value != $bindKey";
                                break;
                            case '$in':
                                $placeholders = [];
                                $values = is_array($value) ? $value : [$value];
                                foreach ($values as $k => $v) {
                                    $key = ":v{$i}_{$k}";
                                    $placeholders[] = $key;
                                    $bind[$key] = $this->convertValueForIndex($v);
                                }
                                $conditions[] = "$alias.value IN (" . implode(',', $placeholders) . ")";
                                break;
                            default:
                                $conditions[] = "$alias.value = $bindKey";
                        }

                        if ($operator !== '$in') {
                            $bind[$bindKey] = $this->convertValueForIndex($value);
                        }
                        $i++;
                    }
                } else {
                    $alias = "idx{$i}";
                    $joins[] = "JOIN $idx $alias ON p.id = $alias.parent_id AND $alias.field = :f{$i}";
                    $conditions[] = "$alias.value = :v{$i}";
                    $bind[":f{$i}"] = $field;
                    $bind[":v{$i}"] = $this->convertValueForIndex($condition);
                    $i++;
                }
            }
        }

        if (!empty($joins)) {
            $sql .= " " . implode(" ", $joins);
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " GROUP BY p.id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $doc = json_decode($row['doc'], true);
            $doc['id'] = (int)$row['id'];
            $out[] = $doc;
        }

        return new Cursor($out);
    }

    protected function findWithPhpFilter(callable $filter): Cursor
    {
        $all = $this->pdo->query("SELECT id, doc FROM {$this->quote($this->name)}")
            ->fetchAll(PDO::FETCH_ASSOC);
        $docs = array_map(fn($r) => ['id' => (int)$r['id']] + json_decode($r['doc'], true), $all);
        return (new Cursor($docs))->filter($filter);
    }

    public function findOne(array|callable $crit): ?array
    {
        return $this->find($crit)->limit(1)->toArray()[0] ?? null;
    }

    public function update(array $crit, array $newData, bool $replace = false): int
    {
        $count = 0;
        $this->pdo->beginTransaction();

        try {
            $cursor = $this->find($crit);
            $docs = $cursor->toArray();

            foreach ($docs as $doc) {
                $id = $doc['id'];
                unset($doc['id']);

                // Handle $inc operator
                if (!$replace && isset($newData['$inc'])) {
                    foreach ($newData['$inc'] as $field => $incValue) {
                        $current = $doc[$field] ?? 0;
                        $doc[$field] = is_numeric($current) ? $current + $incValue : $incValue;
                    }
                    unset($newData['$inc']);
                }

                if ($replace) {
                    $newData['_created_at'] = $doc['_created_at'] ?? time();
                    $newData['_updated_at'] = time();
                    $merged = $newData;
                } else {
                    $merged = array_merge($doc, $newData);
                    $merged['_updated_at'] = time();
                }

                $json = json_encode($merged, JSON_UNESCAPED_UNICODE);
                $tbl = $this->quote($this->name);

                $this->pdo->prepare("UPDATE $tbl SET doc = :doc WHERE id = :id")
                    ->execute([':doc' => $json, ':id' => $id]);

                $this->insertIndexes($id, $merged);
                $count++;
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $count;
    }

    public function remove(array|callable $crit): int
    {
        $count = 0;
        $this->pdo->beginTransaction();

        try {
            $cursor = $this->find($crit);
            $docs = $cursor->toArray();

            foreach ($docs as $doc) {
                if (isset($doc['id'])) {
                    $stmt = $this->pdo->prepare("DELETE FROM {$this->quote($this->name)} WHERE id = :id");
                    $stmt->execute([':id' => $doc['id']]);
                    $count++;
                }
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $count;
    }

    public function count(array|callable|null $crit = null): int
    {
        return $this->find($crit)->count();
    }

    public function createIndex(string $field, array $options = []): bool
    {
        $unique = $options['unique'] ?? false;
        $indexName = "idx_{$this->name}_{$field}";

        // Perbaikan: Gunakan field dan value secara spesifik
        $sql = "
        CREATE INDEX IF NOT EXISTS $indexName
        ON {$this->quote($this->name . '_index')} (field, value)
        WHERE field = '$field'
    ";

        try {
            $this->pdo->exec($sql);

            // Tambahan untuk unique constraint
            if ($unique) {
                $this->pdo->exec("
                CREATE UNIQUE INDEX IF NOT EXISTS {$indexName}_unique
                ON {$this->quote($this->name . '_index')} (value)
                WHERE field = '$field'
            ");
            }

            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function dropIndex(string $field): bool
    {
        $indexName = "idx_{$this->name}_{$field}";
        try {
            $this->pdo->exec("DROP INDEX IF EXISTS $indexName");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function listIndexes(): array
    {
        $autoIndexes = [
            "idx_{$this->name}_parent",
            "idx_{$this->name}_field_value"
        ];

        $stmt = $this->pdo->query("
        SELECT name 
        FROM sqlite_master
        WHERE type = 'index'
        AND name LIKE 'idx_{$this->name}%'
        AND name NOT IN ('" . implode("','", $autoIndexes) . "')
    ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function relateTo(string $fromId, string $toColl, string $toId): bool
    {
        // Hanya validasi dokumen sumber
        if (!$this->findOne(['_id' => $fromId])) {
            return false;
        }

        // Tidak validasi dokumen tujuan karena bisa di database lain
        try {
            $this->pdo->prepare("
            INSERT INTO _relations (from_id, from_collection, to_id, to_collection)
            VALUES (:fid, :fc, :tid, :tc)
            ON CONFLICT (from_id, to_id) DO UPDATE SET 
                to_collection = EXCLUDED.to_collection
        ")->execute([
                ':fid' => $fromId,
                ':fc' => $this->name,
                ':tid' => $toId,
                ':tc' => $toColl
            ]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function getRelations(string $id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM _relations 
            WHERE from_id = :id AND from_collection = :col
        ");
        $stmt->execute([':id' => $id, ':col' => $this->name]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
