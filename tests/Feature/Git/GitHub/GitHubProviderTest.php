<?php

use App\Git\AccountType;
use App\Git\Exceptions\AccessDeniedException;
use App\Git\Exceptions\AuthenticationException;
use App\Git\Exceptions\GitProviderException;
use App\Git\Exceptions\InvalidResponseException;
use App\Git\Exceptions\RateLimitException;
use App\Git\Exceptions\SourceNotFoundException;
use App\Git\GitHub\GitHubProvider;
use App\Git\GitHub\GitHubRepository;
use App\Git\GitProvider;
use App\Git\GitSource;
use App\Git\RemoteRepository;
use App\Git\RepositoryDetails;
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

test('every GitHub endpoint uses the optional PAT without changing public repository scope', function (?string $configuredToken, ?string $expectedToken) {
    config(['services.github.pat' => $configuredToken]);
    Http::fake([
        'https://api.github.com/users/laravel' => Http::response(GitHubPayload::account()),
        'https://api.github.com/orgs/laravel/repos?*' => Http::response([
            GitHubPayload::repository(),
            GitHubPayload::repository(['id' => 124, 'private' => true]),
        ]),
        'https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository()),
        'https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([]),
        'https://api.github.com/repos/laravel/framework/commits?per_page=1' => Http::response([]),
    ]);
    $provider = app(GitHubProvider::class);

    $source = $provider->getSource('laravel');
    $page = $provider->getRepositoriesPage($source);
    $provider->getRepositoryDetails($source, 'framework');
    $provider->getPullRequestsPage($source, 'framework', 'github:123');
    $provider->getLastCommitAt($source, 'framework');

    expect(array_column($page->repositories, 'id'))->toBe(['github:123']);
    expect($provider->getUrlPrefix())->toBe('https://github.com');
    Http::assertSentCount(5);
    foreach (Http::recorded() as [$request]) {
        expect($request->header('Authorization'))->toBe($expectedToken === null ? [] : ['Bearer '.$expectedToken]);
    }
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orgs/laravel/repos?type=public&'));
})->with([
    'configured PAT' => ['  test-pat-value  ', 'test-pat-value'],
    'missing PAT' => [null, null],
    'empty PAT' => ['', null],
    'whitespace PAT' => [" \t\r\n ", null],
]);

test('invalid credentials and denied access never retry anonymously or expose upstream secrets', function (int $status, string $exceptionClass) {
    config(['services.github.pat' => 'test-pat-value']);
    Http::fake(['https://api.github.com/users/laravel' => Http::response(['message' => 'test-pat-value'], $status)]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))->toThrow(function (GitProviderException $exception) use ($status, $exceptionClass) {
        expect($exception)->toBeInstanceOf($exceptionClass);
        expect($exception->statusCode)->toBe($status);
        expect((string) $exception)->not->toContain('test-pat-value');
    });

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-pat-value'));
})->with([
    '401 invalid PAT' => [401, AuthenticationException::class],
    '403 missing access' => [403, AccessDeniedException::class],
]);

test('authenticated transport failures do not retain a credential-bearing cause', function () {
    config(['services.github.pat' => 'test-pat-value']);
    Http::fake(['https://api.github.com/users/laravel' => Http::failedConnection('Transport leaked test-pat-value')]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))->toThrow(function (GitProviderException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
        expect((string) $exception)->not->toContain('test-pat-value');
    });
});

test('a cooldown stops other sources before HTTP while a different authentication context remains usable', function () {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    config(['services.github.pat' => null]);
    Http::fake([
        'https://api.github.com/users/laravel' => Http::response([], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => '1790942700',
        ]),
        'https://api.github.com/users/octocat' => Http::response(GitHubPayload::account(['login' => 'octocat'])),
    ]);

    expect(fn () => app(GitHubProvider::class)->getSource('laravel'))->toThrow(RateLimitException::class);
    expect(fn () => app(GitHubProvider::class)->getSource('octocat'))->toThrow(function (RateLimitException $exception) {
        expect($exception->retryAt?->format(DATE_ATOM))->toBe('2026-10-02T12:05:00+00:00');
    });
    config(['services.github.pat' => 'test-pat-value']);
    $source = app(GitHubProvider::class)->getSource('octocat');

    expect($source->getName())->toBe('octocat');
    Http::assertSentCount(2);
});

