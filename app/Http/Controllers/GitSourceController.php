<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGitSourceRequest;
use App\Http\Resources\GitSourceResource;
use App\Http\Resources\RemoteRepositoryPageResource;
use App\Models\GitSource;
use App\Services\GitSourceService;
use App\Services\RemoteRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GitSourceController extends Controller
{
    public const PAGE_COOKIE = 'git_sources_page';

    public function show(Request $request, GitSource $gitSource, RemoteRepositoryService $repositories): JsonResponse
    {
        return DB::transaction(function () use ($request, $gitSource, $repositories): JsonResponse {
            $source = $gitSource->fresh();
            abort_if($source === null || $source->marked_for_deletion_at !== null, 404);
            $response = ['data' => (new GitSourceResource($source))->resolve($request)];

            if ($request->query->has('repository_page')) {
                $value = $request->query('repository_page');
                $page = is_string($value) && ctype_digit($value)
                    ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;
                $snapshot = (new RemoteRepositoryPageResource($repositories->paginate($source, $page === false ? 1 : $page)))->toArray($request);
                $response['repositories'] = ['fingerprint' => $snapshot['fingerprint'], 'meta' => $snapshot['meta']];
            }

            return response()->json($response);
        });
    }

    public function index(Request $request, GitSourceService $sources): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = $request->string('search')->trim()->toString();
        $value = $request->query->has('page') ? $request->query('page') : ($search === '' ? $request->cookie(self::PAGE_COOKIE) : '1');
        $page = is_string($value) && ctype_digit($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $sources = $sources->paginate($page === false ? 1 : $page, $search);

        $response = response()->json([
            'data' => GitSourceResource::collection($sources->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $sources->currentPage(),
                'last_page' => $sources->lastPage(),
                'per_page' => $sources->perPage(),
                'total' => $sources->total(),
            ],
        ]);

        if ($search !== '') {
            return $response;
        }

        return $response->withCookie(cookie(
            self::PAGE_COOKIE,
            (string) $sources->currentPage(),
            60 * 24 * 365,
            '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    public function store(StoreGitSourceRequest $request, GitSourceService $sources): JsonResponse
    {
        $source = $sources->create($request->string('provider')->toString(), $request->string('account')->toString());

        return response()->json(['data' => (new GitSourceResource($source))->resolve($request)], 201);
    }

    public function destroy(Request $request, GitSource $gitSource, GitSourceService $sources): JsonResponse
    {
        $source = $sources->markForDeletion($gitSource);

        return response()->json(['data' => (new GitSourceResource($source))->resolve($request)], 202);
    }
}
