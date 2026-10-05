<?php

namespace App\Models;

use App\Services\GitProviderRegistry;
use Carbon\CarbonImmutable;
use Database\Factories\RemoteRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $external_id
 * @property int $git_source_id
 * @property string $name
 * @property string $normalized_name
 * @property string|null $description
 * @property string|null $normalized_description
 * @property int $stars_count
 * @property int $issues_count
 * @property int $pull_requests_count
 * @property int $forks_count
 * @property string|null $language
 * @property bool $archived
 * @property CarbonImmutable|null $last_committed_at
 * @property int $sync_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GitSource $owner
 */
#[Fillable(['external_id', 'git_source_id', 'name', 'description', 'stars_count', 'issues_count', 'pull_requests_count', 'forks_count', 'language', 'archived', 'last_committed_at', 'sync_version'])]
class RemoteRepository extends Model
{
    /** @use HasFactory<RemoteRepositoryFactory> */
    use HasFactory;

    protected $primaryKey = 'external_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['sync_version', 'normalized_name', 'normalized_description'];

    /** @return Attribute<string, string> */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (string $value): array => [
            'name' => $value,
            'normalized_name' => mb_strtolower($value),
        ]);
    }

    /** @return Attribute<string|null, string|null> */
    protected function description(): Attribute
    {
        return Attribute::make(set: fn (?string $value): array => [
            'description' => $value,
            'normalized_description' => $value === null ? null : mb_strtolower($value),
        ]);
    }

    /** @return BelongsTo<GitSource, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(GitSource::class, 'git_source_id');
    }

    public function getUrl(): string
    {
        $provider = app(GitProviderRegistry::class)->get($this->owner->provider);
        $account = implode('/', array_map(rawurlencode(...), explode('/', $this->owner->account)));

        return rtrim($provider->getUrlPrefix(), '/').'/'.$account.'/'.rawurlencode($this->name);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'git_source_id' => 'integer',
            'stars_count' => 'integer',
            'issues_count' => 'integer',
            'pull_requests_count' => 'integer',
            'forks_count' => 'integer',
            'archived' => 'boolean',
            'last_committed_at' => 'immutable_datetime',
            'sync_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
