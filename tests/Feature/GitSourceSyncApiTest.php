<?php

use App\Enums\SyncStatus;
use App\Models\GitSource;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Git\GitHubPayload;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.github.pat' => null]);
});

test('source details serialize safe synchronization state without checkpoint or credentials', function () {
    config(['services.github.pat' => 'test-secret-token']);
    $source = GitSource::factory()->create([
        'sync_status' => SyncStatus::Waiting,
        'last_synced_at' => '2026-10-02 12:30:00',
        'last_sync_error_code' => 'errors.rateLimited',
        'last_sync_error_at' => '2026-10-02 12:30:00',
        'sync_retry_at' => '2026-10-02 12:31:00',
        'sync_revision' => 6,
        'sync_run_id' => 'internal-run',
        'sync_checkpoint' => ['internal' => 'private-checkpoint'],
    ]);

    $response = $this->getJson(route('git-sources.show', $source));

    $response->assertOk()->assertJsonPath('data.sync_status', 'waiting')
        ->assertJsonPath('data.last_synced_at', 1790944200)
        ->assertJsonPath('data.last_sync_error_code', 'errors.rateLimited')
        ->assertJsonPath('data.last_sync_error_at', 1790944200)
        ->assertJsonPath('data.sync_retry_at', 1790944260)
        ->assertJsonPath('data.sync_revision', 6)
        ->assertJsonMissingPath('data.sync_run_id')->assertJsonMissingPath('data.sync_checkpoint');
    expect($response->getContent())->not->toContain('test-secret-token', 'private-checkpoint', 'internal-run');
    Http::assertNothingSent();
});

test('manual synchronization queues one job and repeated active requests do not duplicate it', function () {
    config(['services.github.pat' => 'test-secret-token']);
    $source = GitSource::factory()->create(['last_synced_at' => '2026-10-02 12:30:00']);

    $this->postJson(route('git-sources.sync', $source))->assertAccepted()
        ->assertJsonPath('data.sync_status', 'queued')->assertJsonPath('data.last_synced_at', 1790944200);
    $runId = $source->fresh()->sync_run_id;
    $this->postJson(route('git-sources.sync', $source))->assertAccepted()->assertJsonPath('data.sync_status', 'queued');

    $this->assertDatabaseCount('jobs', 1);
    expect($source->fresh()->sync_run_id)->toBe($runId);
    expect(DB::table('jobs')->value('payload'))->not->toContain('test-secret-token');
    Http::assertNothingSent();
});

test('an enqueue failure rolls back the source synchronization state', function () {
    $source = GitSource::factory()->create();
    DB::connection()->beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'insert into "jobs"')) {
            throw new RuntimeException('Private queue failure');
        }
    });

    $this->postJson(route('git-sources.sync', $source))->assertInternalServerError()
        ->assertExactJson(['code' => 'errors.unexpected']);

    expect($source->fresh()->sync_status)->toBe(SyncStatus::Idle);
    expect($source->fresh()->sync_run_id)->toBeNull();
    $this->assertDatabaseEmpty('jobs');
});

test('source creation and its initial enqueue are atomic', function () {
    Http::fake(['https://api.github.com/users/laravel' => Http::response(GitHubPayload::account())]);
    DB::connection()->beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'insert into "jobs"')) {
            throw new RuntimeException('Private queue failure');
        }
    });

    $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel'])
        ->assertInternalServerError()->assertExactJson(['code' => 'errors.unexpected']);

    $this->assertDatabaseEmpty('git_sources');
    $this->assertDatabaseEmpty('jobs');
});

test('manual synchronization retains CSRF protection', function () {
    $source = GitSource::factory()->create();
    $this->app->instance('env', 'local');

    $this->postJson(route('git-sources.sync', $source))->assertStatus(419);

    $this->assertDatabaseEmpty('jobs');
    Http::assertNothingSent();
});

test('a nonexistent source returns 404 at every detail endpoint', function (string $route, string $method) {
    $this->{$method}(route($route, ['gitSource' => 999999]))->assertNotFound();
    Http::assertNothingSent();
})->with([
    'profile' => ['git-sources.show', 'getJson'],
    'repositories' => ['git-sources.repositories.index', 'getJson'],
    'synchronization' => ['git-sources.sync', 'postJson'],
]);

test('invalid PATs and denied access return translated provider errors without an anonymous fallback', function (int $status, string $code) {
    config(['services.github.pat' => 'test-secret-token']);
    Http::fake(['https://api.github.com/users/laravel' => Http::response(['message' => 'Sensitive provider details'], $status)]);

    $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);

    $response->assertStatus(502)->assertExactJson(['code' => $code]);
    expect($response->getContent())->not->toContain('test-secret-token', 'Sensitive provider details');
    Http::assertSentCount(1);
    $this->assertDatabaseEmpty('git_sources');
    $this->assertDatabaseEmpty('jobs');
})->with([
    'authentication' => [401, 'errors.providerAuthenticationFailed'],
    'access denied' => [403, 'errors.providerAccessDenied'],
]);
