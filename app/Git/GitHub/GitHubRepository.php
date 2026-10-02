<?php

namespace App\Git\GitHub;

use App\Git\RemoteRepository;

final readonly class GitHubRepository extends RemoteRepository
{
    public function __construct(
        string $id,
        string $name,
        string $fullName,
        string $url,
        ?string $description,
        public int $stars,
        public int $forks,
        public ?string $language,
        public bool $archived,
    ) {
        parent::__construct($id, $name, $fullName, $url, $description);
    }
}
