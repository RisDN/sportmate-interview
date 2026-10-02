<?php

use App\Git\AccountType;
use App\Http\Controllers\GitSourceController;
use App\Models\GitSource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Git\GitHubPayload;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.github.pat' => null]);
    $this->withCredentials();
});

test('the source list is empty until accounts are saved', function () {
    $response = $this->getJson(route('git-sources.index'));

    $response->assertOk()->assertExactJson([
        'data' => [],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0],
    ])->assertCookie(GitSourceController::PAGE_COOKIE, '1');
    Http::assertNothingSent();
});

test('the source list reads only its requested ten rows in deterministic newest order', function () {
    $this->freezeTime();
    $sources = GitSource::factory()->count(23)->create();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_contains($query->sql, '"git_sources"')) {
            $queries[] = $query->sql;
        }
    });

    $response = $this->getJson(route('git-sources.index', ['page' => 2]));

    $response->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta', [
        'current_page' => 2, 'last_page' => 3, 'per_page' => 10, 'total' => 23,
    ])->assertCookie(GitSourceController::PAGE_COOKIE, '2');
    expect(array_column($response->json('data'), 'id'))->toBe(
        $sources->reverse()->slice(10, 10)->map(fn (GitSource $source) => (string) $source->id)->values()->all(),
    );
    expect($queries)->toHaveCount(2);
    expect($queries[0])->toContain('count(*)');
    expect($queries[1])->toContain('order by "created_at" desc, "id" desc limit 10 offset 10');
    Http::assertNothingSent();
});

test('the list sorts by creation time and serializes existing synchronization timestamps', function () {
    $newer = GitSource::factory()->create([
        'created_at' => '2026-10-02 12:00:00',
        'last_synced_at' => '2026-10-02 12:30:00',
    ]);
    GitSource::factory()->create(['created_at' => '2026-10-01 12:00:00']);

    $response = $this->getJson(route('git-sources.index'));

    $response->assertOk()->assertJsonPath('data.0.id', (string) $newer->id)
        ->assertJsonPath('data.0.last_synced_at', 1790944200)
        ->assertJsonPath('data.1.last_synced_at', null);
    Http::assertNothingSent();
});

test('a remembered page is restored and out of range pages are clamped before querying rows', function () {
    $sources = GitSource::factory()->count(11)->create();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_starts_with($query->sql, 'select * from "git_sources"')) {
            $queries[] = $query->sql;
        }
    });

    $response = $this->withCookie(GitSourceController::PAGE_COOKIE, '999')
        ->getJson(route('git-sources.index'));

    $response->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $sources->first()->id)
        ->assertJsonPath('meta.current_page', 2)
        ->assertCookie(GitSourceController::PAGE_COOKIE, '2');
    expect($queries)->toHaveCount(1);
    expect($queries[0])->toContain('limit 10 offset 10');
});

test('invalid query pages fall back to page one instead of the remembered page', function (mixed $page) {
    GitSource::factory()->count(11)->create();

    $response = $this->withCookie(GitSourceController::PAGE_COOKIE, '2')
        ->getJson(route('git-sources.index', ['page' => $page]));

    $response->assertOk()->assertJsonPath('meta.current_page', 1)
        ->assertCookie(GitSourceController::PAGE_COOKIE, '1');
})->with([
    'zero' => '0',
    'negative' => '-1',
    'decimal' => '1.5',
    'text' => 'invalid',
    'array' => [['1']],
    'overflow' => '9999999999999999999999999999',
]);

test('an invalid or tampered page cookie falls back to page one', function () {
    $response = $this->withUnencryptedCookie(GitSourceController::PAGE_COOKIE, 'invalid-ciphertext')
        ->getJson(route('git-sources.index'));

    $response->assertOk()->assertJsonPath('meta.current_page', 1);
});

