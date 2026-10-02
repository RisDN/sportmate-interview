<?php

use App\Enums\SyncStatus;
use App\Git\AccountType;
use App\Jobs\DeleteGitSource;
use App\Jobs\SyncGitSource;
use App\Models\GitSource;
use App\Models\RemoteRepository;
use App\Services\GitSourceService;
use App\Services\GitSourceSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Git\GitHubPayload;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.github.pat' => null]);
});

function queuedGitSource(array $attributes = []): GitSource
{
    $source = GitSource::factory()->create([
        'remote_id' => '958072',
        'account' => 'laravel',
        'normalized_account' => 'laravel',
        'account_type' => AccountType::Organization,
        ...$attributes,
    ]);

    return app(GitSourceSyncService::class)->start($source);
}

function runSyncReservations(int $count = 1): void
{
    app('queue.worker')->setCache(app('cache.store'));

    for ($index = 0; $index < $count; $index++) {
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
    }
}

function syncCommitPayload(string $date = '2026-09-30T12:00:00Z'): array
{
    return [['commit' => ['committer' => ['date' => $date]]]];
}

test('each reservation makes at most one request and publishes only complete repositories before final success', function () {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([
            GitHubPayload::repository(),
            GitHubPayload::repository(['id' => 124, 'name' => 'starter', 'full_name' => 'laravel/starter']),
        ]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository()),
        'https://api.github.com/repos/laravel/starter' => Http::response(GitHubPayload::repository(['id' => 124, 'name' => 'starter', 'full_name' => 'laravel/starter', 'archived' => true])),
        'https://api.github.com/repos/laravel/*/pulls?*' => Http::response([]),
        'https://api.github.com/repos/laravel/*/commits?*' => Http::response(syncCommitPayload()),
    ]);

    runSyncReservations(3);

    Http::assertSentCount(3);
    $this->assertDatabaseEmpty('remote_repositories');
    expect($source->fresh()->last_synced_at)->toBeNull();

    runSyncReservations();

    Http::assertSentCount(4);
    $this->assertDatabaseCount('remote_repositories', 1);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Syncing);
    expect($source->fresh()->last_synced_at)->toBeNull();
    $this->assertDatabaseHas('remote_repositories', [
        'external_id' => 'github:123', 'git_source_id' => $source->id, 'name' => 'framework',
        'stars_count' => 35000, 'issues_count' => 12, 'pull_requests_count' => 0,
        'forks_count' => 12000, 'language' => 'PHP', 'last_committed_at' => '2026-09-30 12:00:00',
    ]);

    runSyncReservations(3);

    Http::assertSentCount(7);
    $this->assertDatabaseCount('remote_repositories', 2);
    $this->assertDatabaseHas('remote_repositories', ['external_id' => 'github:124', 'archived' => true]);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    expect($source->fresh()->last_synced_at->equalTo(now()))->toBeTrue();
    expect($source->fresh()->sync_checkpoint)->toBeNull();
    $this->assertDatabaseEmpty('jobs');
});

test('a later sync updates the external identity after a rename and keeps repositories missing upstream', function () {
    $source = queuedGitSource();
    RemoteRepository::factory()->create(['external_id' => 'github:123', 'git_source_id' => $source->id, 'name' => 'old-name', 'stars_count' => 3]);
    $missing = RemoteRepository::factory()->create(['git_source_id' => $source->id, 'name' => 'missing']);
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository()),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([]),
        'https://api.github.com/repos/laravel/framework/commits?*' => Http::response(['message' => 'Git Repository is empty.'], 409),
    ]);

    runSyncReservations(4);

    $this->assertDatabaseCount('remote_repositories', 2);
    $this->assertDatabaseHas('remote_repositories', ['external_id' => 'github:123', 'name' => 'framework', 'stars_count' => 35000, 'last_committed_at' => null]);
    $this->assertModelExists($missing);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    Http::assertSentCount(4);
});

