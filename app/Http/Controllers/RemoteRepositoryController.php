<?php

namespace App\Http\Controllers;

use App\Http\Resources\RemoteRepositoryResource;
use App\Models\GitSource;
use App\Services\RemoteRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RemoteRepositoryController extends Controller
{
    public function index(Request $request, GitSource $gitSource, RemoteRepositoryService $repositories): JsonResponse
    {
        $value = $request->query('page');
        $page = is_string($value) && ctype_digit($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $repositories = $repositories->paginate($gitSource, $page === false ? 1 : $page);

        return response()->json([
            'data' => RemoteRepositoryResource::collection($repositories->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $repositories->currentPage(),
                'last_page' => $repositories->lastPage(),
                'per_page' => $repositories->perPage(),
                'total' => $repositories->total(),
            ],
        ]);
    }
}