test('page preferences use encrypted HttpOnly HTTPS cookies for one year', function () {
    $this->freezeTime();

    $response = $this->getJson('https://localhost/api/git-sources');

    $response->assertOk()->assertCookie(GitSourceController::PAGE_COOKIE, '1');
    $cookie = $response->getCookie(GitSourceController::PAGE_COOKIE, false);
    expect($cookie->getValue())->not->toBe('1');
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->isSecure())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($cookie->getPath())->toBe('/');
    expect($cookie->getExpiresTime())->toBe(now()->addDays(365)->getTimestamp());
});

test('creating a source saves provider metadata and queues its first synchronization', function (string $remoteType, string $accountType) {
    Http::fake(['https://api.github.com/users/laravel' => Http::response(GitHubPayload::account([
        'login' => 'Laravel',
        'type' => $remoteType,
        'name' => 'Laravel Framework',
    ]))]);

    $response = $this->postJson(route('git-sources.store'), [
        'provider' => 'github',
        'account' => '  laravel  ',
        'name' => 'Forged name',
        'url' => 'https://attacker.example',
        'avatar_url' => 'https://attacker.example/avatar.png',
        'account_type' => 'forged',
        'last_synced_at' => '2026-10-01T00:00:00Z',
    ]);

    $source = GitSource::query()->sole();
    $response->assertCreated()->assertExactJson(['data' => [
        'id' => (string) $source->id,
        'provider' => 'github',
        'account' => 'Laravel',
        'name' => 'Laravel Framework',
        'url' => 'https://github.com/laravel',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/958072?v=4',
        'account_type' => $accountType,
        'last_synced_at' => null,
        'sync_status' => 'queued',
        'last_sync_error_code' => null,
        'last_sync_error_at' => null,
        'sync_retry_at' => null,
        'sync_revision' => $source->sync_revision,
    ]])->assertCookieMissing(GitSourceController::PAGE_COOKIE);
    $this->assertDatabaseHas('git_sources', [
        'id' => $source->id,
        'remote_id' => '958072',
        'normalized_account' => 'laravel',
        'account_type' => $accountType,
        'last_synced_at' => null,
    ]);
    expect($source->account_type)->toBe(AccountType::from($accountType));
    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseEmpty('remote_repositories');
    Http::assertSentCount(1);
})->with([
    'user' => ['User', 'user'],
    'organization' => ['Organization', 'organization'],
]);

test('source validation returns 422 with translated field keys before outbound HTTP', function (array $payload, string $field, string $error) {
    $response = $this->postJson(route('git-sources.store'), $payload);

    $response->assertUnprocessable()->assertJsonPath('errors.'.$field.'.0', $error);
    $this->assertDatabaseEmpty('git_sources');
    Http::assertNothingSent();
})->with([
    'missing account' => [['provider' => 'github'], 'account', 'create.required'],
    'blank account' => [['provider' => 'github', 'account' => '  '], 'account', 'create.required'],
    'non-string account' => [['provider' => 'github', 'account' => ['laravel']], 'account', 'create.invalid'],
    'invalid account' => [['provider' => 'github', 'account' => 'team--name'], 'account', 'create.invalid'],
    'unsupported provider' => [['provider' => 'gitlab', 'account' => 'laravel'], 'provider', 'create.providerInvalid'],
    'missing provider' => [['account' => 'laravel'], 'provider', 'create.providerInvalid'],
    'non-string provider' => [['provider' => ['github'], 'account' => 'laravel'], 'provider', 'create.providerInvalid'],
]);

test('a case-insensitive duplicate returns 422 before outbound HTTP', function () {
    GitSource::factory()->create(['account' => 'Laravel', 'normalized_account' => 'laravel']);

    $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'LARAVEL']);

    $response->assertUnprocessable()->assertJsonPath('errors.account.0', 'create.duplicate');
    $this->assertDatabaseCount('git_sources', 1);
    Http::assertNothingSent();
});

test('the same remote account under a new name returns 422 without a duplicate row', function () {
    GitSource::factory()->create(['remote_id' => '958072']);
    Http::fake(['https://api.github.com/users/laravel' => Http::response(GitHubPayload::account())]);

    $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);

    $response->assertUnprocessable()->assertJsonPath('errors.account.0', 'create.duplicate');
    $this->assertDatabaseCount('git_sources', 1);
    Http::assertSentCount(1);
});

