<?php

namespace MongoLitePlus;

class Utils
{
    public static function getNested(array $array, string $path, $default = null)
    {
        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        return $current;
    }

    public static function setNested(array &$array, string $path, $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        $current = $value;
    }

    public static function evaluateExpression($expr, array $doc)
    {
        if (is_array($expr)) {
            if (isset($expr['$multiply'])) {
                $factors = array_map(fn($e) => self::evaluateExpression($e, $doc), $expr['$multiply']);
                return array_product($factors);
            }

            if (isset($expr['$sum'])) {
                $sum = 0;
                foreach ($expr['$sum'] as $e) {
                    $val = self::evaluateExpression($e, $doc);
                    if (is_numeric($val)) $sum += $val;
                }
                return $sum;
            }
        } elseif (is_string($expr) && $expr[0] === '$') {
            return self::getNested($doc, substr($expr, 1));
        }

        return $expr;
    }

    public static function nestedExists(array $array, string $path): bool
    {
        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }

        return true;
    }

    public static function unsetNested(array &$array, string $path): void
    {
        $keys = explode('.', $path);
        $current = &$array;

        for ($i = 0; $i < count($keys) - 1; $i++) {
            $key = $keys[$i];
            if (!isset($current[$key])) {
                return;
            }
            $current = &$current[$key];
        }

        $lastKey = $keys[count($keys) - 1];
        unset($current[$lastKey]);
    }

    public static function matches(array $doc, array $criteria): bool
    {
        foreach ($criteria as $field => $condition) {
            $value = self::getNested($doc, $field, null);

            if (is_array($condition)) {
                foreach ($condition as $operator => $opValue) {
                    switch ($operator) {
                        case '$eq':
                            if ($value != $opValue) return false;
                            break;
                        case '$ne':
                            if ($value == $opValue) return false;
                            break;
                        case '$gt':
                            if ($value <= $opValue) return false;
                            break;
                        case '$gte':
                            if ($value < $opValue) return false;
                            break;
                        case '$lt':
                            if ($value >= $opValue) return false;
                            break;
                        case '$lte':
                            if ($value > $opValue) return false;
                            break;
                        case '$in':
                            if (!in_array($value, $opValue)) return false;
                            break;
                        case '$nin':
                            if (in_array($value, $opValue)) return false;
                            break;
                        case '$exists':
                            $exists = !is_null($value);
                            if ($opValue && !$exists) return false;
                            if (!$opValue && $exists) return false;
                            break;
                    }
                }
            } else {
                if ($value !== $condition) {
                    return false;
                }
            }
        }
        return true;
    }
}
