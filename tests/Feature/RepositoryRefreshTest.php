<?php

use App\Enums\SyncStatus;
use App\Models\GitSource;
use App\Models\RemoteRepository;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.github.pat' => null]);
});

test('source details omit repository snapshots and queries unless a page is requested', function () {
    $source = GitSource::factory()->create();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_contains($query->sql, '"remote_repositories"')) {
            $queries[] = $query->sql;
        }
    });

    $response = $this->getJson(route('git-sources.show', $source));

    $response->assertOk()->assertJsonMissingPath('repositories');
    expect($queries)->toBe([]);
    Http::assertNothingSent();
});

test('the source snapshot matches the displayed repository page without loading other pages', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->count(31)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('repository-%02d', $sequence->index)])
        ->create();
    $page = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => 2]))->assertOk();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_contains($query->sql, '"remote_repositories"')) {
            $queries[] = $query->sql;
        }
    });

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 2]));

    $response->assertOk()->assertJsonPath('repositories', [
        'fingerprint' => $page->json('fingerprint'),
        'meta' => ['current_page' => 2, 'last_page' => 4, 'per_page' => 10, 'total' => 31],
    ])->assertJsonMissingPath('repositories.data');
    expect($page->json('fingerprint'))->toBeString()->not->toBeEmpty();
    expect($queries)->toHaveCount(2);
    expect($queries[0])->toContain('count(*)');
    expect($queries[1])->toContain('limit 10 offset 10');
    Http::assertNothingSent();
});

test('editing a repository on another page leaves the visible page fingerprint unchanged', function () {
    $source = GitSource::factory()->create();
    $repositories = RemoteRepository::factory()->count(11)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('repository-%02d', $sequence->index)])
        ->create();
    $page = $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $repositories->last()->update(['stars_count' => 42]);

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 1]));

    $response->assertOk()->assertJsonPath('repositories.fingerprint', $page->json('fingerprint'))
        ->assertJsonPath('repositories.meta.total', 11);
    Http::assertNothingSent();
});

test('adding a repository after the visible page changes only pagination metadata', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->count(10)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('repository-%02d', $sequence->index)])
        ->create();
    $page = $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'zzz-new-repository']);

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 1]));

    $response->assertOk()->assertJsonPath('repositories.fingerprint', $page->json('fingerprint'))
        ->assertJsonPath('repositories.meta', [
            'current_page' => 1, 'last_page' => 2, 'per_page' => 10, 'total' => 11,
        ]);
    Http::assertNothingSent();
});

test('changes to visible repository fields change the page fingerprint', function (string $field, mixed $value) {
    $source = GitSource::factory()->create();
    $repository = RemoteRepository::factory()->for($source, 'owner')->create([
        'name' => 'original', 'description' => 'Original description',
    ]);
    $page = $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $repository->update([$field => $value]);

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 1]));

    $response->assertOk();
    expect($response->json('repositories.fingerprint'))->not->toBe($page->json('fingerprint'));
    Http::assertNothingSent();
})->with([
    'name' => ['name', 'renamed'],
    'description' => ['description', 'Changed description'],
    'stars' => ['stars_count', 42],
    'issues' => ['issues_count', 3],
    'pull requests' => ['pull_requests_count', 4],
    'forks' => ['forks_count', 5],
    'language' => ['language', 'PHP'],
    'archived' => ['archived', true],
    'last commit' => ['last_committed_at', '2026-10-02 12:30:00'],
]);

test('changing the source account changes fingerprints for its derived repository URLs', function () {
    $source = GitSource::factory()->create(['account' => 'original-account']);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'repository']);
    $page = $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $source->update(['account' => 'renamed-account']);

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 1]));

    $response->assertOk();
    expect($response->json('repositories.fingerprint'))->not->toBe($page->json('fingerprint'));
    Http::assertNothingSent();
});

