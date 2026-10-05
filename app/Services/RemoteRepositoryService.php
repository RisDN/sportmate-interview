<?php

namespace App\Services;

use App\Models\GitSource;
use App\Models\RemoteRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class RemoteRepositoryService
{
    public const PER_PAGE = 10;

    /**
     * @param  array{search: string, languages: list<string>, without_language: bool, sort: string, direction: 'asc'|'desc'}  $filters
     * @return LengthAwarePaginator<int, RemoteRepository>
     */
    public function paginate(GitSource $source, int $page, array $filters): LengthAwarePaginator
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

        foreach ($repositories as $repository) {
            $repository->setRelation('owner', $source);
        }

        return $repositories;
    }

    /** @return list<string|null> */
    public function languages(GitSource $source): array
    {
        /** @var list<string|null> $languages */
        $languages = $source->repositories()->select('language')->distinct()
            ->orderByRaw('language IS NULL')->orderBy('language')->pluck('language')->all();

        return $languages;
    }
}
