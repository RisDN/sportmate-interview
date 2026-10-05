<?php

use App\Enums\SyncStatus;
use App\Models\GitSource;
use App\Models\RemoteRepository;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Cache\FailingCacheStore;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.github.pat' => null,
        'repositories.cache_store' => 'array',
        'repositories.cache_ttl' => 60,
        'cache.stores.array.serialize' => true,
        'cache.serializable_classes' => false,
    ]);
});

function repositoryCacheQueries(): object
{
    $queries = (object) ['sql' => []];
    DB::listen(function (QueryExecuted $query) use ($queries): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"remote_repositories"')) {
            $queries->sql[] = $query->sql;
        }
    });

    return $queries;
}

test('repeated repository reads and polling share serialized cache results without repository SQL', function () {
    $source = GitSource::factory()->create(['account' => 'laravel']);
    RemoteRepository::factory()->for($source, 'owner')->create([
        'name' => 'docs', 'language' => 'PHP', 'last_committed_at' => '2026-10-02 12:30:00',
    ]);
    $filters = ['gitSource' => $source, 'search' => 'docs'];
    $first = $this->getJson(route('git-sources.repositories.index', $filters))->assertOk();
    $queries = repositoryCacheQueries();

    $this->getJson(route('git-sources.repositories.index', $filters))->assertOk()->assertExactJson($first->json());
    $this->getJson(route('git-sources.show', [...$filters, 'repository_page' => 1]))->assertOk()
        ->assertJsonPath('repositories.fingerprint', $first->json('fingerprint'))->assertJsonPath('repositories.languages', ['PHP']);

    expect($queries->sql)->toBe([]);
    $first->assertJsonPath('data.0.last_committed_at', 1790944200)->assertJsonPath('data.0.url', 'https://github.com/laravel/docs');
    $entries = Cache::store('array')->getStore()->all(false);
    expect($entries)->toHaveCount(2);
    foreach ($entries as $entry) {
        expect($entry['value'])->toBeString()->not->toContain('O:');
    }
    Http::assertNothingSent();
});

test('equivalent search case whitespace and language order reuse the same cache entry', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs', 'language' => 'PHP']);
    $this->getJson(route('git-sources.repositories.index', [
        'gitSource' => $source, 'search' => ' docs ', 'languages' => ['TypeScript', 'PHP'],
    ]))->assertOk();
    $queries = repositoryCacheQueries();

    $this->getJson(route('git-sources.repositories.index', [
        'gitSource' => $source, 'search' => 'DOCS', 'languages' => ['PHP', 'TypeScript'],
    ]))->assertOk()->assertJsonPath('data.0.name', 'docs');

    expect($queries->sql)->toBe([]);
});

test('different filters sorting direction and pages have separate cache entries', function (array $filters, array $names) {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'alpha', 'language' => 'PHP', 'stars_count' => 10]);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'beta', 'language' => 'Rust', 'stars_count' => 1]);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'gamma', 'language' => null, 'stars_count' => 5]);
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $queries = repositoryCacheQueries();

    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, ...$filters]))
        ->assertOk()->assertJsonPath('data.*.name', $names);

    expect($queries->sql)->toHaveCount(2);
})->with([
    'search' => [['search' => 'beta'], ['beta']],
    'language' => [['languages' => ['PHP']], ['alpha']],
    'unknown language' => [['without_language' => true], ['gamma']],
    'sort field' => [['sort' => 'stars_count'], ['beta', 'gamma', 'alpha']],
    'direction' => [['direction' => 'desc'], ['gamma', 'beta', 'alpha']],
    'requested page' => [['page' => 2], ['alpha', 'beta', 'gamma']],
]);

test('cached pagination returns each page and keeps other sources isolated', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->count(11)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('docs-%02d', $sequence->index)])
        ->create(['language' => 'PHP']);
    $other = GitSource::factory()->create();
    RemoteRepository::factory()->for($other, 'owner')->create(['name' => 'other-source', 'language' => 'Rust']);
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk();

    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => 2]))
        ->assertOk()->assertJsonPath('data.*.name', ['docs-10'])->assertJsonPath('meta.current_page', 2);
    $this->getJson(route('git-sources.repositories.index', $other))->assertOk()
        ->assertJsonPath('data.*.name', ['other-source'])->assertJsonPath('languages', ['Rust']);
});

