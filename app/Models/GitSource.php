<?php

namespace App\Models;

use App\Git\AccountType;
use Carbon\CarbonImmutable;
use Database\Factories\GitSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $remote_id
 * @property string $account
 * @property string $normalized_account
 * @property string $name
 * @property string $url
 * @property string|null $avatar_url
 * @property AccountType $account_type
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider', 'remote_id', 'account', 'normalized_account', 'name', 'url', 'avatar_url', 'account_type', 'last_synced_at'])]
class GitSource extends Model
{
    /** @use HasFactory<GitSourceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'last_synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
