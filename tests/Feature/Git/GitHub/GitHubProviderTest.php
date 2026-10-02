<?php

use App\Git\AccountType;
use App\Git\Exceptions\GitProviderException;
use App\Git\Exceptions\InvalidResponseException;
use App\Git\Exceptions\RateLimitException;
use App\Git\Exceptions\SourceNotFoundException;
use App\Git\GitHub\GitHubProvider;
use App\Git\GitHub\GitHubRepository;
use App\Git\GitProvider;
use App\Git\GitSource;
use App\Git\RemoteRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Git\GitHubPayload;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the default provider discovers canonical account names and source getters remain local', function (string $remoteType, AccountType $accountType) {
    Http::fake([
        'https://api.github.com/users/laravel' => Http::response(GitHubPayload::account([
            'login' => 'Laravel',
            'type' => $remoteType,
            'name' => 'Laravel Framework',
        ])),
    ]);
    $provider = app(GitProvider::class);

    $source = $provider->getSource('  laravel  ');

    expect($provider)->toBeInstanceOf(GitHubProvider::class);
    expect($provider->getKey())->toBe('github');
    expect($provider->getName())->toBe('GitHub');
    expect($source->getName())->toBe('Laravel');
    expect($source->getRemoteId())->toBe('958072');
    expect($source->getDisplayName())->toBe('Laravel Framework');
    expect($source->getUrl())->toBe('https://github.com/laravel');
    expect($source->getAvatarUrl())->toBe('https://avatars.githubusercontent.com/u/958072?v=4');
    expect($source->getAccountType())->toBe($accountType);
    expect($source->getProvider())->toBe($provider);
    Http::assertSentCount(1);
})->with([
    'user' => ['User', AccountType::User],
    'organization' => ['Organization', AccountType::Organization],
]);

test('account type lookup queries GitHub each time without caching', function () {
    Http::fake([
        'https://api.github.com/users/laravel' => Http::sequence()
            ->push(GitHubPayload::account(['type' => 'User']))
            ->push(GitHubPayload::account(['type' => 'Organization'])),
    ]);
    $provider = app(GitHubProvider::class);

    $firstType = $provider->getAccountType('laravel');
    $secondType = $provider->getAccountType('laravel');

    expect($firstType)->toBe(AccountType::User);
    expect($secondType)->toBe(AccountType::Organization);
    Http::assertSentCount(2);
});

test('invalid GitHub names are rejected before sending a request', function (string $name) {
    $provider = app(GitHubProvider::class);

    expect($provider->isValidAccountName($name))->toBeFalse();
    expect(fn () => $provider->getSource($name))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'path traversal' => '../users',
    'nested namespace' => 'team/subgroup',
    'query injection' => 'laravel?type=all',
    'URL' => 'https://github.com/laravel',
    'leading hyphen' => '-laravel',
    'trailing hyphen' => 'laravel-',
    'consecutive hyphens' => 'team--name',
    'underscore' => 'team_name',
    'non-ASCII' => 'árvíz',
    'embedded newline' => "lara\nvel",
    'too long' => str_repeat('a', 40),
]);

test('GitHub account name boundary lengths are accepted', function (string $name) {
    Http::fake([
        'https://api.github.com/users/'.$name => Http::response(GitHubPayload::account(['login' => $name, 'type' => 'User'])),
    ]);
    $provider = app(GitHubProvider::class);

    $source = $provider->getSource($name);

    expect($provider->isValidAccountName($name))->toBeTrue();
    expect($source->getName())->toBe($name);
    Http::assertSentCount(1);
})->with([
    'one character' => 'a',
    'maximum length' => str_repeat('a', 39),
    'separate hyphens' => 'a-b-c',
    'mixed case and numbers' => 'A1-B2',
    'numeric' => '123',
]);

test('account name syntax checking stays local and does not normalize surrounding whitespace', function (string $name) {
    $provider = app(GitProvider::class);

    expect($provider->isValidAccountName($name))->toBeFalse();

    Http::assertNothingSent();
})->with([
    'leading space' => ' laravel',
    'trailing space' => 'laravel ',
    'trailing newline' => "laravel\n",
]);

