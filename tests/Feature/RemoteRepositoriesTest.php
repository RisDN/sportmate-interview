<?php

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

test('repository pagination reads only ten rows from a source with three thousand repositories', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->count(3000)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('repository-%04d', $sequence->index)])
        ->create();
    RemoteRepository::factory()->create(['name' => 'another-source']);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_contains($query->sql, '"remote_repositories"')) {
            $queries[] = $query->sql;
        }
    });

    $response = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => 2]));

    $response->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta', [
        'current_page' => 2, 'last_page' => 300, 'per_page' => 10, 'total' => 3000,
    ])->assertJsonPath('data.0.name', 'repository-0010')->assertJsonPath('data.9.name', 'repository-0019');
    expect($queries)->toHaveCount(2);
    expect($queries[0])->toContain('count(*)');
    expect($queries[1])->toContain('order by "name" asc, "external_id" asc limit 10 offset 10');
    expect(array_unique(array_column($response->json('data'), 'git_source_id')))->toBe([(string) $source->id]);
    Http::assertNothingSent();
});

test('repository resources expose derived URLs and Unix dates without internal synchronization data', function () {
    $source = GitSource::factory()->create(['account' => 'RisDN', 'name' => 'Rostás András']);
    RemoteRepository::factory()->for($source, 'owner')->archived()->create([
        'external_id' => 'github:123', 'name' => 'Hello-World', 'description' => null,
        'stars_count' => 12, 'issues_count' => 3, 'pull_requests_count' => 2, 'forks_count' => 4,
        'language' => 'PHP', 'last_committed_at' => '2026-10-02 12:30:00', 'sync_version' => 5,
    ]);

    $response = $this->getJson(route('git-sources.repositories.index', $source));

    $response->assertOk()->assertJsonPath('data.0', [
        'external_id' => 'github:123', 'git_source_id' => (string) $source->id,
        'name' => 'Hello-World', 'description' => null, 'url' => 'https://github.com/RisDN/Hello-World',
        'stars_count' => 12, 'issues_count' => 3, 'pull_requests_count' => 2, 'forks_count' => 4,
        'language' => 'PHP', 'archived' => true, 'last_committed_at' => 1790944200,
    ]);
    Http::assertNothingSent();
});

test('repository ordering ignores name case and breaks ties with external identifiers', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:3', 'name' => 'Beta']);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:2', 'name' => 'alpha']);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:1', 'name' => 'Alpha']);

    $response = $this->getJson(route('git-sources.repositories.index', $source));

    $response->assertOk();
    expect(array_column($response->json('data'), 'external_id'))->toBe(['github:1', 'github:2', 'github:3']);
});

test('empty repository lists return a single empty page', function () {
    $source = GitSource::factory()->create();

    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => 300]))
        ->assertOk()->assertExactJson([
            'data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0],
        ]);
});

test('repository page parameters are normalized before querying', function (mixed $page, int $expectedPage, int $count) {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->count(11)->for($source, 'owner')->create();

    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'page' => $page]))
        ->assertOk()->assertJsonPath('meta.current_page', $expectedPage)->assertJsonCount($count, 'data');
})->with([
    'out of range' => ['9999', 2, 1],
    'zero' => ['0', 1, 10],
    'negative' => ['-2', 1, 10],
    'text' => ['bad', 1, 10],
    'array' => [['1'], 1, 10],
    'overflow' => ['99999999999999999999999', 1, 10],
]);

test('deleting a source also removes its persisted repositories', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create();
    $other = RemoteRepository::factory()->create();

    $source->delete();

    $this->assertDatabaseCount('remote_repositories', 1);
    $this->assertDatabaseHas('remote_repositories', ['external_id' => $other->external_id]);
});

test('repository read failures return a safe error for the nested endpoint', function () {
    $source = GitSource::factory()->create();
    DB::connection()->beforeExecuting(function (string $query) {
        if (str_contains($query, '"remote_repositories"')) {
            throw new RuntimeException('Private database details');
        }
    });

    $this->getJson(route('git-sources.repositories.index', $source))
        ->assertInternalServerError()->assertExactJson(['code' => 'errors.unexpected']);
});
