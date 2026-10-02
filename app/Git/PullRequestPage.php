<?php

namespace App\Git;

final readonly class PullRequestPage
{
    public function __construct(
        public int $count,
        public ?int $total,
        public ?int $nextPage,
    ) {}
}
