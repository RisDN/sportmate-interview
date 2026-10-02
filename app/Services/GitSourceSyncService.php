<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Git\Exceptions\InvalidResponseException;
use App\Git\Exceptions\RateLimitException;
use App\Git\Exceptions\SourceNotFoundException;
use App\Git\GitSource as ProviderSource;
use App\Git\RepositoryDetails;
use App\Jobs\SyncGitSource;
use App\Models\GitSource;
use App\Models\RemoteRepository;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-import-type RepositoryDetailsPayload from RepositoryDetails
 *
 * @phpstan-type Checkpoint array{job_attempt: int, page: int, next_page: int|null, repositories: list<array{id: string, name: string}>, index: int, phase: string, details: RepositoryDetailsPayload|null, repository_version: int|null, pulls_page: int, pulls_per_page: int, pulls_count: int, consistency_attempts: int, transient_failures: int}
 * @phpstan-type Outcome array{delay: int|null, error: string|null}
 */
final readonly class GitSourceSyncService
{
    private const ACTIVE_STATUSES = ['queued', 'syncing', 'waiting'];

    public function __construct(private GitProviderRegistry $providers) {}

    public function start(GitSource $source): GitSource
    {
        return DB::transaction(function () use ($source): GitSource {
            $runId = (string) Str::uuid();

            // The conditional write acquires SQLite's writer lock before reading the checkpoint.
            $started = GitSource::query()->whereKey($source->id)->available()
                ->whereNotIn('sync_status', self::ACTIVE_STATUSES)
                ->update([
                    'sync_status' => SyncStatus::Queued->value,
                    'sync_run_id' => $runId,
                    'sync_retry_at' => null,
                    'sync_revision' => DB::raw('sync_revision + 1'),
                ]);

            $current = $source->fresh();

            if ($current === null || $current->marked_for_deletion_at !== null) {
                throw (new ModelNotFoundException)->setModel(GitSource::class, [$source->id]);
            }

            if ($started === 0) {
                return $current;
            }

            $checkpoint = $this->checkpoint($current);
            $checkpoint['job_attempt'] = 0;
            $checkpoint['transient_failures'] = 0;
            $checkpoint['consistency_attempts'] = 0;
            $current->sync_checkpoint = $checkpoint;
            $current->save();

            // Both the source update and queue insertion use the same SQLite transaction.
            Queue::connection('database')->push(new SyncGitSource($current->id, $runId));

            return $current;
        });
    }

    /** @return Outcome */
    public function advance(int $sourceId, string $runId, int $attempt): array
    {
        $source = $this->active($sourceId, $runId)->first();

        if ($source === null) {
            return $this->outcome();
        }

        $checkpoint = $this->checkpoint($source);

        if ($attempt <= $checkpoint['job_attempt']) {
            return $this->outcome();
        }

        $revision = $source->sync_revision;
        $waiting = $source->sync_retry_at?->isFuture() ?? false;
        $checkpoint['job_attempt'] = $attempt;
        $prepared = clone $source;
        $prepared->forceFill([
            'sync_status' => $waiting ? $source->sync_status : SyncStatus::Syncing,
            'sync_retry_at' => $waiting ? $source->sync_retry_at : null,
            'sync_checkpoint' => $checkpoint,
            'sync_revision' => $revision + 1,
        ]);
        $claimed = $this->active($sourceId, $runId)->where('sync_revision', $revision)->update($prepared->getDirty());

        if ($claimed === 0) {
            return $this->outcome();
        }

        $source = $prepared->syncOriginal();

        if ($waiting && $source->sync_retry_at !== null) {
            return $this->outcome($source->sync_retry_at->getTimestamp() - now()->getTimestamp());
        }

        try {
            $provider = $this->providers->get($source->provider);
            $remote = new ProviderSource(
                $provider, $source->account, $source->account_type, $source->remote_id,
                $source->name, $source->url, $source->avatar_url,
            );

            return match ($checkpoint['phase']) {
                'page' => $this->readPage($source, $remote, $checkpoint),
                'details' => $this->readDetails($source, $remote, $checkpoint),
                'pull_requests' => $this->readPullRequests($source, $remote, $checkpoint),
                'commit' => $this->readCommit($source, $remote, $checkpoint),
                'verify_source' => $this->verifySource($source, $remote, $checkpoint),
                default => throw new InvalidResponseException('The synchronization checkpoint is invalid.'),
            };
        } catch (SourceNotFoundException $exception) {
            if (! in_array($checkpoint['phase'], ['page', 'verify_source'], true)) {
                $checkpoint['phase'] = 'verify_source';
                $checkpoint['transient_failures'] = 0;

                return $this->saveProgress($source, $checkpoint);
            }

            return $this->handleError($source, $checkpoint, $exception);
        } catch (Throwable $exception) {
            return $this->handleError($source, $checkpoint, $exception);
        }
    }

    public function failed(int $sourceId, string $runId, int $attempt, ?Throwable $exception): void
    {
        $source = $this->active($sourceId, $runId)->first();

        if ($source !== null) {
            $checkpoint = $this->checkpoint($source);

            if ($attempt < $checkpoint['job_attempt']) {
                return;
            }

            // The crash limit can fail the next reservation before handle() records that attempt.
            $checkpoint['job_attempt'] = $attempt;
            $this->recordFailure($source, $checkpoint, $exception ?? new RuntimeException('Synchronization worker stopped.'));
        }
    }

    /**
     * @param  Checkpoint  $checkpoint
     * @return Outcome
     */
    private function verifySource(GitSource $source, ProviderSource $remote, array $checkpoint): array
    {
        // A repository 404 also occurs when its entire owner disappears. Verify in a separate reservation.
        $remote->getProvider()->getRepositoriesPage($remote, 1);

        return $this->advanceRepository($source, $checkpoint);
    }

    /** @param Checkpoint $checkpoint
     * @return Outcome
     */
    private function readPage(GitSource $source, ProviderSource $remote, array $checkpoint): array
    {
        $page = $remote->getProvider()->getRepositoriesPage($remote, $checkpoint['page']);

        if (count($page->repositories) > 100) {
            throw new InvalidResponseException('The repository page is too large.');
        }

        $checkpoint['repositories'] = array_map(
            fn ($repository): array => ['id' => $repository->id, 'name' => $repository->name],
            $page->repositories,
        );
        $checkpoint['next_page'] = $page->nextPage;
        $checkpoint['index'] = 0;
        $checkpoint['phase'] = 'details';
        $checkpoint['transient_failures'] = 0;

        return $this->saveProgress($source, $checkpoint);
    }

    /** @param Checkpoint $checkpoint
     * @return Outcome
     */
    private function readDetails(GitSource $source, ProviderSource $remote, array $checkpoint): array
    {
        $repository = $checkpoint['repositories'][$checkpoint['index']];
        $existing = RemoteRepository::query()->find($repository['id']);
        $details = $remote->getProvider()->getRepositoryDetails($remote, $repository['name']);

        if ($details->id !== $repository['id'] || $details->isPrivate || $details->ownerId !== $source->remote_id) {
            return $this->advanceRepository($source, $checkpoint);
        }

        $checkpoint['details'] = $details->toArray();
        $checkpoint['repository_version'] = $existing?->sync_version;
        $checkpoint['phase'] = 'pull_requests';
        $checkpoint['pulls_page'] = 1;
        $checkpoint['pulls_per_page'] = 1;
        $checkpoint['pulls_count'] = 0;
        $checkpoint['transient_failures'] = 0;

        return $this->saveProgress($source, $checkpoint);
    }

    /** @param Checkpoint $checkpoint
     * @return Outcome
     */
    private function readPullRequests(GitSource $source, ProviderSource $remote, array $checkpoint): array
    {
        $details = $this->details($checkpoint);
        $page = $remote->getProvider()->getPullRequestsPage(
            $remote, $details->name, $details->id, $checkpoint['pulls_page'], $checkpoint['pulls_per_page'],
        );
        $checkpoint['transient_failures'] = 0;

        if ($page->total !== null) {
            $checkpoint['pulls_count'] = $page->total;
        } elseif ($checkpoint['pulls_per_page'] === 1 && $page->nextPage !== null) {
            // A missing last link requires walking pages; restart at 100 rows without double counting.
            $checkpoint['pulls_page'] = 1;
            $checkpoint['pulls_per_page'] = 100;
            $checkpoint['pulls_count'] = 0;

            return $this->saveProgress($source, $checkpoint);
        } else {
            $checkpoint['pulls_count'] += $page->count;

            if ($page->nextPage !== null) {
                $checkpoint['pulls_page'] = $page->nextPage;

                return $this->saveProgress($source, $checkpoint);
            }
        }

        if ($checkpoint['pulls_count'] > $details->openIssuesCount) {
            $checkpoint['consistency_attempts']++;

            if ($checkpoint['consistency_attempts'] >= 3) {
                return $this->recordFailure($source, $checkpoint, new InvalidResponseException('Repository issue counters remained inconsistent.'));
            }

            $checkpoint['phase'] = 'details';
            $checkpoint['details'] = null;
        } else {
            $checkpoint['phase'] = 'commit';
        }

        return $this->saveProgress($source, $checkpoint);
    }

    /** @param Checkpoint $checkpoint
     * @return Outcome
     */
    private function readCommit(GitSource $source, ProviderSource $remote, array $checkpoint): array
    {
        $details = $this->details($checkpoint);
        $committedAt = $remote->getProvider()->getLastCommitAt($remote, $details->name);
        $next = $this->nextRepository($checkpoint);
        $completed = $this->normalizePage($next);
        $attributes = $this->progressAttributes($checkpoint, false);
        $conflict = false;

        $saved = $this->saveState($source, $attributes, function () use ($source, $checkpoint, $details, $committedAt, $next, $completed, &$conflict): array {
            $existing = RemoteRepository::query()->find($details->id);

            if ($existing?->sync_version !== $checkpoint['repository_version']) {
                // Another source may have observed a transfer while this job was waiting for the API.
                $conflict = true;
                $checkpoint['phase'] = 'details';
                $checkpoint['details'] = null;
                $checkpoint['repository_version'] = null;
                $checkpoint['transient_failures'] = 0;

                return $this->progressAttributes($checkpoint, false);
            }

            ($existing ?? new RemoteRepository)->forceFill([
                'external_id' => $details->id,
                'git_source_id' => $source->id,
                'name' => $details->name,
                'description' => $details->description,
                'stars_count' => $details->stars,
                'issues_count' => $details->openIssuesCount - $checkpoint['pulls_count'],
                'pull_requests_count' => $checkpoint['pulls_count'],
                'forks_count' => $details->forks,
                'language' => $details->language,
                'archived' => $details->archived,
                'last_committed_at' => $committedAt,
                'sync_version' => ($checkpoint['repository_version'] ?? 0) + 1,
            ])->save();

            return $this->progressAttributes($next, $completed);
        });

        return $this->outcome($saved && (! $completed || $conflict) ? 0 : null);
    }

    /** @param Checkpoint $checkpoint
     * @return Outcome
     */
    private function advanceRepository(GitSource $source, array $checkpoint): array
    {
        return $this->saveProgress($source, $this->nextRepository($checkpoint));
    }

    /** @param Checkpoint $checkpoint
     * @return Checkpoint
     */
    private function nextRepository(array $checkpoint): array
    {
        $checkpoint['index']++;
        $checkpoint['phase'] = 'details';
        $checkpoint['details'] = null;
        $checkpoint['repository_version'] = null;
        $checkpoint['pulls_page'] = 1;
        $checkpoint['pulls_per_page'] = 1;
        $checkpoint['pulls_count'] = 0;
        $checkpoint['consistency_attempts'] = 0;
        $checkpoint['transient_failures'] = 0;

        return $checkpoint;
    }

    /** @param Checkpoint $checkpoint */
    private function normalizePage(array &$checkpoint): bool
    {
        if ($checkpoint['index'] < count($checkpoint['repositories'])) {
            return false;
        }

        if ($checkpoint['next_page'] === null) {
            return true;
        }

        $checkpoint['page'] = $checkpoint['next_page'];
        $checkpoint['next_page'] = null;
        $checkpoint['repositories'] = [];
        $checkpoint['index'] = 0;
        $checkpoint['phase'] = 'page';

        return false;
    }

    /** @param Checkpoint $checkpoint
     * @return Outcome
     */
    private function saveProgress(GitSource $source, array $checkpoint): array
    {
        $completed = $this->normalizePage($checkpoint);
        $saved = $this->saveState($source, $this->progressAttributes($checkpoint, $completed));

        return $this->outcome($saved && ! $completed ? 0 : null);
    }

    /** @param Checkpoint $checkpoint
     * @return array<string, mixed>
     */
    private function progressAttributes(array $checkpoint, bool $completed): array
    {
        if ($completed) {
            return [
                'sync_status' => SyncStatus::Succeeded,
                'sync_checkpoint' => null,
                'sync_retry_at' => null,
                'last_synced_at' => now(),
                'last_sync_error_code' => null,
                'last_sync_error_at' => null,
            ];
        }

        return ['sync_status' => SyncStatus::Syncing, 'sync_checkpoint' => $checkpoint, 'sync_retry_at' => null];
    }

    /** @param Checkpoint $checkpoint
     * @return Outcome
     */
    private function handleError(GitSource $source, array $checkpoint, Throwable $exception): array
    {
        if ($exception instanceof RateLimitException) {
            $delay = $exception->retryAt === null ? 60 : $exception->retryAt->getTimestamp() - now()->getTimestamp();
            $delay = $delay > 0 ? $delay : 60;
        } elseif (GitSyncError::isTransient($exception)) {
            $checkpoint['transient_failures']++;

            if ($checkpoint['transient_failures'] >= 3) {
                return $this->recordFailure($source, $checkpoint, $exception);
            }

            $delay = $checkpoint['transient_failures'] === 1 ? 10 : 60;
        } else {
            return $this->recordFailure($source, $checkpoint, $exception);
        }

        $saved = $this->saveState($source, [
            'sync_status' => SyncStatus::Waiting,
            'sync_checkpoint' => $checkpoint,
            'sync_retry_at' => now()->addSeconds($delay),
            'last_sync_error_code' => GitSyncError::code($exception),
            'last_sync_error_at' => now(),
        ]);

        if ($saved) {
            $this->logError($source, $exception);
        }

        return $this->outcome($saved ? $delay : null);
    }

    /** @param Checkpoint $checkpoint
     * @return Outcome
     */
    private function recordFailure(GitSource $source, array $checkpoint, Throwable $exception): array
    {
        $code = GitSyncError::code($exception);
        $saved = $this->saveState($source, [
            'sync_status' => SyncStatus::Failed,
            'sync_checkpoint' => $checkpoint,
            'sync_retry_at' => null,
            'last_sync_error_code' => $code,
            'last_sync_error_at' => now(),
        ]);

        if ($saved) {
            $this->logError($source, $exception);
        }

        return $this->outcome(error: $saved ? $code : null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Closure(): array<string, mixed>|null  $write
     */
    private function saveState(GitSource $source, array $attributes, ?Closure $write = null): bool
    {
        return DB::transaction(function () use ($source, $attributes, $write): bool {
            $revision = $source->sync_revision;
            $changed = $this->active($source->id, (string) $source->sync_run_id)
                ->where('sync_revision', $revision)
                ->update(['sync_revision' => $revision + 1]);

            if ($changed === 0) {
                return false;
            }

            $overrides = $write === null ? [] : $write();
            $updated = clone $source;
            $updated->forceFill([...$attributes, ...$overrides, 'sync_revision' => $revision + 1]);
            $updated->save();

            return true;
        });
    }

    private function logError(GitSource $source, Throwable $exception): void
    {
        Log::error('Git source synchronization encountered an error.', [
            'git_source_id' => $source->id,
            'sync_run_id' => $source->sync_run_id,
            ...GitSyncError::context($exception),
        ]);
    }

    /** @return Builder<GitSource> */
    private function active(int $sourceId, string $runId): Builder
    {
        return GitSource::query()->available()->whereKey($sourceId)->where('sync_run_id', $runId)
            ->whereIn('sync_status', self::ACTIVE_STATUSES);
    }

    /** @return Checkpoint */
    private function checkpoint(GitSource $source): array
    {
        /** @var Checkpoint $checkpoint */
        $checkpoint = ['job_attempt' => 0, ...($source->sync_checkpoint ?? [
            'page' => 1,
            'next_page' => null,
            'repositories' => [],
            'index' => 0,
            'phase' => 'page',
            'details' => null,
            'repository_version' => null,
            'pulls_page' => 1,
            'pulls_per_page' => 1,
            'pulls_count' => 0,
            'consistency_attempts' => 0,
            'transient_failures' => 0,
        ])];

        return $checkpoint;
    }

    /** @param Checkpoint $checkpoint */
    private function details(array $checkpoint): RepositoryDetails
    {
        if ($checkpoint['details'] === null) {
            throw new InvalidResponseException('The repository checkpoint is incomplete.');
        }

        return RepositoryDetails::fromArray($checkpoint['details']);
    }

    /** @return Outcome */
    private function outcome(?int $delay = null, ?string $error = null): array
    {
        return ['delay' => $delay, 'error' => $error];
    }
}
