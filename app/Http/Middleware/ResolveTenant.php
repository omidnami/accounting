<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.enabled')) {
            return $next($request);
        }

        $host = strtolower($request->getHost());

        if (in_array($host, array_map('strtolower', (array) config('tenancy.central_domains', [])), true)) {
            return $next($request);
        }

        $subdomain = explode('.', $host)[0];
        $tenant = Tenant::query()
            ->where('subdomain', $subdomain)
            ->where('status', 'active')
            ->first();

        abort_if($tenant === null, 404);
        $baseConnection = (string) config('database.default');
        $base = (array) config("database.connections.{$baseConnection}");
        $base['database'] = $tenant->database_name;
        config(['database.connections.tenant' => $base]);
        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
        $this->context->set($tenant);

        $root = storage_path('tenants/'.$tenant->id);
        File::ensureDirectoryExists($root.'/files');
        File::ensureDirectoryExists($root.'/logs');
        config([
            'cache.prefix' => 'tenant-'.$tenant->id,
            'session.cookie' => 'tenant_'.$tenant->id.'_session',
            'filesystems.disks.local.root' => $root.'/files',
            'filesystems.disks.public.root' => $root.'/files/public',
            'logging.channels.single.path' => $root.'/logs/laravel.log',
        ]);

        try {
            app('log')->shareContext(['tenant_id' => $tenant->id]);
        } catch (\Throwable) {
            // Logging must never prevent tenant resolution.
        }

        return $next($request);
    }
}
