<?php

namespace MongoLitePlus;

class Cursor implements \IteratorAggregate
{
    protected array $docs;
    protected $filter = null;
    protected array $sort = [];
    protected ?int $limit = null;
    protected int $skip = 0;

    public function __construct(array $docs)
    {
        $this->docs = $docs;
    }

    public function filter(callable $fn): self
    {
        $this->filter = $fn;
        return $this;
    }

    public function sort(array $fields): self
    {
        $this->sort = $fields;
        return $this;
    }

    public function limit(int $n): self
    {
        $this->limit = $n;
        return $this;
    }

    public function skip(int $n): self
    {
        $this->skip = $n;
        return $this;
    }

    public function count(): int
    {
        return count($this->applyAll());
    }

    public function toArray(): array
    {
        return array_values($this->applyAll());
    }

    public function each(callable $cb): void
    {
        foreach ($this->applyAll() as $d) {
            $cb($d);
        }
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    protected function applyAll(): array
    {
        $out = $this->docs;

        if ($this->filter) {
            $out = array_filter($out, $this->filter);
        }

        if ($this->sort) {
            usort($out, function ($a, $b) {
                foreach ($this->sort as $f => $o) {
                    $va = $a[$f] ?? null;
                    $vb = $b[$f] ?? null;

                    if ($va === $vb) continue;

                    $res = is_numeric($va) && is_numeric($vb)
                        ? $va <=> $vb
                        : strcmp((string)$va, (string)$vb);

                    if ($res !== 0) {
                        return ($o === 1) ? $res : -$res;
                    }
                }
                return 0;
            });
        }

        if ($this->skip) {
            $out = array_slice($out, $this->skip);
        }

        if ($this->limit !== null) {
            $out = array_slice($out, 0, $this->limit);
        }

        return $out;
    }
}
