<?php

namespace Tests\Fixtures\Cache;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

class FailingCacheStore extends ArrayStore
{
    public function __construct(private readonly string $operation)
    {
        parent::__construct();
    }

    public function get($key): mixed
    {
        if ($this->operation === 'get') {
            throw new RuntimeException('Cache read failed.');
        }

        return parent::get($key);
    }

    public function put($key, $value, $seconds): bool
    {
        if ($this->operation === 'put') {
            throw new RuntimeException('Cache write failed.');
        }

        return parent::put($key, $value, $seconds);
    }
}
