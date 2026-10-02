<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGitSourceRequest;
use App\Http\Resources\GitSourceResource;
use App\Services\GitSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitSourceController extends Controller
{
    public const PAGE_COOKIE = 'git_sources_page';

    public function index(Request $request, GitSourceService $sources): JsonResponse
    {
        $value = $request->query->has('page') ? $request->query('page') : $request->cookie(self::PAGE_COOKIE);
        $page = is_string($value) && ctype_digit($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $sources = $sources->paginate($page === false ? 1 : $page);

        return response()->json([
            'data' => GitSourceResource::collection($sources->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $sources->currentPage(),
                'last_page' => $sources->lastPage(),
                'per_page' => $sources->perPage(),
                'total' => $sources->total(),
            ],
        ])->withCookie(cookie(
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
}