test('missing or blank display names fall back to the canonical login', function (array $nameFields) {
    $payload = GitHubPayload::account(['login' => 'Laravel']);
    unset($payload['name']);
    Http::fake(['https://api.github.com/users/laravel' => Http::response(array_replace($payload, $nameFields))]);

    $source = app(GitProvider::class)->getSource('laravel');

    expect($source->getDisplayName())->toBe('Laravel');
    Http::assertSentCount(1);
})->with([
    'missing name' => [[]],
    'null name' => [['name' => null]],
    'empty name' => [['name' => '']],
    'whitespace name' => [['name' => " \t\n"]],
]);

test('missing or null avatars remain optional', function (array $avatarFields) {
    $payload = GitHubPayload::account();
    unset($payload['avatar_url']);
    Http::fake(['https://api.github.com/users/laravel' => Http::response(array_replace($payload, $avatarFields))]);

    $source = app(GitProvider::class)->getSource('laravel');

    expect($source->getAvatarUrl())->toBeNull();
    Http::assertSentCount(1);
})->with([
    'missing avatar' => [[]],
    'null avatar' => [['avatar_url' => null]],
]);

test('malformed account metadata raises a provider response error', function (string $field, mixed $value) {
    Http::fake(['https://api.github.com/users/laravel' => Http::response(GitHubPayload::account([$field => $value]))]);

    expect(fn () => app(GitProvider::class)->getSource('laravel'))
        ->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with([
    'null ID' => ['id', null],
    'string ID' => ['id', '958072'],
    'zero ID' => ['id', 0],
    'negative ID' => ['id', -1],
    'fractional ID' => ['id', 1.5],
    'invalid display name' => ['name', false],
    'null profile URL' => ['html_url', null],
    'non-string profile URL' => ['html_url', []],
    'relative profile URL' => ['html_url', '/laravel'],
    'insecure profile URL' => ['html_url', 'http://github.com/laravel'],
    'active profile URL' => ['html_url', 'javascript:alert(1)'],
    'profile URL credentials' => ['html_url', 'https://user:password@github.com/laravel'],
    'malformed profile URL' => ['html_url', 'https://'],
    'non-string avatar' => ['avatar_url', false],
    'blank avatar' => ['avatar_url', ''],
    'insecure avatar' => ['avatar_url', 'http://avatars.githubusercontent.com/u/958072'],
    'active avatar' => ['avatar_url', 'data:image/svg+xml,<svg></svg>'],
    'avatar credentials' => ['avatar_url', 'https://user:password@avatars.githubusercontent.com/u/958072'],
]);

test('missing required account metadata raises a provider response error', function (string $field) {
    $payload = GitHubPayload::account();
    unset($payload[$field]);
    Http::fake(['https://api.github.com/users/laravel' => Http::response($payload)]);

    expect(fn () => app(GitProvider::class)->getSource('laravel'))
        ->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with(['id', 'html_url']);

test('invalid account response fields raise a provider response error', function (array $payload) {
    Http::fake(['https://api.github.com/users/laravel' => Http::response($payload)]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))
        ->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with([
    'missing login' => [array_diff_key(GitHubPayload::account(), ['login' => null])],
    'non-string login' => [GitHubPayload::account(['login' => 123])],
    'invalid login' => [GitHubPayload::account(['login' => 'team/subgroup'])],
    'missing type' => [array_diff_key(GitHubPayload::account(), ['type' => null])],
    'non-string type' => [GitHubPayload::account(['type' => false])],
    'unknown type' => [GitHubPayload::account(['type' => 'Bot'])],
    'list instead of object' => [[GitHubPayload::account()]],
]);

test('malformed account JSON raises a provider response error', function () {
    Http::fake(['https://api.github.com/users/laravel' => Http::response('{broken', 200)]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))
        ->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
});

test('repository requests use the account endpoint and unauthenticated bounded GET settings', function (AccountType $accountType, string $path, string $type) {
    $requestOptions = [];
    Http::fake([
        'https://api.github.com/'.$path.'/laravel/repos?*' => function (Request $request, array $options) use (&$requestOptions) {
            $requestOptions = $options;

            return Http::response([]);
        },
    ]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', $accountType, '958072', 'Laravel', 'https://github.com/laravel');

    $repositories = $source->getRepositories();

    expect($repositories)->toBe([]);
    expect($requestOptions)->toMatchArray(['connect_timeout' => 3, 'timeout' => 10]);
    Http::assertSent(function (Request $request) use ($path, $type) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        expect($query)->toMatchArray([
            'type' => $type,
            'sort' => 'full_name',
            'direction' => 'asc',
            'per_page' => '100',
        ]);

        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/'.$path.'/laravel/repos'
            && $request->hasHeader('Accept', 'application/vnd.github+json')
            && $request->hasHeader('X-GitHub-Api-Version', '2026-03-10')
            && $request->hasHeader('User-Agent')
            && ! $request->hasHeader('Authorization');
    });
    Http::assertSentCount(1);
})->with([
    'user' => [AccountType::User, 'users', 'owner'],
    'organization' => [AccountType::Organization, 'orgs', 'public'],
]);

test('repository metadata is mapped to typed GitHub repositories including nullable fields', function (?string $description, ?string $language) {
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([
            GitHubPayload::repository(['description' => $description, 'language' => $language]),
        ]),
    ]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    $repositories = $provider->getRepositories($source);

    expect($repositories)->toHaveCount(1);
    expect($repositories[0])
        ->toBeInstanceOf(RemoteRepository::class)
        ->toBeInstanceOf(GitHubRepository::class)
        ->toHaveProperties([
            'id' => 'github:123',
            'name' => 'framework',
            'fullName' => 'laravel/framework',
            'url' => 'https://github.com/laravel/framework',
            'description' => $description,
            'stars' => 35000,
            'forks' => 12000,
            'language' => $language,
            'archived' => false,
        ]);
    Http::assertSentCount(1);
})->with([
    'filled metadata' => ['The Laravel Framework.', 'PHP'],
    'nullable metadata' => [null, null],
]);

test('only owned public repositories are returned while forks and archives remain included', function () {
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([
            GitHubPayload::repository(['id' => 1, 'private' => true]),
            GitHubPayload::repository(['id' => 2, 'owner' => ['login' => 'someone-else']]),
            GitHubPayload::repository(['id' => 3, 'fork' => true, 'owner' => ['login' => 'Laravel']]),
            GitHubPayload::repository(['id' => 4, 'archived' => true]),
        ]),
    ]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    $repositories = $provider->getRepositories($source);

    expect(array_column($repositories, 'id'))->toBe(['github:3', 'github:4']);
    expect($repositories[1]->archived)->toBeTrue();
    Http::assertSentCount(1);
});

test('a source from another provider instance is rejected without HTTP', function () {
    $provider = app(GitHubProvider::class);
    $otherProvider = new GitHubProvider(app(Factory::class));
    $source = new GitSource($otherProvider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getRepositories($source))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('every repository page is read and duplicate repository IDs are removed', function () {
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::sequence()
            ->push([GitHubPayload::repository()], 200, [
                'Link' => '<https://api.github.com/orgs/laravel/repos?page=2>; rel="next", <https://api.github.com/orgs/laravel/repos?page=2>; rel="last"',
            ])
            ->push([
                GitHubPayload::repository(),
                GitHubPayload::repository(['id' => 124, 'name' => 'starter', 'full_name' => 'laravel/starter']),
            ], 200, [
                'Link' => '<https://api.github.com/orgs/laravel/repos?page=1>; rel="prev"',
            ]),
    ]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    $repositories = $provider->getRepositories($source);

    expect(array_column($repositories, 'id'))->toBe(['github:123', 'github:124']);
    expect(array_column($repositories, 'fullName'))->toBe(['laravel/framework', 'laravel/starter']);
    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? null) === '2'
            && $query['type'] === 'public'
            && $query['sort'] === 'full_name'
            && $query['direction'] === 'asc'
            && $query['per_page'] === '100'
            && $request->method() === 'GET'
            && ! $request->hasHeader('Authorization');
    });
    Http::assertSentCount(2);
});

