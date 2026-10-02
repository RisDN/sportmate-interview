<?php

namespace App\Git;

final readonly class RepositoryPage
{
    /** @param list<RemoteRepository> $repositories */
    public function __construct(
        public array $repositories,
        public ?int $nextPage,
    ) {}
}
