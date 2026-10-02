<?php

namespace Tests\Fixtures\Git;

use App\Git\AccountType;
use App\Git\GitProvider;
use App\Git\GitSource;
use App\Git\PullRequestPage;
use App\Git\RemoteRepository;
use App\Git\RepositoryDetails;
use App\Git\RepositoryPage;
use DateTimeImmutable;
use LogicException;

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

    public function getUrlPrefix(): string
    {
        return 'https://git.example.test';
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

    public function getRepositoriesPage(GitSource $source, int $page = 1): RepositoryPage
    {
        return new RepositoryPage($page === 1 ? $this->getRepositories($source) : [], null);
    }

    public function getRepositoryDetails(GitSource $source, string $name): RepositoryDetails
    {
        throw new LogicException('Repository details are not configured for this fixture.');
    }

    public function getPullRequestsPage(GitSource $source, string $name, string $externalId, int $page = 1, int $perPage = 1): PullRequestPage
    {
        throw new LogicException('Pull requests are not configured for this fixture.');
    }

    public function getLastCommitAt(GitSource $source, string $name): ?DateTimeImmutable
    {
        throw new LogicException('Commits are not configured for this fixture.');
    }
}
