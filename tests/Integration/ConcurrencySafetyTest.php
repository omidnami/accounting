<?php

use App\Enums\InventoryMovementType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Invoices\InvoiceDeletionService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Sales\CustomerReceiptService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for concurrency tests.');
    }

    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrency tests require MySQL/MariaDB.');
    }

    $database = DB::connection()->getDatabaseName();

    if (! env('CI') && ! str_contains($database, 'test')) {
        $this->markTestSkipped('A dedicated test database is required.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
});

/**
 * @return array<string, mixed>
 */
function concurrencyIntegrationContext(): array
{
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $fiscalYear = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'CONCURRENCY-CUSTOMER',
        'name' => 'مشتری هم‌زمانی',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'CONCURRENCY-SUPPLIER',
        'name' => 'تأمین‌کننده هم‌زمانی',
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'CONCURRENCY-PRODUCT',
        'name' => 'کالای هم‌زمانی',
        'purchase_price' => 100,
        'sale_price' => 100,
        'tax_rate' => 5,
        'is_active' => true,
    ]);
    app(InventoryService::class)->receive(
        warehouseId: $warehouse->id,
        productId: $product->id,
        quantity: 100,
        unitCost: 100,
        movementDate: $fiscalYear->starts_on->toDateString(),
        idempotencyKey: 'concurrency-opening',
        movementType: InventoryMovementType::Opening,
    );

    return compact(
        'store',
        'branch',
        'warehouse',
        'fiscalYear',
        'manager',
        'customer',
        'supplier',
        'product',
    ) + ['date' => $fiscalYear->starts_on->toDateString()];
}

/**
 * @param  callable(string): mixed  $operation
 * @return array<string, array<string, mixed>>
 */
function runConcurrentScenario(callable $operation): array
{
    $directory = sys_get_temp_dir().'/hsabyar-concurrency-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    $go = $directory.'/go';
    $pids = [];
    DB::disconnect();

    foreach (['a', 'b'] as $slot) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Cannot fork concurrency worker.');
        }

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            try {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                DB::beginTransaction();
                DB::table('users')->value('id');
                file_put_contents($directory.'/ready-'.$slot, '1');

                while (! file_exists($go)) {
                    usleep(1000);
                }

                if ($slot === 'b') {
                    usleep(50_000);
                }

                $value = $operation($slot);
                DB::commit();
                file_put_contents($directory.'/result-'.$slot, json_encode([
                    'ok' => true,
                    'value' => is_scalar($value) ? $value : null,
                ], JSON_THROW_ON_ERROR));
            } catch (Throwable $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                file_put_contents($directory.'/result-'.$slot, json_encode([
                    'ok' => false,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR));
            }

            exit(0);
        }

        $pids[] = $pid;
    }

    $deadline = microtime(true) + 10;

    while (
        (! file_exists($directory.'/ready-a') || ! file_exists($directory.'/ready-b'))
        && microtime(true) < $deadline
    ) {
        usleep(1000);
    }

    touch($go);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();
    $results = [];

    foreach (['a', 'b'] as $slot) {
        $file = $directory.'/result-'.$slot;
        $results[$slot] = file_exists($file)
            ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)
            : ['ok' => false, 'exception' => 'MissingResult'];
    }

    foreach (glob($directory.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($directory);

    return $results;
}

function createConcurrencySale(array $context, string $key): Invoice
{
    $sales = app(SalesService::class);

    return $sales->confirm(
        $sales->createDraft(
            [
                'store_id' => $context['store']->id,
                'branch_id' => $context['branch']->id,
                'fiscal_year_id' => $context['fiscalYear']->id,
                'customer_id' => $context['customer']->id,
                'invoice_date' => $context['date'],
                'created_by' => $context['manager']->id,
            ],
            [[
                'product_id' => $context['product']->id,
                'quantity' => 1,
                'unit_price' => 100,
                'discount_amount' => 0,
                'tax_rate' => 5,
            ]],
            $key,
        ),
        $context['warehouse']->id,
        $context['manager'],
    );
}

it('prevents concurrent receipts beyond the invoice balance', function () {
    $context = concurrencyIntegrationContext();
    $invoice = createConcurrencySale($context, 'concurrent-receipt-sale');
    $results = runConcurrentScenario(function (string $slot) use ($context, $invoice): string {
        app(CustomerReceiptService::class)->receive(
            storeId: $context['store']->id,
            branchId: $context['branch']->id,
            fiscalYearId: $context['fiscalYear']->id,
            customerId: $context['customer']->id,
            receiptDate: $context['date'],
            paymentMethod: PaymentMethod::Cash,
            allocations: [['invoice_id' => $invoice->id, 'amount' => 60]],
            approver: $context['manager'],
            idempotencyKey: 'concurrent-receipt-'.$slot,
        );

        return $slot;
    });
    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false))->toHaveCount(1)
        ->and(Receipt::query()->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid);
});

