<?php

namespace MongoLitePlus;

class Client
{
    protected string $dataPath;

    public function __construct(string $dataPath)
    {
        $this->dataPath = rtrim($dataPath, DIRECTORY_SEPARATOR);
        if (!is_dir($this->dataPath)) {
            mkdir($this->dataPath, 0777, true);
        }
    }

    public function __get(string $name): Database
    {
        $file = $this->dataPath . DIRECTORY_SEPARATOR . $name . '.sqlite';
        return new Database($file);
    }
}
