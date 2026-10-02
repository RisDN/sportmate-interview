<?php

namespace App\Jobs;

use App\Models\GitSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteGitSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    public function __construct(public int $sourceId)
    {
        $this->onConnection('database');
        $this->beforeCommit();
    }

    public function handle(): void
    {
        // The foreign key removes repositories atomically; duplicate deliveries are harmless.
        GitSource::query()->whereKey($this->sourceId)->whereNotNull('marked_for_deletion_at')->delete();
    }
}
