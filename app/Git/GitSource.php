<?php

namespace App\Git;

use App\Git\Exceptions\GitProviderException;

final readonly class GitSource
{
    public function __construct(
        private GitProvider $provider,
        private string $name,
        private AccountType $accountType,
    ) {}

    public function getProvider(): GitProvider
    {
        return $this->provider;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAccountType(): AccountType
    {
        return $this->accountType;
    }

    /**
     * @return list<RemoteRepository>
     *
     * @throws GitProviderException
     */
    public function getRepositories(): array
    {
        return $this->provider->getRepositories($this);
    }
}
