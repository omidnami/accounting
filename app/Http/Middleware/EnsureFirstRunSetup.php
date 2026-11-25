<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFirstRunSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (User::query()->exists()) {
            abort(404);
        }

        $requiredToken = (string) config('app.setup_token', '');

        if ($requiredToken !== '' && ! hash_equals($requiredToken, (string) $request->query('token', ''))) {
            abort(403, 'Setup token is invalid.');
        }

        if ($requiredToken === '' && app()->environment('production')) {
            abort(503, 'Configure SETUP_TOKEN before opening the first-run setup.');
        }

        return $next($request);
    }
}