test('invalid pagination links raise a response error before following them', function (string $link) {
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()], 200, ['Link' => $link]),
    ]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getRepositories($source))->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with([
    'malformed syntax' => 'https://api.github.com/orgs/laravel/repos?page=2; rel="next"',
    'foreign host' => '<https://attacker.example/repos?page=2>; rel="next"',
    'insecure scheme' => '<http://api.github.com/orgs/laravel/repos?page=2>; rel="next"',
    'wrong source' => '<https://api.github.com/orgs/another/repos?page=2>; rel="next"',
    'changed visibility filter' => '<https://api.github.com/orgs/laravel/repos?page=2&type=private>; rel="next"',
    'credentials' => '<https://user:secret@api.github.com/orgs/laravel/repos?page=2>; rel="next"',
    'unexpected port' => '<https://api.github.com:444/orgs/laravel/repos?page=2>; rel="next"',
    'missing page' => '<https://api.github.com/orgs/laravel/repos>; rel="next"',
    'invalid page' => '<https://api.github.com/orgs/laravel/repos?page=banana>; rel="next"',
    'zero page' => '<https://api.github.com/orgs/laravel/repos?page=0>; rel="next"',
    'repeated first page' => '<https://api.github.com/orgs/laravel/repos?page=1>; rel="next"',
    'skipped second page' => '<https://api.github.com/orgs/laravel/repos?page=3>; rel="next"',
    'duplicate next links' => '<https://api.github.com/orgs/laravel/repos?page=2>; rel="next", <https://api.github.com/orgs/laravel/repos?page=3>; rel="next"',
]);

