<?php

use App\Enums\SyncStatus;
use App\Jobs\DeleteGitSource;
use App\Models\GitSource;
use App\Models\RemoteRepository;
use App\Services\GitSourceSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

test('deletion returns 202 and atomically queues cleanup while retaining source and repositories', function () {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    $source = app(GitSourceSyncService::class)->start(GitSource::factory()->create());
    $repository = RemoteRepository::factory()->for($source, 'owner')->create();

    $response = $this->deleteJson(route('git-sources.destroy', $source));

    $response->assertAccepted()->assertJsonPath('data.id', (string) $source->id)
        ->assertJsonPath('data.marked_for_deletion_at', 1790942400)
        ->assertJsonPath('data.sync_status', 'idle');
    $this->assertModelExists($repository);
    $this->assertDatabaseHas('git_sources', [
        'id' => $source->id, 'marked_for_deletion_at' => '2026-10-02 12:00:00',
        'sync_run_id' => null, 'sync_checkpoint' => null, 'sync_retry_at' => null,
    ]);
    $this->assertDatabaseCount('jobs', 2);
    $payload = json_decode(DB::table('jobs')->orderByDesc('id')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['displayName'])->toBe(DeleteGitSource::class);
    Http::assertNothingSent();
});

test('repeating deletion keeps its original marker and queues only one cleanup job', function () {
    $this->freezeTime();
    $source = GitSource::factory()->create();
    $this->deleteJson(route('git-sources.destroy', $source))->assertAccepted();
    $markedAt = $source->fresh()->marked_for_deletion_at->getTimestamp();
    $revision = $source->fresh()->sync_revision;
    $this->travel(10)->seconds();

    $this->deleteJson(route('git-sources.destroy', $source))->assertAccepted()
        ->assertJsonPath('data.marked_for_deletion_at', $markedAt)
        ->assertJsonPath('data.sync_revision', $revision);

    $this->assertDatabaseCount('jobs', 1);
});

test('sources marked for deletion disappear from ordinary and filtered source lists', function () {
    $source = GitSource::factory()->create(['name' => 'Rostás András']);
    $remaining = GitSource::factory()->create(['name' => 'Remaining source']);
    $this->deleteJson(route('git-sources.destroy', $source))->assertAccepted();

    $this->getJson(route('git-sources.index'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $remaining->id)->assertJsonPath('meta.total', 1);
    $this->getJson(route('git-sources.index', ['search' => 'Rostás']))->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
});

test('marked sources return 404 at detail endpoints and cannot enqueue another sync', function (string $route, string $method) {
    $source = GitSource::factory()->create();
    $this->deleteJson(route('git-sources.destroy', $source))->assertAccepted();

    $this->{$method}(route($route, $source))->assertNotFound();

    $this->assertDatabaseCount('jobs', 1);
    Http::assertNothingSent();
})->with([
    'profile' => ['git-sources.show', 'getJson'],
    'repositories' => ['git-sources.repositories.index', 'getJson'],
    'synchronization' => ['git-sources.sync', 'postJson'],
]);

test('an enqueue failure returns 500 and rolls back deletion and sync cancellation', function () {
    $source = app(GitSourceSyncService::class)->start(GitSource::factory()->create());
    DB::connection()->beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'insert into "jobs"')) {
            throw new RuntimeException('Private cleanup queue failure.');
        }
    });

    $this->deleteJson(route('git-sources.destroy', $source))->assertInternalServerError()
        ->assertExactJson(['code' => 'errors.unexpected']);

    $this->assertDatabaseHas('git_sources', [
        'id' => $source->id, 'marked_for_deletion_at' => null,
        'sync_status' => SyncStatus::Queued->value, 'sync_run_id' => $source->sync_run_id,
        'sync_revision' => $source->sync_revision,
    ]);
    $this->assertDatabaseCount('jobs', 1);
});

test('source deletion retains CSRF protection and returns 419 without a token', function () {
    $source = GitSource::factory()->create();
    $this->app->instance('env', 'local');

    $this->deleteJson(route('git-sources.destroy', $source))->assertStatus(419);

    $this->assertDatabaseHas('git_sources', ['id' => $source->id, 'marked_for_deletion_at' => null]);
    $this->assertDatabaseEmpty('jobs');
});

test('deleting a nonexistent source returns 404 without queuing cleanup', function () {
    $this->deleteJson(route('git-sources.destroy', ['gitSource' => 999999]))->assertNotFound();

    $this->assertDatabaseEmpty('jobs');
});
