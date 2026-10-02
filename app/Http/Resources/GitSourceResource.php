<?php

namespace App\Http\Resources;

use App\Models\GitSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GitSource */
class GitSourceResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'provider' => $this->provider,
            'account' => $this->account,
            'name' => $this->name,
            'url' => $this->url,
            'avatar_url' => $this->avatar_url,
            'account_type' => $this->account_type->value,
            'last_synced_at' => $this->last_synced_at?->getTimestamp(),
            'marked_for_deletion_at' => $this->marked_for_deletion_at?->getTimestamp(),
            'sync_status' => $this->sync_status->value,
            'last_sync_error_code' => $this->last_sync_error_code,
            'last_sync_error_at' => $this->last_sync_error_at?->getTimestamp(),
            'sync_retry_at' => $this->sync_retry_at?->getTimestamp(),
            'sync_revision' => $this->sync_revision,
        ];
    }
}
