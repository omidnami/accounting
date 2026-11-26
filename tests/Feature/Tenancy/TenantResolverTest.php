<?php

use App\Http\Middleware\ResolveTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function createTenantSqliteDatabase(string $path, string $storeCode): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE stores (id INTEGER PRIMARY KEY, code TEXT, name TEXT)');
    $statement = $pdo->prepare('INSERT INTO stores (code, name) VALUES (?, ?)');
    $statement->execute([$storeCode, $storeCode]);
}

it('switches to the tenant database before authentication and session middleware', function () {
    $originalDefault = config('database.default');
    $directory = sys_get_temp_dir().'/accounting-tenant-test-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    $database = $directory.'/tenant.sqlite';
    createTenantSqliteDatabase($database, 'TENANT-STORE');
    config([
        'tenancy.enabled' => true,
        'tenancy.connection' => 'sqlite',
        'tenancy.central_domains' => ['central.test'],
        'database.connections.sqlite.database' => $database,
    ]);
    $tenant = Tenant::query()->create([
        'code' => 'acme',
        'subdomain' => 'acme',
        'database_name' => $database,
        'status' => 'active',
        'timezone' => 'Asia/Tehran',
    ]);
    $request = Request::create('http://acme.test/dashboard');
    $request->headers->set('Host', 'acme.test');
    $context = app(TenantContext::class);

    try {
        $response = app(ResolveTenant::class)->handle(
            $request,
            fn () => response()->json([
                'connection' => DB::connection()->getName(),
                'store' => Store::query()->firstOrFail()->code,
            ]),
        );

        expect($response->getData(true))->toBe([
            'connection' => 'tenant',
            'store' => 'TENANT-STORE',
        ])
            ->and($context->tenant?->id)->toBe($tenant->id)
            ->and(config('session.cookie'))->toBe('tenant_'.$tenant->id.'_session');
    } finally {
        $context->clear();
        config(['database.default' => $originalDefault, 'tenancy.connection' => null]);
        DB::purge('tenant');
        File::deleteDirectory($directory);
        File::deleteDirectory(storage_path('tenants/'.$tenant->id));
    }
});

it('keeps the central database for central domains', function () {
    $originalDefault = config('database.default');
    config([
        'tenancy.enabled' => true,
        'tenancy.connection' => null,
        'tenancy.central_domains' => ['central.test'],
    ]);
    $request = Request::create('http://central.test/dashboard');
    $request->headers->set('Host', 'central.test');

    $response = app(ResolveTenant::class)->handle(
        $request,
        fn () => response()->json(['connection' => DB::connection()->getName()]),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['connection'])->toBe($originalDefault)
        ->and(app(TenantContext::class)->tenant)->toBeNull();
});