test('a pagination cycle raises a response error instead of returning a partial list', function () {
    $next = '<https://api.github.com/orgs/laravel/repos?page=2>; rel="next"';
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::sequence()
            ->push([GitHubPayload::repository()], 200, ['Link' => $next])
            ->push([], 200, ['Link' => $next]),
    ]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getRepositories($source))->toThrow(InvalidResponseException::class);

    Http::assertSentCount(2);
});

test('a 500 on a later page raises an error instead of returning a partial list', function () {
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::sequence()
            ->push([GitHubPayload::repository()], 200, [
                'Link' => '<https://api.github.com/orgs/laravel/repos?page=2>; rel="next"',
            ])
            ->push(['message' => 'Unavailable'], 500),
    ]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getRepositories($source))->toThrow(function (GitProviderException $exception) {
        expect($exception->statusCode)->toBe(500);
    });

    Http::assertSentCount(2);
});

test('invalid repository fields raise a response error instead of being coerced', function (string $field, mixed $value) {
    Http::fake([
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([
            GitHubPayload::repository([$field => $value]),
        ]),
    ]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getRepositories($source))->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with([
    'string ID' => ['id', '123'],
    'zero ID' => ['id', 0],
    'missing name value' => ['name', null],
    'empty name' => ['name', ''],
    'invalid full name' => ['full_name', []],
    'invalid URL type' => ['html_url', false],
    'invalid description' => ['description', 42],
    'string star count' => ['stargazers_count', '35000'],
    'negative star count' => ['stargazers_count', -1],
    'float fork count' => ['forks_count', 1.5],
    'invalid language' => ['language', []],
    'integer archive flag' => ['archived', 1],
    'integer visibility flag' => ['private', 0],
    'missing owner login' => ['owner', []],
    'invalid owner login' => ['owner', ['login' => 42]],
]);

test('missing nullable repository fields raise a response error', function (string $field) {
    $payload = GitHubPayload::repository();
    unset($payload[$field]);
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response([$payload])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getRepositories($source))->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with(['description', 'language']);

test('non-list repository JSON raises a response error rather than an empty success', function (string $body) {
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response($body)]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getRepositories($source))->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with([
    'malformed JSON' => '{broken',
    'empty object' => '{}',
    'numeric-key object' => '{"0":'.json_encode(GitHubPayload::repository(), JSON_THROW_ON_ERROR).'}',
    'null' => 'null',
    'error object' => '{"message":"Unexpected response"}',
    'scalar element' => '[42]',
]);

test('a 404 account response raises source not found', function () {
    Http::fake(['https://api.github.com/users/missing' => Http::response(['message' => 'Not Found'], 404)]);

    expect(fn () => app(GitHubProvider::class)->getSource('missing'))
        ->toThrow(function (SourceNotFoundException $exception) {
            expect($exception->statusCode)->toBe(404);
        });

    Http::assertSentCount(1);
});

test('non-rate-limit 403 and 500 responses raise generic provider errors without retrying', function (int $status) {
    Http::fake(['https://api.github.com/users/laravel' => Http::response(['message' => 'Unavailable'], $status)]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))
        ->toThrow(function (GitProviderException $exception) use ($status) {
            expect($exception)->not->toBeInstanceOf(RateLimitException::class);
            expect($exception->statusCode)->toBe($status);
        });

    Http::assertSentCount(1);
})->with(['403 forbidden' => 403, '500 server error' => 500]);

test('connection failures preserve the cause in a provider exception', function () {
    Http::fake(['https://api.github.com/users/laravel' => Http::failedConnection('Connection timed out')]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))
        ->toThrow(function (GitProviderException $exception) {
            expect($exception->statusCode)->toBeNull();
            expect($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
        });
});

test('403 rate limits expose GitHub reset timestamps', function () {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    Http::fake([
        'https://api.github.com/users/laravel' => Http::response(['message' => 'API rate limit exceeded'], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (new DateTimeImmutable('2026-10-02 12:05:00 UTC'))->getTimestamp(),
        ]),
    ]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))
        ->toThrow(function (RateLimitException $exception) {
            expect($exception->statusCode)->toBe(403);
            expect($exception->retryAt?->format(DateTimeInterface::ATOM))->toBe('2026-10-02T12:05:00+00:00');
        });

    Http::assertSentCount(1);
});