test('the last allowed success saves its quota reset and requests resume after that time', function () {
    $this->travelTo('2026-10-02 12:00:00 UTC');
    Http::fake(['https://api.github.com/users/laravel' => Http::sequence()
        ->push(GitHubPayload::account(), 200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1790942402'])
        ->push(GitHubPayload::account())]);
    $provider = app(GitHubProvider::class);

    $provider->getSource('laravel');
    expect(fn () => $provider->getSource('laravel'))->toThrow(RateLimitException::class);
    $this->travelTo('2026-10-02 12:00:03 UTC');
    $source = $provider->getSource('laravel');

    expect($source->getName())->toBe('laravel');
    Http::assertSentCount(2);
});

test('a repository page stops before the next HTTP call and retains its continuation', function () {
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response([GitHubPayload::repository()], 200, [
        'Link' => '<https://api.github.com/orgs/laravel/repos?page=2>; rel="next"',
    ])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    $page = $provider->getRepositoriesPage($source);

    expect(array_column($page->repositories, 'id'))->toBe(['github:123']);
    expect($page->nextPage)->toBe(2);
    Http::assertSentCount(1);
});

test('organization pagination accepts the canonical ID path only for the resolved organization', function () {
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::sequence()
        ->push([GitHubPayload::repository()], 200, ['Link' => '<https://api.github.com/organizations/958072/repos?per_page=100&page=2>; rel="next"'])
        ->push([], 200)]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    $repositories = $provider->getRepositories($source);

    expect(array_column($repositories, 'id'))->toBe(['github:123']);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/orgs/laravel/repos?type=public&per_page=100&sort=full_name&direction=asc&page=2');
});