test('empty pages finish successfully while pagination continues to a later provider page', function () {
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::sequence()
            ->push([], 200, ['Link' => '<https://api.github.com/orgs/laravel/repos?page=2>; rel="next"'])
            ->push([]),
    ]);

    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Syncing);
    expect($source->fresh()->sync_checkpoint['page'])->toBe(2);

    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    $this->assertDatabaseEmpty('remote_repositories');
    Http::assertSentCount(2);
});

test('rate limits persist their retry time without consuming transient failures or losing completed data', function () {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    $source = queuedGitSource(['last_synced_at' => '2026-10-01 12:00:00']);
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::sequence()
        ->push([], 429, ['Retry-After' => '120'])
        ->push([])]);

    runSyncReservations();

    $source->refresh();
    expect($source->sync_status)->toBe(SyncStatus::Waiting);
    expect($source->sync_retry_at->toIso8601String())->toBe('2026-10-02T12:02:00+00:00');
    expect($source->last_sync_error_code)->toBe('errors.rateLimited');
    expect($source->last_synced_at->toDateTimeString())->toBe('2026-10-01 12:00:00');
    expect($source->sync_checkpoint['transient_failures'])->toBe(0);

    runSyncReservations();
    Http::assertSentCount(1);
    $this->travel(120)->seconds();
    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    expect($source->fresh()->last_sync_error_code)->toBeNull();
    Http::assertSentCount(2);
});

test('three consecutive transient failures stop the run with a safe error and preserve the previous successful timestamp', function () {
    $this->freezeTime();
    $source = queuedGitSource(['last_synced_at' => '2026-10-01 12:00:00']);
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response(['message' => 'secret upstream body'], 503)]);

    runSyncReservations();
    $this->travel(10)->seconds();
    runSyncReservations();
    $this->travel(60)->seconds();
    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Failed);
    expect($source->fresh()->last_sync_error_code)->toBe('errors.providerUnavailable');
    expect($source->fresh()->sync_checkpoint['transient_failures'])->toBe(3);
    expect($source->fresh()->last_synced_at->toDateTimeString())->toBe('2026-10-01 12:00:00');
    $this->assertDatabaseEmpty('jobs');
    Http::assertSentCount(3);
});

test('manual retry resumes the failed detail with a new run token and clears the old error only on completion', function () {
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::sequence()->push([], 401)->push(GitHubPayload::repository()),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([]),
        'https://api.github.com/repos/laravel/framework/commits?*' => Http::response(syncCommitPayload()),
    ]);
    runSyncReservations(2);
    $oldRun = $source->sync_run_id;

    $resumed = app(GitSourceSyncService::class)->start($source);

    expect($resumed->sync_run_id)->not->toBe($oldRun);
    expect($resumed->sync_checkpoint['phase'])->toBe('details');
    expect($resumed->sync_checkpoint['job_attempt'])->toBe(0);
    expect($resumed->last_sync_error_code)->toBe('errors.providerAuthenticationFailed');
    runSyncReservations();
    expect($source->fresh()->last_sync_error_code)->toBe('errors.providerAuthenticationFailed');
    runSyncReservations(2);

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    expect($source->fresh()->last_sync_error_code)->toBeNull();
    Http::assertSentCount(5);
});

test('a removed private or transferred repository is skipped without deleting its previously saved version', function (array $detail, int $status, int $reservations) {
    $source = queuedGitSource();
    $existing = RemoteRepository::factory()->create(['external_id' => 'github:123', 'git_source_id' => $source->id, 'name' => 'framework', 'stars_count' => 5]);
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response($detail, $status),
    ]);

    runSyncReservations($reservations);

    $this->assertModelExists($existing);
    expect($existing->fresh()->stars_count)->toBe(5);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    Http::assertSentCount($reservations);
})->with([
    'deleted' => [[], 404, 3],
    'renamed' => [[], 301, 3],
    'private' => [GitHubPayload::repository(['private' => true]), 200, 2],
    'transferred' => [GitHubPayload::repository(['owner' => ['login' => 'other', 'id' => 99]]), 200, 2],
]);