test('429 rate limits expose relative or HTTP-date Retry-After timestamps', function (string $retryAfter, string $expected) {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    Http::fake([
        'https://api.github.com/users/laravel' => Http::response(['message' => 'Too many requests'], 429, ['Retry-After' => $retryAfter]),
    ]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))
        ->toThrow(function (RateLimitException $exception) use ($expected) {
            expect($exception->statusCode)->toBe(429);
            expect($exception->retryAt?->format(DateTimeInterface::ATOM))->toBe($expected);
        });

    Http::assertSentCount(1);
})->with([
    'seconds' => ['120', '2026-10-02T12:02:00+00:00'],
    'HTTP-date' => ['Fri, 02 Oct 2026 12:03:00 GMT', '2026-10-02T12:03:00+00:00'],
]);

test('rate limits without a usable retry time remain distinguishable', function (int $status, string $message, array $headers) {
    Http::fake(['https://api.github.com/users/laravel' => Http::response(['message' => $message], $status, $headers)]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))
        ->toThrow(function (RateLimitException $exception) use ($status) {
            expect($exception->statusCode)->toBe($status);
            expect($exception->retryAt)->toBeNull();
        });

    Http::assertSentCount(1);
})->with([
    '429 without headers' => [429, 'Too many requests', []],
    '403 secondary limit' => [403, 'You have exceeded a secondary rate limit.', []],
    '429 invalid retry header' => [429, 'Too many requests', ['Retry-After' => 'invalid']],
    '429 overflowing retry delay' => [429, 'Too many requests', ['Retry-After' => (string) PHP_INT_MAX]],
    '429 impossible HTTP date' => [429, 'Too many requests', ['Retry-After' => 'Fri, 99 Oct 2026 12:03:00 GMT']],
]);

test('invalid Retry-After values fall back to a valid rate-limit reset timestamp', function (string $retryAfter) {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    Http::fake([
        'https://api.github.com/users/laravel' => Http::response(['message' => 'Too many requests'], 429, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Reset' => '1790942700',
        ]),
    ]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))
        ->toThrow(function (RateLimitException $exception) {
            expect($exception->retryAt?->format(DateTimeInterface::ATOM))->toBe('2026-10-02T12:05:00+00:00');
        });

    Http::assertSentCount(1);
})->with([
    'invalid string' => 'invalid',
    'overflowing delay' => (string) PHP_INT_MAX,
    'impossible HTTP date' => 'Fri, 99 Oct 2026 12:03:00 GMT',
]);
