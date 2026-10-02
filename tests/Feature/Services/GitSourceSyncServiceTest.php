<?php

use App\Enums\SyncStatus;
use App\Jobs\SyncGitSource;
use App\Models\GitSource;
use App\Services\GitSourceService;
use App\Services\GitSourceSyncService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.github.pat' => null]);
});

test('starting synchronization atomically stores a database job without HTTP even when the default queue is synchronous', function () {
    $source = GitSource::factory()->create();
    config(['services.github.pat' => 'server-only-test-token']);

    $started = app(GitSourceSyncService::class)->start($source);

    expect($started->sync_status)->toBe(SyncStatus::Queued);
    expect($started->sync_run_id)->toBeUuid();
    expect($started->last_synced_at)->toBeNull();
    $this->assertDatabaseCount('jobs', 1);
    $payload = DB::table('jobs')->sole()->payload;
    expect(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['displayName'])->toBe(SyncGitSource::class);
    expect($payload)->not->toContain('server-only-test-token');
    Http::assertNothingSent();
});

test('repeated requests while a source is active do not enqueue duplicates or reset progress', function (SyncStatus $status) {
    $source = GitSource::factory()->create();
    $service = app(GitSourceSyncService::class);
    $started = $service->start($source);
    $started->update(['sync_status' => $status]);
    $revision = $started->sync_revision;

    $again = $service->start($source);

    expect($again->sync_status)->toBe($status);
    expect($again->sync_run_id)->toBe($started->sync_run_id);
    expect($again->sync_revision)->toBe($revision);
    expect($again->sync_checkpoint)->toBe($started->sync_checkpoint);
    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
})->with([SyncStatus::Queued, SyncStatus::Syncing, SyncStatus::Waiting]);

test('an enqueue failure rolls back the source state and preserves its previous error', function () {
    $source = GitSource::factory()->create([
        'sync_status' => SyncStatus::Failed,
        'last_sync_error_code' => 'errors.providerUnavailable',
    ]);
    DB::connection()->beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'insert into "jobs"')) {
            throw new RuntimeException('Queue insert failed.');
        }
    });

    expect(fn () => app(GitSourceSyncService::class)->start($source))->toThrow(RuntimeException::class);

    $source->refresh();
    expect($source->sync_status)->toBe(SyncStatus::Failed);
    expect($source->last_sync_error_code)->toBe('errors.providerUnavailable');
    expect($source->sync_run_id)->toBeNull();
    $this->assertDatabaseEmpty('jobs');
    Http::assertNothingSent();
});

test('rolling back source creation also rolls back its queued sync', function () {
    expect(fn () => DB::transaction(function () {
        $source = GitSource::factory()->create();
        app(GitSourceSyncService::class)->start($source);

        throw new RuntimeException('Creation was rolled back.');
    }))->toThrow(RuntimeException::class);

    $this->assertDatabaseEmpty('git_sources');
    $this->assertDatabaseEmpty('jobs');
    Http::assertNothingSent();
});

test('queue fakes observe the source ID and run token without serializing source metadata', function () {
    $source = GitSource::factory()->create();
    Queue::fake([SyncGitSource::class]);

    $started = app(GitSourceSyncService::class)->start($source);

    Queue::assertPushed(SyncGitSource::class, fn (SyncGitSource $job) => $job->sourceId === $source->id && $job->runId === $started->sync_run_id);
    $this->assertDatabaseEmpty('jobs');
    Http::assertNothingSent();
});

test('a stale source model cannot restart synchronization after deletion was marked', function () {
    $source = GitSource::factory()->create();
    app(GitSourceService::class)->markForDeletion($source);

    expect(fn () => app(GitSourceSyncService::class)->start($source))->toThrow(ModelNotFoundException::class);

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Idle);
    expect($source->fresh()->sync_run_id)->toBeNull();
    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
});
