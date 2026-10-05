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

test('repository searches match literal substrings in names and descriptions regardless of case', function (string $field, string $value, string $search) {
    $source = GitSource::factory()->create();
    $matching = RemoteRepository::factory()->for($source, 'owner')->create([
        'name' => 'reference', 'description' => null, $field => $value,
    ]);
    RemoteRepository::factory()->count(11)->for($source, 'owner')->create(['name' => 'aaa-unrelated', 'description' => null]);
    RemoteRepository::factory()->create(['name' => 'foreign', $field => $value]);

    $response = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'search' => $search]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.external_id', $matching->external_id)
        ->assertJsonPath('meta.total', 1);
    Http::assertNothingSent();
})->with([
    'name substring' => ['name', 'project-docs-site', 'DOCS'],
    'description substring' => ['description', 'Project DOCUMENTATION site', '  documentation  '],
    'Unicode name' => ['name', 'Árvíztűrő', 'ÁRVÍZTŰRŐ'],
    'Unicode description' => ['description', 'Tükörfúrógép útmutató', 'TÜKÖRFÚRÓGÉP'],
    'literal percent' => ['description', '100% coverage', '%'],
    'literal underscore' => ['name', 'docs_site', '_'],
    'literal escape' => ['description', 'Hello! world', '!'],
    'literal backslash' => ['description', 'path\\docs', '\\'],
    'SQL text' => ['description', "Example ' OR 1=1 -- text", "' OR 1=1 --"],
]);

test('search normalization follows repository renames and nullable description updates', function () {
    $source = GitSource::factory()->create();
    $repository = RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'before', 'description' => 'previous']);
    $repository->update(['name' => 'Árvíztűrő', 'description' => 'Tükörfúrógép']);

    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'search' => 'ÁRVÍZTŰRŐ']))
        ->assertOk()->assertJsonPath('data.0.external_id', $repository->external_id);
    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'search' => 'TÜKÖRFÚRÓGÉP']))
        ->assertOk()->assertJsonCount(1, 'data');
    $repository->update(['description' => null]);
    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'search' => 'TÜKÖRFÚRÓGÉP']))
        ->assertOk()->assertJsonCount(0, 'data');
});

test('upgrading existing repositories backfills searchable Unicode names and descriptions', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'Árvíztűrő', 'description' => null]);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'guide', 'description' => 'Tükörfúrógép']);
    $migration = require database_path('migrations/2026_10_05_080539_add_search_columns_to_remote_repositories_table.php');
    $migration->down();

    $migration->up();

    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'search' => 'ÁRVÍZTŰRŐ']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Árvíztűrő');
    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'search' => 'TÜKÖRFÚRÓGÉP']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'guide');
});

test('language selection uses any selected language including the optional unknown language', function (array $filters, array $expected) {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'alpha', 'language' => 'PHP']);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'beta', 'language' => 'TypeScript']);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'gamma', 'language' => null]);
    RemoteRepository::factory()->create(['name' => 'foreign', 'language' => 'PHP']);

    $response = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, ...$filters]));

    $response->assertOk()->assertJsonPath('data.*.name', $expected)->assertJsonPath('meta.total', count($expected));
})->with([
    'nothing selected' => [[], ['alpha', 'beta', 'gamma']],
    'one language' => [['languages' => ['PHP']], ['alpha']],
    'two languages' => [['languages' => ['PHP', 'TypeScript']], ['alpha', 'beta']],
    'unknown only' => [['without_language' => true], ['gamma']],
    'known and unknown' => [['languages' => ['PHP'], 'without_language' => true], ['alpha', 'gamma']],
    'everything selected' => [['languages' => ['PHP', 'TypeScript'], 'without_language' => true], ['alpha', 'beta', 'gamma']],
    'false leaves unknown visible without another filter' => [['without_language' => 'false'], ['alpha', 'beta', 'gamma']],
    'string true supports query serialization' => [['without_language' => 'true'], ['gamma']],
    'language removed during sync' => [['languages' => ['Rust']], []],
]);

test('filtered pagination counts and limits matching SQL rows while languages cover the entire source', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->count(12)->for($source, 'owner')
        ->sequence(fn (Sequence $sequence) => ['name' => sprintf('docs-%02d', $sequence->index)])
        ->create(['language' => 'PHP']);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs-rust', 'language' => 'Rust']);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'unrelated', 'language' => 'TypeScript']);
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'unknown', 'language' => null]);
    RemoteRepository::factory()->create(['name' => 'docs-foreign', 'language' => 'Python']);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_contains($query->sql, '"remote_repositories"')) {
            $queries[] = $query->sql;
        }
    });

    $response = $this->getJson(route('git-sources.repositories.index', [
        'gitSource' => $source, 'search' => 'docs', 'languages' => ['PHP'], 'page' => 999,
    ]));

    $response->assertOk()->assertJsonPath('data.*.name', ['docs-10', 'docs-11'])
        ->assertJsonPath('meta', ['current_page' => 2, 'last_page' => 2, 'per_page' => 10, 'total' => 12])
        ->assertJsonPath('languages', ['PHP', 'Rust', 'TypeScript', null]);
    expect($queries)->toHaveCount(3);
    expect($queries[0])->toContain('count(*)', 'normalized_name LIKE', 'normalized_description LIKE', '"language" in');
    expect($queries[1])->toContain('normalized_name LIKE', '"language" in', 'limit 10 offset 10');
    expect($queries[2])->toContain('select distinct "language"')->not->toContain('LIKE');
    Http::assertNothingSent();
});

