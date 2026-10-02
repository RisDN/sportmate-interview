<?php

namespace App\Git;

/**
 * @phpstan-type RepositoryDetailsPayload array{id: string, name: string, description: string|null, stars: int, forks: int, language: string|null, archived: bool, open_issues_count: int, owner_id: string, owner_name: string, is_private: bool}
 */
final readonly class RepositoryDetails
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public int $stars,
        public int $forks,
        public ?string $language,
        public bool $archived,
        public int $openIssuesCount,
        public string $ownerId,
        public string $ownerName,
        public bool $isPrivate,
    ) {}

    /** @return RepositoryDetailsPayload */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'stars' => $this->stars,
            'forks' => $this->forks,
            'language' => $this->language,
            'archived' => $this->archived,
            'open_issues_count' => $this->openIssuesCount,
            'owner_id' => $this->ownerId,
            'owner_name' => $this->ownerName,
            'is_private' => $this->isPrivate,
        ];
    }

    /** @param RepositoryDetailsPayload $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            description: $data['description'],
            stars: $data['stars'],
            forks: $data['forks'],
            language: $data['language'],
            archived: $data['archived'],
            openIssuesCount: $data['open_issues_count'],
            ownerId: $data['owner_id'],
            ownerName: $data['owner_name'],
            isPrivate: $data['is_private'],
        );
    }
}
