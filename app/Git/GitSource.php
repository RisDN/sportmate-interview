<?php

namespace App\Git;

use App\Git\Exceptions\GitProviderException;

final readonly class GitSource
{
    public function __construct(
        private GitProvider $provider,
        private string $name,
        private AccountType $accountType,
        private string $remoteId,
        private string $displayName,
        private string $url,
        private ?string $avatarUrl = null,
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

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
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
