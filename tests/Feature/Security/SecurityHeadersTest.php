<?php

it('sets baseline browser security headers', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('5173');
});

it('keeps the production csp strict', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $response = $this->get('/');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'self' 'unsafe-inline';")
        ->toContain("font-src 'self' data:;")
        ->not->toContain('5173');
});

it('allows the local vite development server without weakening production csp', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    $response = $this->get('/');
    $policy = $response->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'self' 'unsafe-inline' http://localhost:5173 http://127.0.0.1:5173")
        ->toContain("style-src 'self' 'unsafe-inline' http://localhost:5173 http://127.0.0.1:5173")
        ->toContain("font-src 'self' data: http://localhost:5173 http://127.0.0.1:5173")
        ->toContain("connect-src 'self' http://localhost:5173 http://127.0.0.1:5173 ws://localhost:5173 ws://127.0.0.1:5173")
        ->toContain("frame-ancestors 'none'");
});
