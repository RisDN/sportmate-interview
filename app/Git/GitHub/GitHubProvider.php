<?php

namespace App\Git\GitHub;

use App\Git\AccountType;
use App\Git\Exceptions\GitProviderException;
use App\Git\Exceptions\InvalidResponseException;
use App\Git\Exceptions\RateLimitException;
use App\Git\Exceptions\SourceNotFoundException;
use App\Git\GitProvider;
use App\Git\GitSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
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
     * @return list<GitHubRepository>
     */
    public function getRepositories(GitSource $source): array
    {
        if ($source->getProvider() !== $this) {
            throw new InvalidArgumentException('The Git source belongs to a different provider instance.');
        }

        $name = $this->normalizeName($source->getName());
        $organization = $source->getAccountType() === AccountType::Organization;
        $path = ($organization ? '/orgs/' : '/users/').$name.'/repos';
        $query = [
            'type' => $organization ? 'public' : 'owner',
            'per_page' => '100',
            'sort' => 'full_name',
            'direction' => 'asc',
        ];
        $page = 1;
        $repositories = [];

        do {
            $url = self::BASE_URL.$path.'?'.http_build_query([...$query, 'page' => $page]);
            $response = $this->get($url);
            $items = $this->decode($response);

            if (! is_array($items) || ! array_is_list($items)) {
                throw new InvalidResponseException('GitHub returned an invalid repository list.', 200);
            }

            foreach ($items as $item) {
                $data = $this->object($item);
                $repository = $this->repository($data);
                $owner = $this->object($data['owner'] ?? null);
                $ownerName = $this->string($owner, 'login');

                if (! $this->boolean($data, 'private') && strcasecmp($ownerName, $name) === 0) {
                    $repositories[$repository->id] = $repository;
                }
            }

            $page = $this->nextPage($response->header('Link'), $path, $query, $page);
        } while ($page !== null);

        $repositories = array_values($repositories);
        usort($repositories, fn (GitHubRepository $left, GitHubRepository $right): int => strcasecmp($left->fullName, $right->fullName) ?: strcmp($left->id, $right->id)
        );

        return $repositories;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if (! $this->isValidAccountName($name)) {
            throw new InvalidArgumentException('A GitHub account name must contain 1 to 39 alphanumeric characters or single hyphens, without a leading or trailing hyphen.');
        }

        return $name;
    }

    private function get(string $url): Response
    {
        try {
            $response = $this->http->createPendingRequest()
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'sportmate-interview',
                    'X-GitHub-Api-Version' => '2026-03-10',
                ])
                ->connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new GitProviderException('Unable to connect to GitHub.', previous: $exception);
        }

        if ($response->status() === 404) {
            throw new SourceNotFoundException('The GitHub source was not found.', 404);
        }

        if ($this->isRateLimited($response)) {
            throw new RateLimitException('The GitHub API rate limit was reached.', $this->retryAt($response), $response->status());
        }

        if ($response->status() !== 200) {
            throw new GitProviderException('GitHub returned an unexpected HTTP status.', $response->status());
        }

        return $response;
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
     * @param  array<string, string>  $query
     */
    private function nextPage(string $header, string $path, array $query, int $currentPage): ?int
    {
        if ($header === '') {
            return null;
        }

        $nextPage = null;

        foreach (explode(',', $header) as $link) {
            if (preg_match('/\A\s*<([^<>\s]+)>\s*;\s*rel="([a-z ]+)"\s*\z/', $link, $matches) !== 1) {
                throw new InvalidResponseException('GitHub returned a malformed pagination link.', 200);
            }

            if (! in_array('next', explode(' ', $matches[2]), true)) {
                continue;
            }

            if ($nextPage !== null) {
                throw new InvalidResponseException('GitHub returned multiple next-page links.', 200);
            }

            $url = parse_url($matches[1]);

            if ($url === false
                || ($url['scheme'] ?? null) !== 'https'
                || ($url['host'] ?? null) !== 'api.github.com'
                || ($url['path'] ?? null) !== $path
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

            if ($number === false || $number !== $currentPage + 1) {
                throw new InvalidResponseException('GitHub returned a repeated or invalid next page.', 200);
            }

            $nextPage = $number;
        }

        return $nextPage;
    }
}
