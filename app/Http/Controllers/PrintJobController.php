<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Printing\PrintJobService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PrintJobController extends Controller
{
    public function queueSales(
        Request $request,
        Invoice $invoice,
        PrintJobService $jobs,
    ): RedirectResponse {
        abort_unless($request->user()?->canManageSales(), 403);
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $branch = app(BranchContextService::class)->active($request->user(), $store);
        abort_unless(
            $invoice->type === InvoiceType::Sales
            && (int) $invoice->store_id === (int) $store->id
            && (int) $invoice->branch_id === (int) $branch->id,
            404,
        );

        try {
            $jobs->queueSalesInvoice($invoice);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'print' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'فاکتور به صف Print Agent ارسال شد.',
        ]);

        return back();
    }
}
