<?php

use App\Enums\InventoryMovementType;
use App\Enums\ProductSerialStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\InventoryAlert;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\PrintStation;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\ProductSerial;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\InventoryExpiryAlertService;
use App\Services\Inventory\InventoryService;
use App\Services\Printing\PrintJobService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Reporting\InventorySalesAnalyticsService;
use App\Services\Reporting\NativePdfExportService;
use App\Services\Reporting\NativeXlsxExportService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Str;

/**
 * @return array<string, mixed>
 */
function traceabilityContext(bool $serials = true): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $warehouse = $branch->warehouses()->where('is_default', true)->firstOrFail();
    $fiscalYear = $store->fiscalYears()->where('is_current', true)->firstOrFail();
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();
    $category = ProductCategory::query()->create([
        'code' => 'TRACE-CAT',
        'name' => 'کالاهای ردیابی',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'code' => 'TRACE-P-001',
        'name' => 'کالای دارای Batch',
        'unit' => 'عدد',
        'category_id' => $category->id,
        'purchase_price' => 100,
        'sale_price' => 200,
        'tax_rate' => 0,
        'track_batches' => true,
        'track_serials' => $serials,
        'is_active' => true,
    ]);
    $customerParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'TRACE-CUSTOMER',
        'name' => 'مشتری ردیابی',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'party_id' => $customerParty->id,
        'credit_limit' => 100_000_000,
        'is_active' => true,
    ]);
    $supplierParty = Party::query()->create([
        'store_id' => $store->id,
        'code' => 'TRACE-SUPPLIER',
        'name' => 'تأمین‌کننده ردیابی',
        'is_active' => true,
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $supplierParty->id,
        'is_active' => true,
    ]);

    return compact(
        'store',
        'branch',
        'warehouse',
        'fiscalYear',
        'manager',
        'category',
        'product',
        'customer',
        'supplier',
    ) + ['date' => $fiscalYear->starts_on->toDateString()];
}

it('applies FEFO and records exact serials through sale and sales return', function () {
    $context = traceabilityContext();
    $early = ProductBatch::query()->create([
        'product_id' => $context['product']->id,
        'batch_number' => 'EARLY',
        'expiry_date' => $context['fiscalYear']->starts_on->addDays(10),
    ]);
    $late = ProductBatch::query()->create([
        'product_id' => $context['product']->id,
        'batch_number' => 'LATE',
        'expiry_date' => $context['fiscalYear']->starts_on->addDays(100),
    ]);
    $inventory = app(InventoryService::class);

    foreach ([
        [$early, ['E-1', 'E-2']],
        [$late, ['L-1', 'L-2']],
    ] as [$batch, $serials]) {
        $inventory->receive(
            warehouseId: $context['warehouse']->id,
            productId: $context['product']->id,
            quantity: 2,
            unitCost: 100,
            movementDate: $context['date'],
            idempotencyKey: 'trace-open-'.$batch->id,
            movementType: InventoryMovementType::Opening,
            productBatchId: $batch->id,
            serialNumbers: $serials,
        );
    }

    $sales = app(SalesService::class);
    $sale = $sales->createDraft(
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
            'quantity' => 3,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'trace-sale',
    );
    $sale = $sales->confirm($sale, $context['warehouse']->id, $context['manager']);
    $saleMovement = InventoryMovement::query()
        ->where('source_type', Invoice::class)
        ->where('source_id', $sale->id)
        ->where('product_id', $context['product']->id)
        ->with('batchAssignments.batch')
        ->firstOrFail();

    expect($saleMovement->batchAssignments->first()->batch->batch_number)->toBe('EARLY')
        ->and($saleMovement->batchAssignments->first()->quantity)->toBe('2.000')
        ->and($saleMovement->batchAssignments->last()->batch->batch_number)->toBe('LATE')
        ->and(ProductSerial::query()
            ->whereIn('serial_number', ['E-1', 'E-2', 'L-1'])
            ->where('status', ProductSerialStatus::Sold->value)
            ->count())->toBe(3)
        ->and(ProductSerial::query()
            ->where('serial_number', 'L-2')
            ->firstOrFail()
            ->status)->toBe(ProductSerialStatus::Available);

    $return = app(SalesReturnService::class)->createDraft(
        $sale,
        [[
            'invoice_item_id' => $sale->items()->firstOrFail()->id,
            'quantity' => 2,
        ]],
        $context['date'],
        $context['manager']->id,
        'trace-sale-return',
    );
    app(SalesReturnService::class)->confirm($return, $context['warehouse']->id, $context['manager']);

    expect(ProductSerial::query()
        ->whereIn('serial_number', ['E-1', 'E-2'])
        ->where('status', ProductSerialStatus::Available->value)
        ->count())->toBe(2);
});

it('tracks purchased batch and serial and returns them to supplier', function () {
    $context = traceabilityContext();
    $purchase = app(PurchaseService::class)->createDraft(
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
            'quantity' => 2,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_rate' => 0,
            'batch_number' => 'PURCHASE-BATCH',
            'expiry_date' => $context['fiscalYear']->starts_on->addDays(50)->toDateString(),
            'serial_numbers' => ['P-1', 'P-2'],
        ]],
        'trace-purchase',
    );
    $purchase = app(PurchaseService::class)->confirm(
        $purchase,
        $context['warehouse']->id,
        $context['manager'],
    );
    $return = app(PurchaseReturnService::class)->createDraft(
        $purchase,
        [[
            'invoice_item_id' => $purchase->items()->firstOrFail()->id,
            'quantity' => 1,
        ]],
        $context['date'],
        $context['manager']->id,
        null,
        'trace-purchase-return',
    );
    app(PurchaseReturnService::class)->confirm(
        $return,
        $context['warehouse']->id,
        $context['manager'],
    );

    expect(ProductSerial::query()
        ->whereIn('serial_number', ['P-1', 'P-2'])
        ->where('status', ProductSerialStatus::Returned->value)
        ->count())->toBe(1)
        ->and(ProductSerial::query()
            ->whereIn('serial_number', ['P-1', 'P-2'])
            ->where('status', ProductSerialStatus::Available->value)
            ->count())->toBe(1);
});

