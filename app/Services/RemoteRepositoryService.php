<?php

namespace App\Services;

use App\Models\GitSource;
use App\Models\RemoteRepository;
use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RemoteRepositoryService
{
    public const PER_PAGE = 10;

    private bool $cacheUnavailable = false;

    public function __construct(private readonly Factory $cache) {}

    /**
     * @param  array{search: string, languages: list<string>, without_language: bool, sort: string, direction: 'asc'|'desc'}  $filters
     * @return LengthAwarePaginator<int, RemoteRepository>
     */
    public function paginate(GitSource $source, int $page, array $filters): LengthAwarePaginator
    {
        $filters['search'] = mb_strtolower(trim($filters['search']));
        sort($filters['languages'], SORT_STRING);
        $key = $this->cacheKey($source, ['page', $page, self::PER_PAGE, $filters]);
        $snapshot = $this->rememberAfterCommit($key, function () use ($source, $page, $filters): array {
            $repositories = $this->queryPage($source, $page, $filters);

            return [
                'rows' => $repositories->getCollection()->map(fn (RemoteRepository $repository): array => $repository->getAttributes())->all(),
                'total' => $repositories->total(),
                'current_page' => $repositories->currentPage(),
            ];
        });

        $repositories = new Collection(array_map(function (array $attributes) use ($source): RemoteRepository {
            $repository = (new RemoteRepository)->newFromBuilder($attributes);
            $repository->setRelation('owner', $source);

            return $repository;
        }, $snapshot['rows']));

        return new LengthAwarePaginator($repositories, $snapshot['total'], self::PER_PAGE, $snapshot['current_page']);
    }

    /**
     * @param  array{search: string, languages: list<string>, without_language: bool, sort: string, direction: 'asc'|'desc'}  $filters
     * @return LengthAwarePaginator<int, RemoteRepository>
     */
    private function queryPage(GitSource $source, int $page, array $filters): LengthAwarePaginator
    {
        $query = $source->repositories();

        if ($filters['search'] !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['search'])).'%';
            $query->where(fn (Builder $query) => $query
                ->whereRaw("normalized_name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("normalized_description LIKE ? ESCAPE '!'", [$pattern]));
        }

        if ($filters['languages'] !== [] || $filters['without_language']) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->whereIn('language', $filters['languages']);

                if ($filters['without_language']) {
                    $query->orWhereNull('language');
                }
            });
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        if ($filters['sort'] === 'last_committed_at') {
            $query->orderByRaw('last_committed_at IS NULL');
        }

        $query->orderBy($filters['sort'], $filters['direction']);

        if ($filters['sort'] !== 'name') {
            $query->orderBy('name');
        }

        $repositories = $query
            ->orderBy('external_id')
            ->paginate(self::PER_PAGE, page: min(max(1, $page), $lastPage), total: $total);

        return $repositories;
    }

    /** @return list<string|null> */
    public function languages(GitSource $source): array
    {
        return $this->rememberAfterCommit($this->cacheKey($source, ['languages']), function () use ($source): array {
            /** @var list<string|null> $languages */
            $languages = $source->repositories()->select('language')->distinct()
                ->orderByRaw('language IS NULL')->orderBy('language')->pluck('language')->all();

            return $languages;
        });
    }

    /** @param list<mixed> $criteria */
    private function cacheKey(GitSource $source, array $criteria): string
    {
        return 'repository-search:v1:'.hash('sha256', json_encode([
            $source->id, $source->provider, $source->account,
            $source->sync_revision, $source->repositories_revision, $criteria,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @template TValue of array
     *
     * @param  Closure(): TValue  $read
     * @return TValue
     */
    private function rememberAfterCommit(string $key, Closure $read): array
    {
        if ($this->cacheUnavailable) {
            return $read();
        }

        try {
            $store = $this->cache->store(config('repositories.cache_store', 'repository-search'));
            /** @var TValue|null $cached */
            $cached = $store->get($key);
        } catch (Throwable) {
            $this->cacheUnavailable = true;

            return $read();
        }

        if ($cached !== null) {
            return $cached;
        }

        // Query failures must still reach the normal API error handler.
        $value = $read();
        $publish = function () use ($store, $key, $value): void {
            try {
                $store->put($key, $value, (int) config('repositories.cache_ttl', 300));
            } catch (Throwable) {
                $this->cacheUnavailable = true;
            }
        };

        // A rolled-back generation may be reused; never publish its uncommitted rows.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($publish);
        } else {
            $publish();
        }

        return $value;
    }
}
