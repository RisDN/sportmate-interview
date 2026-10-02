<?php

namespace App\Git;

readonly class RemoteRepository
{
    public function __construct(
        public string $id,
        public string $name,
        public string $fullName,
        public string $url,
        public ?string $description,
    ) {}
}