test('a source disappearing after discovery fails instead of reporting all missing repositories as successful', function () {
    $source = queuedGitSource(['last_synced_at' => '2026-10-01 12:00:00']);
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::sequence()->push([GitHubPayload::repository()])->push([], 404),
        'https://api.github.com/repos/laravel/framework' => Http::response([], 404),
    ]);

    runSyncReservations(2);

    expect($source->fresh()->sync_checkpoint['phase'])->toBe('verify_source');
    Http::assertSentCount(2);
    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Failed);
    expect($source->fresh()->last_sync_error_code)->toBe('errors.sourceNotFound');
    expect($source->fresh()->last_synced_at->toDateTimeString())->toBe('2026-10-01 12:00:00');
    $this->assertDatabaseEmpty('remote_repositories');
    Http::assertSentCount(3);
});

test('a source page 404 fails the run instead of treating the account as empty', function () {
    $source = queuedGitSource();
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response([], 404)]);

    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Failed);
    expect($source->fresh()->last_sync_error_code)->toBe('errors.sourceNotFound');
    expect($source->fresh()->last_synced_at)->toBeNull();
    Http::assertSentCount(1);
});

test('a stale queued payload and its failure callback cannot change a new run', function () {
    $source = queuedGitSource();
    $oldRun = $source->sync_run_id;
    $source->update(['sync_status' => SyncStatus::Failed]);
    $resumed = app(GitSourceSyncService::class)->start($source);

    runSyncReservations();
    (new SyncGitSource($source->id, $oldRun))->failed(new RuntimeException('old failure'));

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Queued);
    expect($source->fresh()->sync_run_id)->toBe($resumed->sync_run_id);
    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
});

test('a competing checkpoint revision rejects an older HTTP result', function () {
    $source = queuedGitSource();
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => function () use ($source) {
        GitSource::query()->whereKey($source->id)->increment('sync_revision');

        return Http::response([GitHubPayload::repository()]);
    }]);

    $result = app(GitSourceSyncService::class)->advance($source->id, $source->sync_run_id, 1);

    expect($result)->toBe(['delay' => null, 'error' => null]);
    expect($source->fresh()->sync_checkpoint['phase'])->toBe('page');
    $this->assertDatabaseEmpty('remote_repositories');
    Http::assertSentCount(1);
});

test('a competing repository owner version triggers fresh metadata instead of reclaiming a transfer', function () {
    $source = queuedGitSource(['last_sync_error_code' => 'errors.rateLimited']);
    $other = GitSource::factory()->create();
    $repository = RemoteRepository::factory()->create(['external_id' => 'github:123', 'git_source_id' => $source->id, 'name' => 'framework']);
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::sequence()->push(GitHubPayload::repository())
            ->push(GitHubPayload::repository(['owner' => ['login' => 'other', 'id' => (int) $other->remote_id]])),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([]),
        'https://api.github.com/repos/laravel/framework/commits?*' => function () use ($repository, $other) {
            $repository->update(['git_source_id' => $other->id, 'sync_version' => 1]);

            return Http::response(syncCommitPayload());
        },
    ]);

    runSyncReservations(4);

    expect($repository->fresh()->git_source_id)->toBe($other->id);
    expect($source->fresh()->sync_checkpoint['phase'])->toBe('details');
    expect($source->fresh()->last_synced_at)->toBeNull();
    expect($source->fresh()->last_sync_error_code)->toBe('errors.rateLimited');
    runSyncReservations();

    expect($repository->fresh()->git_source_id)->toBe($other->id);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    Http::assertSentCount(5);
});

test('repository persistence and checkpoint advancement roll back together when a write fails', function () {
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository()),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([]),
        'https://api.github.com/repos/laravel/framework/commits?*' => Http::response(syncCommitPayload()),
    ]);
    runSyncReservations(3);
    RemoteRepository::created(function () {
        throw new RuntimeException('Private persistence details');
    });

    try {
        runSyncReservations();
    } finally {
        RemoteRepository::flushEventListeners();
    }

    $this->assertDatabaseEmpty('remote_repositories');
    expect($source->fresh()->sync_checkpoint['phase'])->toBe('commit');
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Failed);
    expect($source->fresh()->last_sync_error_code)->toBe('errors.unexpected');
    Http::assertSentCount(4);
});

