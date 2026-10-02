<?php

namespace App\Git;

use App\Git\Exceptions\GitProviderException;
use DateTimeImmutable;

interface GitProvider
{
    public function getKey(): string;

    public function getName(): string;

    public function getUrlPrefix(): string;

    public function isValidAccountName(string $name): bool;

    /**
     * Resolve an account's type through the remote provider.
     *
     * @throws GitProviderException
     */
    public function getAccountType(string $name): AccountType;

    /**
     * Resolve an account and bind the source to this provider instance.
     *
     * @throws GitProviderException
     */
    public function getSource(string $name): GitSource;

    /**
     * Get all public repositories owned by the source, including forks and archives.
     *
     * @return list<RemoteRepository>
     *
     * @throws GitProviderException
     */
    public function getRepositories(GitSource $source): array;

    /** @throws GitProviderException */
    public function getRepositoriesPage(GitSource $source, int $page = 1): RepositoryPage;

    /** @throws GitProviderException */
    public function getRepositoryDetails(GitSource $source, string $name): RepositoryDetails;

    /** @throws GitProviderException */
    public function getPullRequestsPage(GitSource $source, string $name, string $externalId, int $page = 1, int $perPage = 1): PullRequestPage;

    /** @throws GitProviderException */
    public function getLastCommitAt(GitSource $source, string $name): ?DateTimeImmutable;
}