test('an identity collision at insert returns 422 while preserving the winning row', function () {
    $winner = GitSource::factory()->create(['remote_id' => '123456']);
    Http::fake(['https://api.github.com/users/laravel' => Http::response(GitHubPayload::account())]);
    GitSource::creating(function (GitSource $source) use ($winner) {
        // Simulate a collision after preflight without writing a competing row inside our transaction.
        $source->remote_id = $winner->remote_id;
    });

    try {
        $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);
    } finally {
        GitSource::flushEventListeners();
    }

    $response->assertUnprocessable()->assertJsonPath('errors.account.0', 'create.duplicate');
    $this->assertDatabaseCount('git_sources', 1);
    $this->assertDatabaseHas('git_sources', ['id' => $winner->id, 'remote_id' => '123456']);
    $this->assertDatabaseEmpty('jobs');
    Http::assertSentCount(1);
});

test('database constraints reject duplicate provider identities', function (string $column) {
    $existing = GitSource::factory()->create();

    expect(fn () => GitSource::factory()->create([$column => $existing->{$column}]))
        ->toThrow(UniqueConstraintViolationException::class);

    $this->assertDatabaseCount('git_sources', 1);
})->with(['remote_id', 'normalized_account']);

test('a missing remote source returns 422 without saving', function () {
    Http::fake(['https://api.github.com/users/missing' => Http::response([], 404)]);

    $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'missing']);

    $response->assertUnprocessable()->assertJsonPath('errors.account.0', 'create.notFound');
    $this->assertDatabaseEmpty('git_sources');
    Http::assertSentCount(1);
});

test('upstream rate limits return 429 and the retry time without saving', function () {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    Http::fake(['https://api.github.com/users/laravel' => Http::response([], 429, ['Retry-After' => '120'])]);

    $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);

    $response->assertTooManyRequests()->assertExactJson([
        'code' => 'errors.rateLimited', 'retry_at' => 1790942520,
    ])->assertHeader('Retry-After', '120');
    $this->assertDatabaseEmpty('git_sources');
    Http::assertSentCount(1);
});

test('malformed provider responses return 502 without saving', function () {
    Http::fake(['https://api.github.com/users/laravel' => Http::response('{broken')]);

    $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);

    $response->assertStatus(502)->assertExactJson(['code' => 'errors.providerInvalidResponse']);
    $this->assertDatabaseEmpty('git_sources');
    Http::assertSentCount(1);
});

test('unavailable providers return 503 without saving', function (bool $connectionFailure) {
    Http::fake(['https://api.github.com/users/laravel' => $connectionFailure
        ? Http::failedConnection()
        : Http::response([], 500)]);

    $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);

    $response->assertServiceUnavailable()->assertExactJson(['code' => 'errors.providerUnavailable']);
    $this->assertDatabaseEmpty('git_sources');
})->with(['connection failure' => true, 'upstream failure' => false]);

test('Precognition returns the same validation errors without HTTP or writes', function () {
    $response = $this->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'account'])
        ->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'team--name']);

    $response->assertUnprocessable()->assertJsonPath('errors.account.0', 'create.invalid');
    $this->assertDatabaseEmpty('git_sources');
    Http::assertNothingSent();
});

test('valid Precognition returns 204 without HTTP or writes', function () {
    $response = $this->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'account,provider'])
        ->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);

    $response->assertNoContent()->assertHeader('Precognition-Success', 'true')
        ->assertCookieMissing(GitSourceController::PAGE_COOKIE);
    $this->assertDatabaseEmpty('git_sources');
    Http::assertNothingSent();
});

test('source creation keeps CSRF protection and returns 419 without a token', function () {
    $this->app->instance('env', 'local');

    $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);

    $response->assertStatus(419);
    $this->assertDatabaseEmpty('git_sources');
    Http::assertNothingSent();
});

