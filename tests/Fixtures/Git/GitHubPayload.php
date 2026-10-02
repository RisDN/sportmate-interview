<?php

namespace Tests\Fixtures\Git;

final class GitHubPayload
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function repository(array $overrides = []): array
    {
        return array_replace([
            'id' => 123,
            'name' => 'framework',
            'full_name' => 'laravel/framework',
            'html_url' => 'https://github.com/laravel/framework',
            'description' => 'The Laravel Framework.',
            'stargazers_count' => 35000,
            'forks_count' => 12000,
            'language' => 'PHP',
            'archived' => false,
            'private' => false,
            'fork' => false,
            'owner' => ['login' => 'laravel'],
        ], $overrides);
    }
}
