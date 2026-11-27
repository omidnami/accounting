<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $local = app()->environment(['local', 'development']);
        $viteHttp = $local ? ' http://localhost:5173 http://127.0.0.1:5173' : '';
        $viteWebSocket = $local ? ' ws://localhost:5173 ws://127.0.0.1:5173' : '';
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'unsafe-inline'{$viteHttp}; style-src 'self' 'unsafe-inline'{$viteHttp}; img-src 'self' data: blob:{$viteHttp}; font-src 'self' data:{$viteHttp}; connect-src 'self'{$viteHttp}{$viteWebSocket}; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );

        return $response;
    }
}
