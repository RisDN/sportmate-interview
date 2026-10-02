<?php

namespace App\Git;

use App\Git\Exceptions\GitProviderException;

interface GitProvider
{
    public function getName(): string;

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
}
