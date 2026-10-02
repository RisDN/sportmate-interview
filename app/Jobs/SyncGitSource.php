<?php

namespace App\Jobs;

use App\Services\GitSourceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Throwable;

class SyncGitSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public bool $countCrashesAsExceptions = true;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $backoff = 10;

    public function __construct(public int $sourceId, public string $runId)
    {
        $this->onConnection('database');
        $this->beforeCommit();
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('git-source:'.$this->sourceId))->releaseAfter(5)->expireAfter(75)];
    }

    public function handle(GitSourceSyncService $service): void
    {
        $result = $service->advance($this->sourceId, $this->runId, $this->attempts());

        if ($result['error'] !== null) {
            // Failed-job storage must not serialize upstream messages or credential-bearing causes.
            $this->fail(new RuntimeException($result['error']));
        } elseif ($result['delay'] !== null) {
            $this->release($result['delay']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(GitSourceSyncService::class)->failed($this->sourceId, $this->runId, $this->attempts(), $exception);
    }
}
