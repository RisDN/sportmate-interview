<?php

namespace App\Http\Resources;

use App\Models\RemoteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class RemoteRepositoryPageResource extends JsonResource
{
    /**
     * @param  LengthAwarePaginator<int, RemoteRepository>  $repositories
     * @param  list<string|null>  $languages
     */
    public function __construct(private LengthAwarePaginator $repositories, private array $languages)
    {
        parent::__construct($repositories);
    }

    /** @return array{data: array<mixed>, fingerprint: string, languages: list<string|null>, meta: array{current_page: int, last_page: int, per_page: int, total: int}} */
    public function toArray(Request $request): array
    {
        $data = RemoteRepositoryResource::collection($this->repositories->getCollection())->resolve($request);

        return [
            'data' => $data,
            // Internal sync versions and pagination totals do not change the visible rows.
            'fingerprint' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
            'languages' => $this->languages,
            'meta' => [
                'current_page' => $this->repositories->currentPage(),
                'last_page' => $this->repositories->lastPage(),
                'per_page' => $this->repositories->perPage(),
                'total' => $this->repositories->total(),
            ],
        ];
    }
}
