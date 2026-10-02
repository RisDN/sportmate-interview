<?php

namespace App\Services;

use App\Git\GitHub\GitHubProvider;
use App\Git\GitProvider;
use InvalidArgumentException;

final readonly class GitProviderRegistry
{
    public function __construct(private GitHubProvider $github) {}

    /** @return list<string> */
    public function keys(): array
    {
        return [$this->github->getKey()];
    }

    public function find(string $key): ?GitProvider
    {
        return $key === $this->github->getKey() ? $this->github : null;
    }

    public function get(string $key): GitProvider
    {
        return $this->find($key) ?? throw new InvalidArgumentException('Unsupported Git provider.');
    }
}
