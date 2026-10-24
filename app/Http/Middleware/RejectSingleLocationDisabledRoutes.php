<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectSingleLocationDisabledRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        abort(404);
    }
}