test('whitespace only searches return all repositories and an empty result keeps all language options', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['name' => 'docs', 'language' => 'PHP']);

    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'search' => '  ']))
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'search' => 'absent', 'page' => 2]))
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('languages', ['PHP'])
        ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0]);
});

test('repository metrics sort both directions with stable names and external identifiers for ties', function (string $sort, string $direction, array $expected) {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:4', 'name' => 'lowest', $sort => 1]);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:3', 'name' => 'Beta', $sort => 5]);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:2', 'name' => 'alpha', $sort => 5]);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:1', 'name' => 'Alpha', $sort => 5]);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:5', 'name' => 'highest', $sort => 9]);

    $response = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'sort' => $sort, 'direction' => $direction]));

    $response->assertOk()->assertJsonPath('data.*.external_id', $expected);
})->with(['issues_count', 'pull_requests_count', 'stars_count', 'forks_count'])->with([
    'ascending' => ['asc', ['github:4', 'github:1', 'github:2', 'github:3', 'github:5']],
    'descending' => ['desc', ['github:5', 'github:1', 'github:2', 'github:3', 'github:4']],
]);

test('last commit ordering keeps unknown dates last in both directions and stabilizes ties', function (string $direction, array $expected) {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:1', 'name' => 'old', 'last_committed_at' => '2020-01-01 12:00:00']);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:3', 'name' => 'Beta', 'last_committed_at' => '2025-01-01 12:00:00']);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:2', 'name' => 'Alpha', 'last_committed_at' => '2025-01-01 12:00:00']);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:4', 'name' => 'aaa-unknown', 'last_committed_at' => null]);

    $response = $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'sort' => 'last_committed_at', 'direction' => $direction]));

    $response->assertOk()->assertJsonPath('data.*.external_id', $expected);
})->with([
    'ascending' => ['asc', ['github:1', 'github:2', 'github:3', 'github:4']],
    'descending' => ['desc', ['github:2', 'github:3', 'github:1', 'github:4']],
]);

test('name sorting supports descending order with stable identifiers for case insensitive ties', function () {
    $source = GitSource::factory()->create();
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:2', 'name' => 'alpha']);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:1', 'name' => 'Alpha']);
    RemoteRepository::factory()->for($source, 'owner')->create(['external_id' => 'github:3', 'name' => 'Beta']);

    $this->getJson(route('git-sources.repositories.index', ['gitSource' => $source, 'sort' => 'name', 'direction' => 'desc']))
        ->assertOk()->assertJsonPath('data.*.external_id', ['github:3', 'github:1', 'github:2']);
});

test('invalid repository filters return 422 with the same errors for list and polling endpoints', function (array $filters, string $field, string $message, string $route) {
    $source = GitSource::factory()->create();

    $response = $this->getJson(route($route, ['gitSource' => $source, 'repository_page' => 1, ...$filters]));

    $response->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
    Http::assertNothingSent();
})->with([
    'search array' => [['search' => ['docs']], 'search', 'The search field must be a string.'],
    'search length' => [['search' => str_repeat('a', 256)], 'search', 'The search field must not be greater than 255 characters.'],
    'languages string' => [['languages' => 'PHP'], 'languages', 'The languages field must be an array.'],
    'languages associative' => [['languages' => ['key' => 'PHP']], 'languages', 'The languages field must be a list.'],
    'nested language' => [['languages' => [['PHP']]], 'languages.0', 'The languages.0 field must be a string.'],
    'empty language' => [['languages' => ['']], 'languages.0', 'The languages.0 field is required.'],
    'language length' => [['languages' => [str_repeat('a', 256)]], 'languages.0', 'The languages.0 field must not be greater than 255 characters.'],
    'duplicate language' => [['languages' => ['PHP', 'PHP']], 'languages.0', 'The languages.0 field has a duplicate value.'],
    'invalid unknown flag' => [['without_language' => 'yes'], 'without_language', 'The without language field must be true or false.'],
    'sort injection' => [['sort' => 'name desc; DROP TABLE remote_repositories'], 'sort', 'The selected sort is invalid.'],
    'sort array' => [['sort' => ['name']], 'sort', 'The sort field must be a string.'],
    'direction injection' => [['direction' => 'desc, name'], 'direction', 'The selected direction is invalid.'],
    'direction array' => [['direction' => ['asc']], 'direction', 'The direction field must be a string.'],
])->with(['git-sources.repositories.index', 'git-sources.show']);
