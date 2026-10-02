<?php

namespace App\Http\Resources;

use App\Models\RemoteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RemoteRepository */
class RemoteRepositoryResource extends JsonResource
{
    /** @return array<string, bool|int|string|null> */
    public function toArray(Request $request): array
    {
        return [
            'external_id' => $this->external_id,
            'git_source_id' => (string) $this->git_source_id,
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->getUrl(),
            'stars_count' => $this->stars_count,
            'issues_count' => $this->issues_count,
            'pull_requests_count' => $this->pull_requests_count,
            'forks_count' => $this->forks_count,
            'language' => $this->language,
            'archived' => $this->archived,
            'last_committed_at' => $this->last_committed_at?->getTimestamp(),
        ];
    }
}