test('a worker crash after a committed step resumes the next step instead of replaying its HTTP request', function () {
    $this->freezeTime();
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository()),
    ]);
    $reserved = Queue::connection('database')->pop();
    app(GitSourceSyncService::class)->advance($source->id, $source->sync_run_id, 1);

    $this->travel(91)->seconds();
    runSyncReservations();

    expect($reserved)->not->toBeNull();
    expect($source->fresh()->sync_checkpoint['phase'])->toBe('pull_requests');
    Http::assertSentCount(2);
});

test('an overlap lock delays another worker without outbound HTTP', function () {
    $source = queuedGitSource();
    $middleware = (new SyncGitSource($source->id, $source->sync_run_id))->middleware()[0];
    $lock = Cache::lock($middleware->getLockKey(new SyncGitSource($source->id, $source->sync_run_id)), 75);
    $lock->get();

    try {
        runSyncReservations();
    } finally {
        $lock->release();
    }

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Queued);
    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
});

test('unknown failure logging contains safe context without raw messages or credential-bearing causes', function () {
    $source = queuedGitSource();
    Log::spy();

    (new SyncGitSource($source->id, $source->sync_run_id))->failed(new RuntimeException('Authorization: Bearer private-token'));

    expect($source->fresh()->last_sync_error_code)->toBe('errors.unexpected');
    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($source) {
        expect($context['git_source_id'])->toBe($source->id);
        expect(json_encode([$message, $context]))->not->toContain('private-token');

        return true;
    });
    Http::assertNothingSent();
});

test('the pull request last page supplies a separate open count that is subtracted from GitHub issues', function () {
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository()),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([['id' => 1, 'state' => 'open']], 200, [
            'Link' => '<https://api.github.com/repos/laravel/framework/pulls?state=open&per_page=1&page=7>; rel="last"',
        ]),
        'https://api.github.com/repos/laravel/framework/commits?*' => Http::response(syncCommitPayload()),
    ]);

    runSyncReservations(4);

    $this->assertDatabaseHas('remote_repositories', ['external_id' => 'github:123', 'issues_count' => 5, 'pull_requests_count' => 7]);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    Http::assertSentCount(4);
});

test('missing pull request totals fall back to durable hundred-row pages without counting the probe twice', function () {
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository(['open_issues_count' => 105])),
        'https://api.github.com/repos/laravel/framework/pulls?state=open&per_page=1&page=1' => Http::response([['id' => 1, 'state' => 'open']], 200, [
            'Link' => '<https://api.github.com/repos/laravel/framework/pulls?state=open&per_page=1&page=2>; rel="next"',
        ]),
        'https://api.github.com/repos/laravel/framework/pulls?state=open&per_page=100&page=1' => Http::response(array_map(
            fn (int $id): array => ['id' => $id, 'state' => 'open'], range(1, 100),
        ), 200, [
            'Link' => '<https://api.github.com/repos/laravel/framework/pulls?state=open&per_page=100&page=2>; rel="next"',
        ]),
        'https://api.github.com/repos/laravel/framework/pulls?state=open&per_page=100&page=2' => Http::response([
            ['id' => 101, 'state' => 'open'], ['id' => 102, 'state' => 'open'],
        ]),
        'https://api.github.com/repos/laravel/framework/commits?*' => Http::response(syncCommitPayload()),
    ]);

    runSyncReservations(4);

    expect($source->fresh()->sync_checkpoint['pulls_count'])->toBe(100);
    expect($source->fresh()->sync_checkpoint['pulls_page'])->toBe(2);
    $this->assertDatabaseEmpty('remote_repositories');
    Http::assertSentCount(4);
    runSyncReservations(2);

    $this->assertDatabaseHas('remote_repositories', ['external_id' => 'github:123', 'issues_count' => 3, 'pull_requests_count' => 102]);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    Http::assertSentCount(6);
});