test('list failures return 500 without overwriting the remembered page or exposing database details', function () {
    DB::connection()->beforeExecuting(function (string $query) {
        if (str_contains($query, '"git_sources"')) {
            throw new RuntimeException('Private database details');
        }
    });

    $response = $this->withCookie(GitSourceController::PAGE_COOKIE, '2')->getJson(route('git-sources.index'));

    $response->assertInternalServerError()->assertExactJson(['code' => 'errors.unexpected'])
        ->assertCookieMissing(GitSourceController::PAGE_COOKIE);
    Http::assertNothingSent();
});

test('write failures return 500 without saving or exposing database details', function () {
    Http::fake(['https://api.github.com/users/laravel' => Http::response(GitHubPayload::account())]);
    GitSource::creating(function () {
        throw new RuntimeException('Private write details');
    });

    try {
        $response = $this->postJson(route('git-sources.store'), ['provider' => 'github', 'account' => 'laravel']);
    } finally {
        GitSource::flushEventListeners();
    }

    $response->assertInternalServerError()->assertExactJson(['code' => 'errors.unexpected']);
    $this->assertDatabaseEmpty('git_sources');
    Http::assertSentCount(1);
});

test('source searches match usernames and Unicode display names beyond the unfiltered first page', function (string $search) {
    $source = GitSource::factory()->create(['account' => 'risdn', 'normalized_account' => 'risdn', 'name' => 'Rostás András']);
    GitSource::factory()->count(12)->create(['name' => 'Unrelated account']);

    $response = $this->getJson(route('git-sources.index', ['search' => $search]));

    $response->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $source->id)->assertJsonPath('meta.total', 1);
    Http::assertNothingSent();
})->with(['username' => 'RISDN', 'partial username' => 'isd', 'display name' => 'Rostás András', 'accented case' => 'ROSTÁS', 'trimmed surname' => '  andrás  ']);

test('source searches paginate all matching rows and preserve the unfiltered page preference', function () {
    GitSource::factory()->count(12)->create(['name' => 'Matching account']);
    GitSource::factory()->count(10)->create(['name' => 'Unrelated account']);

    $this->withCookie(GitSourceController::PAGE_COOKIE, '2')
        ->getJson(route('git-sources.index', ['search' => 'matching']))
        ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.total', 12)->assertCookieMissing(GitSourceController::PAGE_COOKIE);

    $this->getJson(route('git-sources.index', ['search' => 'matching', 'page' => 999]))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 12);
});

test('source search treats SQL wildcard and escape characters as literal text', function (string $search) {
    $source = GitSource::factory()->create(['name' => 'Before '.$search.' after']);
    GitSource::factory()->create(['name' => 'Before anything after']);

    $response = $this->getJson(route('git-sources.index', ['search' => $search]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $source->id);
})->with(['percent' => '%', 'underscore' => '_', 'escape' => '!', 'backslash' => '\\', 'SQL text' => "' OR 1=1 --"]);

test('empty searches restore the full source list and remembered page', function () {
    GitSource::factory()->count(11)->create();

    $this->withCookie(GitSourceController::PAGE_COOKIE, '2')
        ->getJson(route('git-sources.index', ['search' => '  ']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.total', 11)->assertCookie(GitSourceController::PAGE_COOKIE, '2');
});

test('invalid source searches return 422 before changing the page preference', function (mixed $search, string $message) {
    $this->getJson(route('git-sources.index', ['search' => $search]))
        ->assertUnprocessable()->assertJsonPath('errors.search.0', $message)
        ->assertCookieMissing(GitSourceController::PAGE_COOKIE);
    Http::assertNothingSent();
})->with([
    'array' => [['risdn'], 'The search field must be a string.'],
    'too long' => [str_repeat('x', 256), 'The search field must not be greater than 255 characters.'],
]);

test('renamed display names remain searchable with Unicode case folding', function () {
    $source = GitSource::factory()->create(['name' => 'Previous display name']);
    $source->update(['name' => 'Árvíztűrő Tükörfúrógép']);

    $this->getJson(route('git-sources.index', ['search' => 'ÁRVÍZTŰRŐ']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $source->id);
    $this->getJson(route('git-sources.index', ['search' => 'Previous display name']))
        ->assertOk()->assertJsonCount(0, 'data');
});
