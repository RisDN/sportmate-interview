<?php

namespace App\Http\Controllers;

use App\Http\Resources\RemoteRepositoryPageResource;
use App\Models\GitSource;
use App\Services\RemoteRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RemoteRepositoryController extends Controller
{
    public function index(Request $request, GitSource $gitSource, RemoteRepositoryService $repositories): JsonResponse
    {
        $value = $request->query('page');
        $page = is_string($value) && ctype_digit($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        return DB::transaction(function () use ($request, $gitSource, $repositories, $page): JsonResponse {
            $source = $gitSource->fresh();
            abort_if($source === null || $source->marked_for_deletion_at !== null, 404);
            $snapshot = new RemoteRepositoryPageResource($repositories->paginate($source, $page === false ? 1 : $page));

            return response()->json($snapshot->resolve($request));
        });
    }
}
