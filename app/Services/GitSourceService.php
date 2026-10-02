<?php

namespace App\Services;

use App\Git\Exceptions\SourceNotFoundException;
use App\Models\GitSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class GitSourceService
{
    public const PER_PAGE = 10;

    public function __construct(private GitProviderRegistry $providers, private GitSourceSyncService $sync) {}

    /** @return LengthAwarePaginator<int, GitSource> */
    public function paginate(int $page): LengthAwarePaginator
    {
        $total = GitSource::query()->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        return GitSource::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, page: min(max(1, $page), $lastPage), total: $total);
    }

    public function create(string $providerKey, string $account): GitSource
    {
        $provider = $this->providers->get($providerKey);

        try {
            $remote = $provider->getSource($account);
        } catch (SourceNotFoundException) {
            throw ValidationException::withMessages(['account' => 'create.notFound']);
        }

        $normalizedAccount = mb_strtolower($remote->getName());
        $duplicate = GitSource::query()
            ->where('provider', $provider->getKey())
            ->where(fn (Builder $query) => $query
                ->where('remote_id', $remote->getRemoteId())
                ->orWhere('normalized_account', $normalizedAccount));

        if ($duplicate->exists()) {
            throw ValidationException::withMessages(['account' => 'create.duplicate']);
        }

        try {
            return DB::transaction(function () use ($provider, $remote, $normalizedAccount): GitSource {
                $source = GitSource::query()->create([
                    'provider' => $provider->getKey(),
                    'remote_id' => $remote->getRemoteId(),
                    'account' => $remote->getName(),
                    'normalized_account' => $normalizedAccount,
                    'name' => $remote->getDisplayName(),
                    'url' => $remote->getUrl(),
                    'avatar_url' => $remote->getAvatarUrl(),
                    'account_type' => $remote->getAccountType(),
                    'last_synced_at' => null,
                ]);

                return $this->sync->start($source);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['account' => 'create.duplicate']);
        }
    }
}