test('negative issue differences refresh both metadata and pull counts before publishing', function () {
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::sequence()
            ->push(GitHubPayload::repository(['open_issues_count' => 0]))
            ->push(GitHubPayload::repository(['open_issues_count' => 3])),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([['id' => 1, 'state' => 'open']]),
        'https://api.github.com/repos/laravel/framework/commits?*' => Http::response(syncCommitPayload()),
    ]);

    runSyncReservations(3);

    expect($source->fresh()->sync_checkpoint['phase'])->toBe('details');
    $this->assertDatabaseEmpty('remote_repositories');
    runSyncReservations(3);

    $this->assertDatabaseHas('remote_repositories', ['external_id' => 'github:123', 'issues_count' => 2, 'pull_requests_count' => 1]);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    Http::assertSentCount(6);
});

test('three inconsistent counter samples fail instead of saving negative issues', function () {
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository(['open_issues_count' => 0])),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([['id' => 1, 'state' => 'open']]),
    ]);

    runSyncReservations(7);

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Failed);
    expect($source->fresh()->last_sync_error_code)->toBe('errors.providerInvalidResponse');
    $this->assertDatabaseEmpty('remote_repositories');
    $this->assertDatabaseEmpty('jobs');
    Http::assertSentCount(7);
});

test('a successful step resets the transient budget while retaining the last error until full success', function () {
    $this->freezeTime();
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::sequence()->push([], 503)->push([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::sequence()->push([], 503)->push([], 503),
    ]);

    runSyncReservations();
    $this->travel(10)->seconds();
    runSyncReservations();

    expect($source->fresh()->sync_checkpoint['transient_failures'])->toBe(0);
    expect($source->fresh()->last_sync_error_code)->toBe('errors.providerUnavailable');
    runSyncReservations();
    $this->travel(10)->seconds();
    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Waiting);
    expect($source->fresh()->sync_checkpoint['transient_failures'])->toBe(2);
    Http::assertSentCount(4);
});

test('a rate limit without a usable timestamp waits sixty seconds and ignores premature delivery', function () {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    $source = queuedGitSource();
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response([], 429)]);
    runSyncReservations();

    $result = app(GitSourceSyncService::class)->advance($source->id, $source->sync_run_id, 2);

    expect($result)->toBe(['delay' => 60, 'error' => null]);
    expect($source->fresh()->sync_retry_at->toDateTimeString())->toBe('2026-10-02 12:01:00');
    expect($source->fresh()->sync_checkpoint['transient_failures'])->toBe(0);
    Http::assertSentCount(1);
});

test('a finished run ignores duplicate queue deliveries and delayed failure callbacks', function () {
    $source = queuedGitSource();
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response([])]);
    runSyncReservations();
    $lastSyncedAt = $source->fresh()->last_synced_at;
    Queue::connection('database')->push(new SyncGitSource($source->id, $source->sync_run_id));

    runSyncReservations();
    (new SyncGitSource($source->id, $source->sync_run_id))->failed(new RuntimeException('Late worker failure.'));

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Succeeded);
    expect($source->fresh()->last_synced_at->equalTo($lastSyncedAt))->toBeTrue();
    expect($source->fresh()->last_sync_error_code)->toBeNull();
    $this->assertDatabaseEmpty('jobs');
    Http::assertSentCount(1);
});

