<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Git\AccountType;
use Carbon\CarbonImmutable;
use Database\Factories\GitSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $provider
 * @property string $remote_id
 * @property string $account
 * @property string $normalized_account
 * @property string $name
 * @property string $normalized_name
 * @property string $url
 * @property string|null $avatar_url
 * @property AccountType $account_type
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $marked_for_deletion_at
 * @property SyncStatus $sync_status
 * @property string|null $last_sync_error_code
 * @property CarbonImmutable|null $last_sync_error_at
 * @property CarbonImmutable|null $sync_retry_at
 * @property string|null $sync_run_id
 * @property int $sync_revision
 * @property int $repositories_revision
 * @property array<string, mixed>|null $sync_checkpoint
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider', 'remote_id', 'account', 'normalized_account', 'name', 'url', 'avatar_url', 'account_type', 'last_synced_at', 'sync_status', 'last_sync_error_code', 'last_sync_error_at', 'sync_retry_at', 'sync_run_id', 'sync_revision', 'sync_checkpoint'])]
class GitSource extends Model
{
    /** @use HasFactory<GitSourceFactory> */
    use HasFactory;

    protected $attributes = ['sync_status' => 'idle', 'sync_revision' => 0, 'repositories_revision' => 0];

    protected $hidden = ['sync_run_id', 'sync_checkpoint', 'repositories_revision'];

    /** @return Attribute<string, string> */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (string $value): array => [
            'name' => $value,
            'normalized_name' => mb_strtolower($value),
        ]);
    }

    /** @param Builder<GitSource> $query
     * @return Builder<GitSource>
     */
    #[Scope]
    protected function available(Builder $query): Builder
    {
        return $query->whereNull('marked_for_deletion_at');
    }

    /** @return HasMany<RemoteRepository, $this> */
    public function repositories(): HasMany
    {
        return $this->hasMany(RemoteRepository::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'last_synced_at' => 'immutable_datetime',
            'marked_for_deletion_at' => 'immutable_datetime',
            'sync_status' => SyncStatus::class,
            'last_sync_error_at' => 'immutable_datetime',
            'sync_retry_at' => 'immutable_datetime',
            'sync_revision' => 'integer',
            'repositories_revision' => 'integer',
            'sync_checkpoint' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
