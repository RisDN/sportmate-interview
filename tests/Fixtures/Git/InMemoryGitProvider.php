<?php

namespace Tests\Fixtures\Git;

use App\Git\AccountType;
use App\Git\GitProvider;
use App\Git\GitSource;
use App\Git\RemoteRepository;

final class InMemoryGitProvider implements GitProvider
{
    public ?GitSource $requestedSource = null;

    /** @param list<RemoteRepository> $repositories */
    public function __construct(private readonly array $repositories) {}

    public function getKey(): string
    {
        return 'in-memory';
    }

    public function getName(): string
    {
        return 'In-memory Git';
    }

    public function isValidAccountName(string $name): bool
    {
        return $name !== '';
    }

    public function getAccountType(string $name): AccountType
    {
        return AccountType::Organization;
    }

    public function getSource(string $name): GitSource
    {
        return new GitSource(
            provider: $this,
            name: $name,
            accountType: $this->getAccountType($name),
            remoteId: '42',
            displayName: $name,
            url: 'https://git.example.test/'.$name,
        );
    }

    /** @return list<RemoteRepository> */
    public function getRepositories(GitSource $source): array
    {
        $this->requestedSource = $source;

        return $this->repositories;
    }
}
