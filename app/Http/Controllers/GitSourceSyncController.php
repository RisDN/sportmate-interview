<?php

namespace App\Http\Controllers;

use App\Http\Resources\GitSourceResource;
use App\Models\GitSource;
use App\Services\GitSourceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitSourceSyncController extends Controller
{
    public function store(Request $request, GitSource $gitSource, GitSourceSyncService $sync): JsonResponse
    {
        $source = $sync->start($gitSource);

        return response()->json(['data' => (new GitSourceResource($source))->resolve($request)], 202);
    }
}
