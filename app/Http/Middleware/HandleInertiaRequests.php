<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $theme = $request->cookie('theme');

        return [
            ...parent::share($request),
            'theme' => in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system',
        ];
    }
}