test('internal sync versions and timestamps do not invalidate unchanged visible repositories', function () {
    $source = GitSource::factory()->create(['sync_revision' => 1]);
    $repository = RemoteRepository::factory()->for($source, 'owner')->create([
        'sync_version' => 1, 'updated_at' => '2026-10-01 12:00:00',
    ]);
    $page = $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $source->update(['sync_revision' => 9, 'sync_status' => SyncStatus::Succeeded, 'last_synced_at' => '2026-10-02 12:00:00']);
    $repository->update(['sync_version' => 2, 'updated_at' => '2026-10-02 12:00:00']);

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 1]));

    $response->assertOk()->assertJsonPath('repositories.fingerprint', $page->json('fingerprint'))
        ->assertJsonPath('data.sync_revision', 9)->assertJsonPath('data.sync_status', 'succeeded');
    Http::assertNothingSent();
});

test('inserting before the visible page invalidates its shifted membership', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->count(20)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('repository-%02d', $sequence->index)])
        ->create();
    $page = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => 2]))->assertOk();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'aaa-first']);

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 2]));

    $response->assertOk()->assertJsonPath('repositories.meta.total', 21);
    expect($response->json('repositories.fingerprint'))->not->toBe($page->json('fingerprint'));
    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => 2]))
        ->assertOk()->assertJsonPath('data.0.name', 'repository-09')
        ->assertJsonPath('fingerprint', $response->json('repositories.fingerprint'));
    Http::assertNothingSent();
});

test('renaming an off-page repository into the visible page invalidates its membership', function () {
    $source = GitSource::factory()->create();
    $repositories = RemoteRepository::factory()->count(11)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('repository-%02d', $sequence->index)])
        ->create();
    $page = $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $repositories->last()->update(['name' => 'aaa-first']);

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 1]));

    $response->assertOk()->assertJsonPath('repositories.meta.total', 11);
    expect($response->json('repositories.fingerprint'))->not->toBe($page->json('fingerprint'));
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()
        ->assertJsonPath('data.0.external_id', $repositories->last()->external_id);
    Http::assertNothingSent();
});

test('removing the final page clamps the snapshot to the remaining page', function () {
    $source = GitSource::factory()->create();
    $repositories = RemoteRepository::factory()->count(11)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('repository-%02d', $sequence->index)])
        ->create();
    $page = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => 2]))->assertOk();
    $repositories->last()->delete();

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 2]));

    $response->assertOk()->assertJsonPath('repositories.meta', [
        'current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 10,
    ]);
    expect($response->json('repositories.fingerprint'))->not->toBe($page->json('fingerprint'));
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()
        ->assertJsonPath('fingerprint', $response->json('repositories.fingerprint'));
    Http::assertNothingSent();
});

test('snapshot page parameters are normalized consistently with repository pagination', function (mixed $page, int $expectedPage) {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->count(11)->for($source, 'owner')->create();
    $repositories = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => $expectedPage]))->assertOk();

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => $page]));

    $response->assertOk()->assertJsonPath('repositories.meta.current_page', $expectedPage)
        ->assertJsonPath('repositories.fingerprint', $repositories->json('fingerprint'));
    Http::assertNothingSent();
})->with([
    'out of range' => ['9999', 2],
    'zero' => ['0', 1],
    'negative' => ['-2', 1],
    'text' => ['bad', 1],
    'array' => [['1'], 1],
    'overflow' => ['99999999999999999999999', 1],
]);

test('empty source snapshots share the empty repository page fingerprint', function () {
    $source = GitSource::factory()->create();
    $page = $this->getJson(route('git-sources.repositories.index', $source))->assertOk();

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 999]));

    $response->assertOk()->assertJsonPath('repositories', [
        'fingerprint' => $page->json('fingerprint'),
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0],
    ]);
    Http::assertNothingSent();
});

test('changes to another source do not invalidate the selected source snapshot', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'visible']);
    $page = $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    RemoteRepository::factory()->create(['name' => 'aaa-other-source']);

    $response = $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 1]));

    $response->assertOk()->assertJsonPath('repositories.fingerprint', $page->json('fingerprint'))
        ->assertJsonPath('repositories.meta.total', 1);
    Http::assertNothingSent();
});