test('an older reservation cannot advance or fail a newer reservation within the same run', function () {
    $this->freezeTime();
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository()),
    ]);
    $older = Queue::connection('database')->pop();
    $service = app(GitSourceSyncService::class);
    $service->advance($source->id, $source->sync_run_id, $older->attempts());
    $this->travel(91)->seconds();
    runSyncReservations();

    $result = $service->advance($source->id, $source->sync_run_id, $older->attempts());
    $older->fail(new RuntimeException('Delayed failure from the first reservation.'));

    expect($result)->toBe(['delay' => null, 'error' => null]);
    expect($source->fresh()->sync_status)->toBe(SyncStatus::Syncing);
    expect($source->fresh()->sync_checkpoint['phase'])->toBe('pull_requests');
    expect($source->fresh()->sync_checkpoint['job_attempt'])->toBe(2);
    expect($source->fresh()->last_sync_error_code)->toBeNull();
    $this->assertDatabaseCount('jobs', 1);
    Http::assertSentCount(2);
});

test('a current reservation failure still marks its active source failed', function () {
    $source = queuedGitSource(['last_synced_at' => '2026-10-01 12:00:00']);
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()])]);
    $current = Queue::connection('database')->pop();
    app(GitSourceSyncService::class)->advance($source->id, $source->sync_run_id, $current->attempts());

    $current->fail(new RuntimeException('Current reservation stopped.'));

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Failed);
    expect($source->fresh()->sync_checkpoint['job_attempt'])->toBe(1);
    expect($source->fresh()->last_sync_error_code)->toBe('errors.unexpected');
    expect($source->fresh()->last_synced_at->toDateTimeString())->toBe('2026-10-01 12:00:00');
    $this->assertDatabaseEmpty('jobs');
    Http::assertSentCount(1);
});

test('the crash limit failing a newer reservation before its handler starts still marks the source failed', function () {
    $this->freezeTime();
    $source = queuedGitSource();
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()])]);
    $crashed = Queue::connection('database')->pop();
    app(GitSourceSyncService::class)->advance($source->id, $source->sync_run_id, $crashed->attempts());
    // Simulate the framework markers left behind when a process dies before its finally block.
    Cache::put('job-processing:'.$crashed->uuid(), true, now()->addDay());
    Cache::put('job-exceptions:'.$crashed->uuid(), 2, now()->addDay());
    $this->travel(91)->seconds();

    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Failed);
    expect($source->fresh()->sync_checkpoint['phase'])->toBe('details');
    expect($source->fresh()->sync_checkpoint['job_attempt'])->toBe(2);
    expect($source->fresh()->last_sync_error_code)->toBe('errors.unexpected');
    $this->assertDatabaseEmpty('jobs');
    Http::assertSentCount(1);
});

test('an in-flight repository result cannot write after deletion is marked or completed', function (bool $cleanupCompleted) {
    $source = queuedGitSource();
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository()),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([]),
        'https://api.github.com/repos/laravel/framework/commits?*' => function () use ($source, $cleanupCompleted) {
            app(GitSourceService::class)->markForDeletion($source);

            if ($cleanupCompleted) {
                (new DeleteGitSource($source->id))->handle();
            }

            return Http::response(syncCommitPayload());
        },
    ]);

    runSyncReservations(4);
    (new SyncGitSource($source->id, $source->sync_run_id))->failed(new RuntimeException('Late failure after cancellation.'));

    $this->assertDatabaseEmpty('remote_repositories');
    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseEmpty('failed_jobs');
    if ($cleanupCompleted) {
        $this->assertModelMissing($source);
    } else {
        expect($source->fresh()->marked_for_deletion_at)->not->toBeNull();
        expect($source->fresh()->sync_status)->toBe(SyncStatus::Idle);
        expect($source->fresh()->last_synced_at)->toBeNull();
        expect($source->fresh()->last_sync_error_code)->toBeNull();
    }
    Http::assertSentCount(4);
})->with(['marked' => false, 'removed' => true]);

test('an in-flight provider error after deletion cannot schedule a retry or mark the source failed', function () {
    $source = queuedGitSource();
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => function () use ($source) {
        app(GitSourceService::class)->markForDeletion($source);

        return Http::response([], 503);
    }]);

    runSyncReservations();

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Idle);
    expect($source->fresh()->sync_retry_at)->toBeNull();
    expect($source->fresh()->last_sync_error_code)->toBeNull();
    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseEmpty('failed_jobs');
    Http::assertSentCount(1);
});