it('creates and resolves expiry alerts from batch stock', function () {
    $context = traceabilityContext(false);
    $batch = ProductBatch::query()->create([
        'product_id' => $context['product']->id,
        'batch_number' => 'EXPIRING',
        'expiry_date' => $context['fiscalYear']->starts_on->addDays(10),
    ]);
    app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 2,
        unitCost: 100,
        movementDate: $context['date'],
        idempotencyKey: 'expiry-opening',
        movementType: InventoryMovementType::Opening,
        productBatchId: $batch->id,
    );
    app(InventoryExpiryAlertService::class)->refresh($context['fiscalYear']->starts_on);

    expect(InventoryAlert::query()->where('product_batch_id', $batch->id)->count())->toBe(1);
});

it('claims and completes an outbound-only print agent job', function () {
    $context = traceabilityContext(false);
    $sale = app(SalesService::class)->createDraft(
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
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'print-sale',
    );
    app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 1,
        unitCost: 100,
        movementDate: $context['date'],
        idempotencyKey: 'print-opening',
        movementType: InventoryMovementType::Opening,
        productBatchId: ProductBatch::query()->create([
            'product_id' => $context['product']->id,
            'batch_number' => 'PRINT-BATCH',
        ])->id,
    );
    $sale = app(SalesService::class)->confirm($sale, $context['warehouse']->id, $context['manager']);
    $token = Str::random(64);
    PrintStation::query()->create([
        'store_id' => $context['store']->id,
        'branch_id' => $context['branch']->id,
        'name' => 'Test Station',
        'platform' => 'linux',
        'token_hash' => hash('sha256', $token),
        'status' => 'active',
    ]);
    $job = app(PrintJobService::class)->queueSalesInvoice($sale);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/print-agent/claim')
        ->assertOk()
        ->assertJsonPath('job.id', $job->id);
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/print-agent/jobs/'.$job->id.'/complete', [
            'status' => 'printed',
        ])
        ->assertOk();

    expect($job->refresh()->status)->toBe('printed')
        ->and($job->printed_at)->not->toBeNull();
});

it('exports a real xlsx and analytics by category and batch', function () {
    $context = traceabilityContext(false);
    $batch = ProductBatch::query()->create([
        'product_id' => $context['product']->id,
        'batch_number' => 'ANALYTICS-BATCH',
        'expiry_date' => now()->addDays(30)->toDateString(),
    ]);
    app(InventoryService::class)->receive(
        warehouseId: $context['warehouse']->id,
        productId: $context['product']->id,
        quantity: 3,
        unitCost: 100,
        movementDate: $context['date'],
        idempotencyKey: 'analytics-opening',
        movementType: InventoryMovementType::Opening,
        productBatchId: $batch->id,
    );
    $sale = app(SalesService::class)->createDraft(
        [
            'store_id' => $context['store']->id,
            'branch_id' => $context['branch']->id,
            'fiscal_year_id' => $context['fiscalYear']->id,
            'customer_id' => $context['customer']->id,
            'invoice_date' => now()->toDateString(),
            'created_by' => $context['manager']->id,
        ],
        [[
            'product_id' => $context['product']->id,
            'quantity' => 2,
            'unit_price' => 200,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]],
        'analytics-sale',
    );
    app(SalesService::class)->confirm($sale, $context['warehouse']->id, $context['manager']);
    $analytics = app(InventorySalesAnalyticsService::class)->report(
        $context['store']->id,
        $context['branch']->id,
        $context['fiscalYear']->starts_on->toDateString(),
        $context['fiscalYear']->ends_on->toDateString(),
    );
    $response = app(NativeXlsxExportService::class)->download(
        'test',
        'گزارش',
        ['کالا', 'مقدار'],
        [['کالای نمونه', 2]],
    );
    $zip = new ZipArchive;
    expect($zip->open($response->getFile()->getPathname()))->toBeTrue();
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheet)->toContain('کالای نمونه')
        ->and($sheet)->toContain('rightToLeft="1"')
        ->and($analytics['categories'][0]['label'])->toBe('کالاهای ردیابی')
        ->and($analytics['batches'][0]['batch_number'])->toBe('ANALYTICS-BATCH')
        ->and($analytics['top_month'][0]['product_id'])->toBe($context['product']->id);
});

it('renders a native server-side PDF when Chrome is available', function () {
    $binary = (string) config('accounting.pdf_renderer_binary');

    if (! is_executable($binary)) {
        $this->markTestSkipped('Configured PDF renderer is unavailable.');
    }

    $response = app(NativePdfExportService::class)->download(
        'report',
        'گزارش آزمایشی',
        ['کالا', 'مبلغ'],
        [['کالای فارسی', '۱۰۰']],
    );
    $content = file_get_contents($response->getFile()->getPathname());

    expect($content)->toStartWith('%PDF');
});

it('serves native xlsx through the report export endpoint', function () {
    $context = traceabilityContext(false);

    $response = $this->actingAs($context['manager'])
        ->get(route('reports.export.financial', [
            'report' => 'trial',
            'format' => 'xlsx',
            'from' => $context['fiscalYear']->starts_on->toDateString(),
            'to' => $context['fiscalYear']->ends_on->toDateString(),
        ]));
    $response->assertOk();
    $content = file_get_contents($response->baseResponse->getFile()->getPathname());

    expect($content)->toStartWith("PK\x03\x04");
});
