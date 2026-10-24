<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActiveBranchController extends Controller
{
    public function update(
        Request $request,
        BranchContextService $context,
    ): RedirectResponse {
        abort_unless($request->user()?->canSwitchBranches(), 403);
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $validated = $request->validate([
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')
                    ->where('store_id', $store->id)
                    ->where('is_active', true),
            ],
        ]);
        $branch = Branch::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->findOrFail((int) $validated['branch_id']);

        try {
            $context->switch($request->user(), $branch);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'branch_id' => $exception->getMessage(),
            ]);
        }

        return to_route('dashboard');
    }
}
