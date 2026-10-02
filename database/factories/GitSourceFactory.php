<?php

namespace Database\Factories;

use App\Git\AccountType;
use App\Models\GitSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GitSource> */
class GitSourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $account = 'source-'.fake()->unique()->numberBetween(1, 99999999);

        return [
            'provider' => 'github',
            'remote_id' => (string) fake()->unique()->numberBetween(1, 99999999),
            'account' => $account,
            'normalized_account' => $account,
            'name' => fake()->name(),
            'url' => 'https://github.com/'.$account,
            'avatar_url' => null,
            'account_type' => AccountType::User,
            'last_synced_at' => null,
        ];
    }
}
