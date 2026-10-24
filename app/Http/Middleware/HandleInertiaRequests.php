<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\Branches\BranchContextService;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $branchContext = null;

        if ($request->user() !== null) {
            $store = Store::query()->where('code', 'MAIN')->first();

            if ($store !== null) {
                try {
                    $branchContext = app(BranchContextService::class)
                        ->shared($request->user(), $store);
                } catch (DomainException) {
                    $branchContext = null;
                }
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'branch_context' => $branchContext,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