test('repository creation edits and deletion invalidate cached rows and language options', function () {
    $source = GitSource::factory()->create();
    $filters = ['gitSource' => $source, 'search' => 'docs'];
    $this->getJson(route('git-sources.repositories.index', $filters))->assertOk()->assertJsonCount(0, 'data');
    $repository = RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs', 'language' => 'PHP']);

    $this->getJson(route('git-sources.repositories.index', $filters))->assertOk()
        ->assertJsonPath('data.0.name', 'docs')->assertJsonPath('languages', ['PHP']);
    $repository->update(['stars_count' => 42, 'language' => 'Rust']);
    $this->getJson(route('git-sources.repositories.index', $filters))->assertOk()
        ->assertJsonPath('data.0.stars_count', 42)->assertJsonPath('languages', ['Rust']);
    $repository->delete();
    $this->getJson(route('git-sources.repositories.index', $filters))->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('languages', []);
});

test('bulk repository transfers invalidate the old and new owners atomically', function () {
    $source = GitSource::factory()->create(['account' => 'old-owner']);
    $other = GitSource::factory()->create(['account' => 'new-owner']);
    $repository = RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs', 'language' => 'PHP']);
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(route('git-sources.repositories.index', $other))->assertOk()->assertJsonCount(0, 'data');

    DB::table('remote_repositories')->where('external_id', $repository->external_id)->update(['git_source_id' => $other->id]);

    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('languages', []);
    $this->getJson(route('git-sources.repositories.index', $other))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.url', 'https://github.com/new-owner/docs')->assertJsonPath('languages', ['PHP']);
});

test('source account changes invalidate cached repository URLs', function () {
    $source = GitSource::factory()->create(['account' => 'before']);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs']);
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $source->update(['account' => 'after']);
    $queries = repositoryCacheQueries();

    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()->assertJsonPath('data.0.url', 'https://github.com/after/docs');

    expect($queries->sql)->toHaveCount(3);
});

test('cached repositories cannot bypass source deletion checks', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create();
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk();

    $this->deleteJson(route('git-sources.destroy', $source))->assertAccepted();

    $this->getJson(route('git-sources.repositories.index', $source))->assertNotFound();
    $this->getJson(route('git-sources.show', ['gitSource' => $source, 'repository_page' => 1]))->assertNotFound();
});

test('sync start progress and completion invalidate all cached filters through the sync revision', function (SyncStatus $status) {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs']);
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $source->update(['sync_revision' => 1, 'sync_status' => $status]);
    $queries = repositoryCacheQueries();

    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()->assertJsonPath('data.0.name', 'docs');

    expect($queries->sql)->toHaveCount(3);
})->with([SyncStatus::Queued, SyncStatus::Syncing, SyncStatus::Succeeded]);

test('rolled back cache misses never publish rows under a generation reused by a later commit', function () {
    $source = GitSource::factory()->create();
    $filters = ['gitSource' => $source, 'search' => 'docs'];
    $this->getJson(route('git-sources.repositories.index', $filters))->assertOk()->assertJsonCount(0, 'data');
    DB::beginTransaction();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs', 'language' => 'PHP']);
    $this->getJson(route('git-sources.repositories.index', $filters))->assertOk()->assertJsonCount(1, 'data');
    DB::rollBack();

    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'other', 'description' => null, 'language' => 'Rust']);

    $this->getJson(route('git-sources.repositories.index', $filters))->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('languages', ['Rust']);
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()->assertJsonPath('data.*.name', ['other']);
});

test('expired repository results query fresh SQL and repopulate the cache', function () {
    $this->freezeTime();
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs']);
    $this->getJson(route('git-sources.repositories.index', $source))->assertOk();
    $this->travel(61)->seconds();
    $queries = repositoryCacheQueries();

    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()->assertJsonPath('data.0.name', 'docs');

    expect($queries->sql)->toHaveCount(3);
});

test('cache read and write failures fall back to successful SQL responses', function (string $operation) {
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore($operation)));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'repositories.cache_store' => 'failing']);
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs', 'language' => 'PHP']);

    $this->getJson(route('git-sources.repositories.index', $source))->assertOk()
        ->assertJsonPath('data.0.name', 'docs')->assertJsonPath('languages', ['PHP']);
})->with(['get', 'put']);

test('cache failures never hide SQL failures', function () {
    Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore('get')));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'repositories.cache_store' => 'failing']);
    $source = GitSource::factory()->create();
    DB::connection()->beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'select') && str_contains($query, '"remote_repositories"')) {
            throw new RuntimeException('Private database details.');
        }
    });

    $this->getJson(route('git-sources.repositories.index', $source))->assertInternalServerError()
        ->assertExactJson(['code' => 'errors.unexpected']);
});