it('prevents concurrent supplier payments beyond the invoice balance', function () {
    $context = concurrencyIntegrationContext();
    $purchases = app(PurchaseService::class);
    $invoice = $purchases->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => $context['date'],
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_rate' => 5,
        ]],
        'concurrent-payment-purchase',
    );
    $invoice = $purchases->confirm($invoice, $context['warehouse']->id, $context['manager']);
    $results = runConcurrentScenario(function (string $slot) use ($context, $invoice): string {
        app(SupplierPaymentService::class)->pay(
            storeId: $context['store']->id,
            branchId: $context['branch']->id,
            fiscalYearId: $context['fiscalYear']->id,
            supplierId: $context['supplier']->id,
            paymentDate: $context['date'],
            paymentMethod: PaymentMethod::Cash,
            allocations: [['invoice_id' => $invoice->id, 'amount' => 60]],
            approver: $context['manager'],
            idempotencyKey: 'concurrent-payment-'.$slot,
        );

        return $slot;
    });

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false))->toHaveCount(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid);
});

it('prevents concurrent sales returns beyond the original quantity', function () {
    $context = concurrencyIntegrationContext();
    $sale = createConcurrencySale($context, 'concurrent-return-sale');
    $item = $sale->items()->firstOrFail();
    $returns = app(SalesReturnService::class);
    $first = $returns->createDraft(
        $sale,
        [['invoice_item_id' => $item->id, 'quantity' => '0.6']],
        $context['date'],
        $context['manager']->id,
        'concurrent-return-draft-a',
    );
    $second = $returns->createDraft(
        $sale,
        [['invoice_item_id' => $item->id, 'quantity' => '0.6']],
        $context['date'],
        $context['manager']->id,
        'concurrent-return-draft-b',
    );
    $results = runConcurrentScenario(function (string $slot) use ($context, $first, $second): string {
        app(SalesReturnService::class)->confirm(
            $slot === 'a' ? $first : $second,
            $context['warehouse']->id,
            $context['manager'],
        );

        return $slot;
    });

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false))->toHaveCount(1)
        ->and(Invoice::query()
            ->where('type', InvoiceType::SalesReturn)
            ->where('status', InvoiceStatus::Confirmed)
            ->count())->toBe(1);
});

it('prevents concurrent purchase returns beyond the original quantity', function () {
    $context = concurrencyIntegrationContext();
    $purchases = app(PurchaseService::class);
    $purchase = $purchases->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'supplier_id' => $context['supplier']->id,
            'invoice_date' => $context['date'],
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_rate' => 5,
        ]],
        'concurrent-purchase-return-source',
    );
    $purchase = $purchases->confirm($purchase, $context['warehouse']->id, $context['manager']);
    $item = $purchase->items()->firstOrFail();
    $returns = app(PurchaseReturnService::class);
    $first = $returns->createDraft(
        $purchase,
        [['invoice_item_id' => $item->id, 'quantity' => '0.6']],
        $context['date'],
        $context['manager']->id,
        null,
        'concurrent-purchase-return-a',
    );
    $second = $returns->createDraft(
        $purchase,
        [['invoice_item_id' => $item->id, 'quantity' => '0.6']],
        $context['date'],
        $context['manager']->id,
        null,
        'concurrent-purchase-return-b',
    );
    $results = runConcurrentScenario(function (string $slot) use ($context, $first, $second): string {
        app(PurchaseReturnService::class)->confirm(
            $slot === 'a' ? $first : $second,
            $context['warehouse']->id,
            $context['manager'],
        );

        return $slot;
    });

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false))->toHaveCount(1)
        ->and(Invoice::query()
            ->where('type', InvoiceType::PurchaseReturn)
            ->where('status', InvoiceStatus::Confirmed)
            ->count())->toBe(1);
});

it('cannot delete an invoice after a concurrent confirmation commits', function () {
    $context = concurrencyIntegrationContext();
    $sales = app(SalesService::class);
    $invoice = $sales->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => $context['date'],
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_rate' => 5,
        ]],
        'concurrent-delete-sale',
    );
    $results = runConcurrentScenario(function (string $slot) use ($context, $invoice): string {
        if ($slot === 'a') {
            app(SalesService::class)->confirm(
                $invoice,
                $context['warehouse']->id,
                $context['manager'],
            );
        } else {
            app(InvoiceDeletionService::class)->deleteDraft(
                $invoice,
                InvoiceType::Sales,
                $context['store']->id,
                $context['branch']->id,
            );
        }

        return $slot;
    });

    expect($results['a']['ok'])->toBeTrue()
        ->and($results['b']['ok'])->toBeFalse()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Confirmed)
        ->and(JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->count())->toBe(1);
});
