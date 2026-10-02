<?php

namespace Database\Factories;

use App\Models\GitSource;
use App\Models\RemoteRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RemoteRepository> */
class RemoteRepositoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'external_id' => 'github:'.fake()->unique()->numberBetween(1, 999999999),
            'git_source_id' => GitSource::factory(),
            'name' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'stars_count' => 0,
            'issues_count' => 0,
            'pull_requests_count' => 0,
            'forks_count' => 0,
            'language' => null,
            'archived' => false,
            'last_committed_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived' => true]);
    }
}
