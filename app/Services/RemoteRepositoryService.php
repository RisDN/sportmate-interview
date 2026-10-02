<?php

namespace App\Services;

use App\Models\GitSource;
use App\Models\RemoteRepository;
use Illuminate\Pagination\LengthAwarePaginator;

final class RemoteRepositoryService
{
    public const PER_PAGE = 10;

    /** @return LengthAwarePaginator<int, RemoteRepository> */
    public function paginate(GitSource $source, int $page): LengthAwarePaginator
    {
        $total = $source->repositories()->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $repositories = $source->repositories()
            ->orderBy('name')
            ->orderBy('external_id')
            ->paginate(self::PER_PAGE, page: min(max(1, $page), $lastPage), total: $total);

        foreach ($repositories as $repository) {
            $repository->setRelation('owner', $source);
        }

        return $repositories;
    }
}
