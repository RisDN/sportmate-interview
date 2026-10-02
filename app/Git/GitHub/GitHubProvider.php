<?php

namespace App\Git\GitHub;

use App\Git\AccountType;
use App\Git\Exceptions\AccessDeniedException;
use App\Git\Exceptions\AuthenticationException;
use App\Git\Exceptions\GitProviderException;
use App\Git\Exceptions\InvalidResponseException;
use App\Git\Exceptions\RateLimitException;
use App\Git\Exceptions\SourceNotFoundException;
use App\Git\GitProvider;
use App\Git\GitSource;
use App\Git\PullRequestPage;
use App\Git\RemoteRepository;
use App\Git\RepositoryDetails;
use App\Git\RepositoryPage;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class GitHubProvider implements GitProvider
{
    private const BASE_URL = 'https://api.github.com';

    public function __construct(private readonly Factory $http) {}

    public function getKey(): string
    {
        return 'github';
    }

    public function getName(): string
    {
        return 'GitHub';
    }

    public function getUrlPrefix(): string
    {
        return 'https://github.com';
    }

    public function isValidAccountName(string $name): bool
    {
        // GitHub's current signup rules exclude legacy and managed-user names.
        return preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}\z/', $name) === 1;
    }

    public function getAccountType(string $name): AccountType
    {
        return $this->getSource($name)->getAccountType();
    }

    public function getSource(string $name): GitSource
    {
        $name = $this->normalizeName($name);
        $account = $this->object($this->decode($this->get(self::BASE_URL.'/users/'.$name)));
        $login = $this->string($account, 'login');

        if (! $this->isValidAccountName($login)) {
            throw new InvalidResponseException('GitHub returned an invalid account name.', 200);
        }

        $type = match ($this->string($account, 'type')) {
            'User' => AccountType::User,
            'Organization' => AccountType::Organization,
            default => throw new InvalidResponseException('GitHub returned an unsupported account type.', 200),
        };

        $id = $this->integer($account, 'id');

        if ($id === 0) {
            throw new InvalidResponseException('GitHub returned an invalid account identifier.', 200);
        }

        $displayName = $account['name'] ?? null;

        if ($displayName !== null && ! is_string($displayName)) {
            throw new InvalidResponseException('GitHub returned an invalid name field.', 200);
        }

        $displayName = trim($displayName ?? '');
        $avatarUrl = $account['avatar_url'] ?? null;

        return new GitSource(
            provider: $this,
            name: $login,
            accountType: $type,
            remoteId: (string) $id,
            displayName: $displayName === '' ? $login : $displayName,
            url: $this->httpsUrl($account['html_url'] ?? null, 'html_url'),
            avatarUrl: $avatarUrl === null ? null : $this->httpsUrl($avatarUrl, 'avatar_url'),
        );
    }

    /**
     * @return list<RemoteRepository>
     */
    public function getRepositories(GitSource $source): array
    {
        $page = 1;
        $repositories = [];

        do {
            $result = $this->getRepositoriesPage($source, $page);

            foreach ($result->repositories as $repository) {
                $repositories[$repository->id] = $repository;
            }

            $page = $result->nextPage;
        } while ($page !== null);

        $repositories = array_values($repositories);
        usort($repositories, fn (RemoteRepository $left, RemoteRepository $right): int => strcasecmp($left->fullName, $right->fullName) ?: strcmp($left->id, $right->id)
        );

        return $repositories;
    }

    public function getRepositoriesPage(GitSource $source, int $page = 1): RepositoryPage
    {
        $name = $this->sourceName($source);
        $this->validatePage($page);
        $organization = $source->getAccountType() === AccountType::Organization;
        $path = ($organization ? '/orgs/' : '/users/').$name.'/repos';
        $paths = [$path];

        if ($organization && preg_match('/\A[1-9][0-9]*\z/', $source->getRemoteId()) === 1) {
            $paths[] = '/organizations/'.$source->getRemoteId().'/repos';
        }

        $query = [
            'type' => $organization ? 'public' : 'owner',
            'per_page' => '100',
            'sort' => 'full_name',
            'direction' => 'asc',
        ];
        $response = $this->get(self::BASE_URL.$path.'?'.http_build_query([...$query, 'page' => $page]));
        $repositories = [];

        foreach ($this->list($response) as $item) {
            $data = $this->object($item);
            $repository = $this->repository($data);
            $owner = $this->object($data['owner'] ?? null);

            if (! $this->boolean($data, 'private') && strcasecmp($this->string($owner, 'login'), $name) === 0) {
                $repositories[$repository->id] = $repository;
            }
        }

        return new RepositoryPage(
            repositories: array_values($repositories),
            nextPage: $this->nextPage($response->header('Link'), $paths, $query, $page),
        );
    }

    public function getRepositoryDetails(GitSource $source, string $name): RepositoryDetails
    {
        $data = $this->object($this->decode($this->get(self::BASE_URL.$this->repositoryPath($source, $name), repositoryRequest: true)));
        $repository = $this->repository($data);
        $owner = $this->object($data['owner'] ?? null);
        $ownerId = $this->integer($owner, 'id');
        $ownerName = $this->string($owner, 'login');

        if ($ownerId === 0 || ! $this->isValidAccountName($ownerName)) {
            throw new InvalidResponseException('GitHub returned an invalid repository owner.', 200);
        }

        return new RepositoryDetails(
            id: $repository->id,
            name: $repository->name,
            description: $repository->description,
            stars: $repository->stars,
            forks: $repository->forks,
            language: $repository->language,
            archived: $repository->archived,
            openIssuesCount: $this->integer($data, 'open_issues_count'),
            ownerId: (string) $ownerId,
            ownerName: $ownerName,
            isPrivate: $this->boolean($data, 'private'),
        );
    }

    public function getPullRequestsPage(GitSource $source, string $name, string $externalId, int $page = 1, int $perPage = 1): PullRequestPage
    {
        $this->validatePage($page);

        if (! in_array($perPage, [1, 100], true) || preg_match('/\Agithub:([1-9][0-9]*)\z/', $externalId, $identity) !== 1) {
            throw new InvalidArgumentException('Invalid GitHub pull request pagination parameters.');
        }

        $path = $this->repositoryPath($source, $name).'/pulls';
        $query = ['state' => 'open', 'per_page' => (string) $perPage];
        $response = $this->get(self::BASE_URL.$path.'?'.http_build_query([...$query, 'page' => $page]), repositoryRequest: true);
        $items = $this->list($response);

        if (count($items) > $perPage) {
            throw new InvalidResponseException('GitHub returned too many pull requests for one page.', 200);
        }

        foreach ($items as $item) {
            $pullRequest = $this->object($item);

            if ($this->integer($pullRequest, 'id') === 0 || $this->string($pullRequest, 'state') !== 'open') {
                throw new InvalidResponseException('GitHub returned an invalid open pull request.', 200);
            }
        }

        $links = $this->paginationLinks($response->header('Link'), [$path, '/repositories/'.$identity[1].'/pulls'], $query);
        $last = $links['last'] ?? null;
        $next = $links['next'] ?? ($last !== null && $last > $page ? $page + 1 : null);

        if (($next !== null && $next !== $page + 1)
            || ($last !== null && $last < $page)
            || ($next !== null && $last !== null && $last < $next)
            || ($next !== null && count($items) !== $perPage)) {
            throw new InvalidResponseException('GitHub returned inconsistent pull request pagination.', 200);
        }

        $total = null;

        if ($page === 1 && $next === null) {
            $total = count($items);
        } elseif ($perPage === 1 && $last !== null) {
            $total = $last;
        }

        return new PullRequestPage(count($items), $total, $next);
    }

    public function getLastCommitAt(GitSource $source, string $name): ?DateTimeImmutable
    {
        $response = $this->get(self::BASE_URL.$this->repositoryPath($source, $name).'/commits?per_page=1', allowEmptyRepository: true, repositoryRequest: true);

        if ($response->status() === 409) {
            return null;
        }

        $items = $this->list($response);

        if ($items === []) {
            return null;
        }

        if (count($items) !== 1) {
            throw new InvalidResponseException('GitHub returned an invalid latest commit list.', 200);
        }

        $commit = $this->object($this->object($items[0])['commit'] ?? null);
        $committer = $this->object($commit['committer'] ?? null);
        $date = $this->string($committer, 'date');
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $date);

        if ($timestamp === false || DateTimeImmutable::getLastErrors() !== false
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $date) !== 1) {
            throw new InvalidResponseException('GitHub returned an invalid commit timestamp.', 200);
        }

        return $timestamp;
    }

    private function sourceName(GitSource $source): string
    {
        if ($source->getProvider() !== $this) {
            throw new InvalidArgumentException('The Git source belongs to a different provider instance.');
        }

        return $this->normalizeName($source->getName());
    }

    private function repositoryPath(GitSource $source, string $name): string
    {
        $owner = $this->sourceName($source);

        if (preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/', $name) !== 1 || in_array($name, ['.', '..'], true)) {
            throw new InvalidArgumentException('Invalid GitHub repository name.');
        }

        return '/repos/'.$owner.'/'.rawurlencode($name);
    }

    private function validatePage(int $page): void
    {
        if ($page < 1 || $page === PHP_INT_MAX) {
            throw new InvalidArgumentException('A GitHub page must be a positive integer.');
        }
    }

    /** @return list<mixed> */
    private function list(Response $response): array
    {
        $items = $this->decode($response);

        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidResponseException('GitHub returned an invalid list.', 200);
        }

        return $items;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if (! $this->isValidAccountName($name)) {
            throw new InvalidArgumentException('A GitHub account name must contain 1 to 39 alphanumeric characters or single hyphens, without a leading or trailing hyphen.');
        }

        return $name;
    }

    private function get(string $url, bool $allowEmptyRepository = false, bool $repositoryRequest = false): Response
    {
        $configuredToken = config('services.github.pat');
        $token = is_string($configuredToken) ? trim($configuredToken) : '';

        if (preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new AuthenticationException('GitHub authentication could not be configured.', 401);
        }

        $cooldownKey = 'github:cooldown:'.hash('sha256', $token === '' ? 'anonymous' : 'pat:'.$token);
        $retryTimestamp = Cache::get($cooldownKey);

        if (is_int($retryTimestamp) && $retryTimestamp > now()->getTimestamp()) {
            throw new RateLimitException('The GitHub API rate limit was reached.', (new DateTimeImmutable)->setTimestamp($retryTimestamp), 429);
        }

        try {
            $request = $this->http->createPendingRequest()
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'sportmate-interview',
                    'X-GitHub-Api-Version' => '2026-03-10',
                ])
                ->connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting();

            if ($token !== '') {
                $request->withToken($token);
            }

            $response = $request->get($url);
        } catch (ConnectionException $exception) {
            // A transport exception can retain a request containing the bearer token.
            $cause = $token === '' ? $exception : new ConnectionException('Unable to connect to GitHub.');

            throw new GitProviderException('Unable to connect to GitHub.', previous: $cause);
        }

        if ($response->status() === 404) {
            throw new SourceNotFoundException('The GitHub source was not found.', 404);
        }

        if ($repositoryRequest && in_array($response->status(), [301, 308], true)) {
            // Skip names changed since discovery; the next sync discovers the current name.
            throw new SourceNotFoundException('The GitHub repository moved.', $response->status());
        }

        if ($this->isRateLimited($response)) {
            $retryAt = $this->retryAt($response);
            $this->rememberCooldown($cooldownKey, $retryAt);

            throw new RateLimitException('The GitHub API rate limit was reached.', $retryAt, $response->status());
        }

        if ($response->status() === 401) {
            throw new AuthenticationException('GitHub authentication failed.', 401);
        }

        if ($response->status() === 403) {
            throw new AccessDeniedException('GitHub denied access to the requested resource.', 403);
        }

        if ($response->header('X-RateLimit-Remaining') === '0') {
            $this->rememberCooldown($cooldownKey, $this->retryAt($response));
        }

        if ($allowEmptyRepository && $response->status() === 409
            && $response->json('message', flags: 0) === 'Git Repository is empty.') {
            return $response;
        }

        if ($response->status() !== 200) {
            throw new GitProviderException('GitHub returned an unexpected HTTP status.', $response->status());
        }

        return $response;
    }

    private function rememberCooldown(string $key, ?DateTimeImmutable $retryAt): void
    {
        $until = max(now()->getTimestamp() + 1, $retryAt?->getTimestamp() ?? now()->getTimestamp() + 60);

        Cache::lock($key.':lock', 5)->block(1, function () use ($key, $until): void {
            $existing = Cache::get($key);
            $until = is_int($existing) ? max($existing, $until) : $until;

            Cache::put($key, $until, (new DateTimeImmutable)->setTimestamp($until));
        });
    }

    private function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        if ($response->status() !== 403) {
            return false;
        }

        $message = $response->json('message', flags: 0);

        return $response->header('X-RateLimit-Remaining') === '0'
            || $response->header('Retry-After') !== ''
            || (is_string($message) && preg_match('/rate limit|abuse detection/i', $message) === 1);
    }

    private function retryAt(Response $response): ?DateTimeImmutable
    {
        $retryAfter = $response->header('Retry-After');
        $now = DateTimeImmutable::createFromInterface(now());
        $seconds = filter_var($retryAfter, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX - $now->getTimestamp()],
        ]);

        if ($seconds !== false) {
            return $now->setTimestamp($now->getTimestamp() + $seconds);
        }

        if ($retryAfter !== '') {
            $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \\G\\M\\T', $retryAfter, new DateTimeZone('UTC'));

            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }
        }

        $reset = filter_var($response->header('X-RateLimit-Reset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $reset === false ? null : (new DateTimeImmutable)->setTimestamp($reset);
    }

    private function decode(Response $response): mixed
    {
        try {
            return json_decode($response->body(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidResponseException('GitHub returned invalid JSON.', $response->status(), $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            throw new InvalidResponseException('GitHub returned an invalid object.', 200);
        }

        return get_object_vars($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function repository(array $data): GitHubRepository
    {
        $id = $this->integer($data, 'id');

        if ($id === 0) {
            throw new InvalidResponseException('GitHub returned an invalid repository identifier.', 200);
        }

        return new GitHubRepository(
            id: 'github:'.$id,
            name: $this->string($data, 'name'),
            fullName: $this->string($data, 'full_name'),
            url: $this->string($data, 'html_url'),
            description: $this->nullableString($data, 'description'),
            stars: $this->integer($data, 'stargazers_count'),
            forks: $this->integer($data, 'forks_count'),
            language: $this->nullableString($data, 'language'),
            archived: $this->boolean($data, 'archived'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidResponseException("GitHub returned an invalid {$key} field.", 200);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nullableString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || ($data[$key] !== null && ! is_string($data[$key]))) {
            throw new InvalidResponseException("GitHub returned an invalid {$key} field.", 200);
        }

        return $data[$key];
    }

    private function httpsUrl(mixed $value, string $key): string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidResponseException("GitHub returned an invalid {$key} field.", 200);
        }

        $url = parse_url($value);

        if ($url === false
            || ($url['scheme'] ?? null) !== 'https'
            || isset($url['user']) || isset($url['pass'])) {
            throw new InvalidResponseException("GitHub returned an invalid {$key} field.", 200);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidResponseException("GitHub returned an invalid {$key} field.", 200);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidResponseException("GitHub returned an invalid {$key} field.", 200);
        }

        return $value;
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, string>  $query
     */
    private function nextPage(string $header, array $paths, array $query, int $currentPage): ?int
    {
        $nextPage = $this->paginationLinks($header, $paths, $query)['next'] ?? null;

        if ($nextPage !== null && $nextPage !== $currentPage + 1) {
            throw new InvalidResponseException('GitHub returned a repeated or invalid next page.', 200);
        }

        return $nextPage;
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, string>  $query
     * @return array<string, int>
     */
    private function paginationLinks(string $header, array $paths, array $query): array
    {
        if ($header === '') {
            return [];
        }

        $links = [];

        foreach (explode(',', $header) as $link) {
            if (preg_match('/\A\s*<([^<>\s]+)>\s*;\s*rel="([a-z ]+)"\s*\z/', $link, $matches) !== 1) {
                throw new InvalidResponseException('GitHub returned a malformed pagination link.', 200);
            }

            $url = parse_url($matches[1]);

            if ($url === false
                || ($url['scheme'] ?? null) !== 'https'
                || ($url['host'] ?? null) !== 'api.github.com'
                || ! in_array($url['path'] ?? null, $paths, true)
                || isset($url['port']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
                throw new InvalidResponseException('GitHub returned an invalid pagination destination.', 200);
            }

            parse_str($url['query'] ?? '', $parameters);

            foreach ($parameters as $key => $value) {
                if ($key !== 'page' && (! isset($query[$key]) || $query[$key] !== $value)) {
                    throw new InvalidResponseException('GitHub changed the repository pagination filters.', 200);
                }
            }

            $page = $parameters['page'] ?? null;
            $number = is_string($page) ? filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

            if ($number === false || $number === PHP_INT_MAX) {
                throw new InvalidResponseException('GitHub returned an invalid page number.', 200);
            }

            foreach (explode(' ', $matches[2]) as $relation) {
                if (isset($links[$relation])) {
                    throw new InvalidResponseException('GitHub returned duplicate pagination links.', 200);
                }

                $links[$relation] = $number;
            }
        }

        return $links;
    }
}