test('organization pagination never accepts a canonical path belonging to another source', function () {
    Http::fake(['https://api.github.com/orgs/laravel/repos?*' => Http::response([], 200, [
        'Link' => '<https://api.github.com/organizations/999/repos?page=2>; rel="next"',
    ])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getRepositoriesPage($source))->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
});

test('fresh repository details retain identity ownership and nullable metadata through a durable checkpoint', function () {
    Http::fake(['https://api.github.com/repos/laravel/framework' => Http::response(GitHubPayload::repository([
        'description' => null, 'language' => null, 'archived' => true, 'open_issues_count' => 27,
    ]))]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    $details = $provider->getRepositoryDetails($source, 'framework');

    expect($details->toArray())->toBe([
        'id' => 'github:123', 'name' => 'framework', 'description' => null, 'stars' => 35000,
        'forks' => 12000, 'language' => null, 'archived' => true, 'open_issues_count' => 27,
        'owner_id' => '958072', 'owner_name' => 'laravel', 'is_private' => false,
    ]);
    expect(RepositoryDetails::fromArray($details->toArray()))->toEqual($details);
    Http::assertSentCount(1);
});

test('pull request pagination proves a total only when the response supports it', function (int $page, int $perPage, int $count, string $link, ?int $total, ?int $nextPage) {
    $items = array_map(fn (int $id) => ['id' => $id, 'state' => 'open'], $count === 0 ? [] : range(1, $count));
    Http::fake(['https://api.github.com/repos/laravel/framework/pulls?*' => Http::response($items, 200, ['Link' => $link])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    $result = $provider->getPullRequestsPage($source, 'framework', 'github:123', $page, $perPage);

    expect($result)->toHaveProperties(['count' => $count, 'total' => $total, 'nextPage' => $nextPage]);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($page, $perPage) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['state' => 'open', 'per_page' => (string) $perPage, 'page' => (string) $page];
    });
})->with([
    'empty' => [1, 1, 0, '', 0, null],
    'one pull request' => [1, 1, 1, '', 1, null],
    'canonical ID last page' => [1, 1, 1, '<https://api.github.com/repositories/123/pulls?state=open&per_page=1&page=2>; rel="next", <https://api.github.com/repositories/123/pulls?state=open&per_page=1&page=137>; rel="last"', 137, 2],
    'last link proves the total without a next link' => [1, 1, 1, '<https://api.github.com/repositories/123/pulls?page=137>; rel="last"', 137, 2],
    'no last page' => [1, 1, 1, '<https://api.github.com/repos/laravel/framework/pulls?page=2>; rel="next"', null, 2],
    'fallback first page' => [1, 100, 100, '<https://api.github.com/repos/laravel/framework/pulls?per_page=100&page=2>; rel="next"', null, 2],
    'fallback final page' => [2, 100, 2, '', null, null],
]);

test('unsafe or inconsistent pull request pagination is rejected without following a link', function (string $link) {
    Http::fake(['https://api.github.com/repos/laravel/framework/pulls?*' => Http::response([['id' => 1, 'state' => 'open']], 200, ['Link' => $link])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getPullRequestsPage($source, 'framework', 'github:123'))->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with([
    'foreign host' => '<https://other.test/repositories/123/pulls?page=2>; rel="next"',
    'foreign ID' => '<https://api.github.com/repositories/999/pulls?page=2>; rel="next"',
    'changed state' => '<https://api.github.com/repositories/123/pulls?state=closed&page=2>; rel="next"',
    'changed page size' => '<https://api.github.com/repositories/123/pulls?per_page=100&page=2>; rel="next"',
    'overflow' => '<https://api.github.com/repositories/123/pulls?page=99999999999999999999999>; rel="last"',
    'cycle' => '<https://api.github.com/repositories/123/pulls?page=1>; rel="next"',
]);

test('the latest commit uses the default branch committer date rather than author or repository metadata', function () {
    Http::fake(['https://api.github.com/repos/laravel/framework/commits?per_page=1' => Http::response([
        ['commit' => ['author' => ['date' => '2020-01-01T00:00:00Z'], 'committer' => ['date' => '2026-10-02T12:34:56Z']]],
    ])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    $date = $provider->getLastCommitAt($source, 'framework');

    expect($date?->format(DATE_ATOM))->toBe('2026-10-02T12:34:56+00:00');
    Http::assertSentCount(1);
});

test('an empty repository has no commit date without becoming a failed synchronization', function (int $status, array $payload) {
    Http::fake(['https://api.github.com/repos/laravel/framework/commits?per_page=1' => Http::response($payload, $status)]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect($provider->getLastCommitAt($source, 'framework'))->toBeNull();

    Http::assertSentCount(1);
})->with([
    'empty successful list' => [200, []],
    'known empty repository response' => [409, ['message' => 'Git Repository is empty.']],
]);

test('an unrelated commit conflict is not mistaken for an empty repository', function () {
    Http::fake(['https://api.github.com/repos/laravel/framework/commits?per_page=1' => Http::response(['message' => 'Temporarily unavailable'], 409)]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getLastCommitAt($source, 'framework'))->toThrow(GitProviderException::class);

    Http::assertSentCount(1);
});

test('invalid commit dates are rejected instead of silently normalized', function (mixed $date) {
    Http::fake(['https://api.github.com/repos/laravel/framework/commits?per_page=1' => Http::response([
        ['commit' => ['committer' => ['date' => $date]]],
    ])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => $provider->getLastCommitAt($source, 'framework'))->toThrow(InvalidResponseException::class);

    Http::assertSentCount(1);
})->with([
    'impossible day' => '2026-02-30T12:00:00Z',
    'no timezone' => '2026-10-02T12:00:00',
    'relative date' => 'tomorrow',
    'null date' => null,
    'invalid offset' => '2026-10-02T12:00:00+99:99',
]);

test('moved repository endpoints become skippable without following credential-bearing redirects', function (string $endpoint, int $status) {
    config(['services.github.pat' => 'test-private-token']);
    Http::fake(['https://api.github.com/repos/laravel/framework*' => Http::response([], $status, [
        'Location' => 'https://untrusted.example/repository',
    ])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    expect(fn () => match ($endpoint) {
        'details' => $provider->getRepositoryDetails($source, 'framework'),
        'pulls' => $provider->getPullRequestsPage($source, 'framework', 'github:123'),
        'commits' => $provider->getLastCommitAt($source, 'framework'),
    })->toThrow(SourceNotFoundException::class, 'The GitHub repository moved.');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.github.com/')
        && $request->hasHeader('Authorization', 'Bearer test-private-token'));
})->with(['details', 'pulls', 'commits'])->with([301, 308]);

test('profile and repository discovery redirects remain terminal provider failures', function (string $endpoint) {
    Http::fake(['https://api.github.com/*' => Http::response([], 301, [
        'Location' => 'https://api.github.com/users/renamed',
    ])]);
    $provider = app(GitHubProvider::class);
    $source = new GitSource($provider, 'laravel', AccountType::Organization, '958072', 'Laravel', 'https://github.com/laravel');

    try {
        match ($endpoint) {
            'profile' => $provider->getSource('laravel'),
            'repositories' => $provider->getRepositoriesPage($source),
        };
        $this->fail('A redirected source must not be accepted.');
    } catch (GitProviderException $exception) {
        expect($exception)->not->toBeInstanceOf(SourceNotFoundException::class);
        expect($exception->statusCode)->toBe(301);
    }

    Http::assertSentCount(1);
})->with(['profile', 'repositories']);
