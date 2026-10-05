<?php

namespace App\Http\Controllers;

use App\Http\Requests\RepositoryFiltersRequest;
use App\Http\Resources\RemoteRepositoryPageResource;
use App\Models\GitSource;
use App\Services\RemoteRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RemoteRepositoryController extends Controller
{
    public function index(RepositoryFiltersRequest $request, GitSource $gitSource, RemoteRepositoryService $repositories): JsonResponse
    {
        return DB::transaction(function () use ($request, $gitSource, $repositories): JsonResponse {
            $source = $gitSource->fresh();
            abort_if($source === null || $source->marked_for_deletion_at !== null, 404);
            $snapshot = new RemoteRepositoryPageResource(
                $repositories->paginate($source, $request->repositoryPage(), $request->filters()),
                $repositories->languages($source),
            );

            return response()->json($snapshot->resolve($request));
        });
    }
}
