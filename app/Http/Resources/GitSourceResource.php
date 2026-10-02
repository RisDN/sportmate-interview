<?php

namespace App\Http\Resources;

use App\Models\GitSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GitSource */
class GitSourceResource extends JsonResource
{
    /** @return array<string, string|null> */
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
            'last_synced_at' => $this->last_synced_at?->toISOString(),
        ];
    }
}
