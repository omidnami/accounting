<?php

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\TreasuryDocumentStatus;
use App\Models\Check;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Store;
use App\Models\Supplier;
use Database\Seeders\StoreSeeder;

function storeForTreasuryTest(): Store
{
    app(StoreSeeder::class)->run();

    return Store::query()->where('code', 'MAIN')->firstOrFail();
}

it('connects sales invoices, items, products, and customers', function () {
    $party = Party::query()->create([
        'store_id' => storeForTreasuryTest()->id,
        'code' => 'C-001',
        'name' => 'مشتری آزمایشی',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
    ]);

    $product = Product::query()->create([
        'code' => 'P-001',
        'name' => 'کالای آزمایشی',
        'sale_price' => 2_500_000,
        'tax_rate' => 10,
    ]);

    $invoice = Invoice::query()->create([
        'invoice_number' => 'S-001',
        'type' => InvoiceType::Sales,
        'invoice_date' => '2026-10-22',
        'customer_id' => $customer->id,
        'subtotal' => 2_500_000,
        'total' => 2_750_000,
    ]);

    $item = $invoice->items()->create([
        'product_id' => $product->id,
        'product_code' => $product->code,
        'product_name' => $product->name,
        'quantity' => 1,
        'unit_price' => 2_500_000,
        'tax_rate' => 10,
        'tax_amount' => 250_000,
        'line_total' => 2_750_000,
    ]);

    expect($invoice->customer->is($customer))->toBeTrue()
        ->and($customer->invoices)->toHaveCount(1)
        ->and($invoice->items)->toHaveCount(1)
        ->and($item->product->is($product))->toBeTrue()
        ->and($invoice->products)->toHaveCount(1)
        ->and($invoice->type)->toBe(InvoiceType::Sales)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft);
});

it('links a received check to its customer and receipt', function () {
    $party = Party::query()->create([
        'store_id' => storeForTreasuryTest()->id,
        'code' => 'C-002',
        'name' => 'مشتری چک',
    ]);
    $customer = Customer::query()->create([
        'party_id' => $party->id,
    ]);

    $check = Check::query()->create([
        'check_number' => '900001',
        'bank_name' => 'بانک نمونه',
        'amount' => 1_500_000,
        'due_date' => '2026-11-22',
        'type' => CheckType::Receivable,
        'status' => CheckStatus::Pending,
        'customer_id' => $customer->id,
    ]);

    $receipt = Receipt::query()->create([
        'receipt_number' => 'R-001',
        'receipt_date' => '2026-10-22',
        'amount' => 1_500_000,
        'payment_method' => PaymentMethod::Check,
        'customer_id' => $customer->id,
        'check_id' => $check->id,
        'status' => TreasuryDocumentStatus::Pending,
    ]);

    expect($receipt->customer->is($customer))->toBeTrue()
        ->and($receipt->check->is($check))->toBeTrue()
        ->and($check->receipt->is($receipt))->toBeTrue()
        ->and($customer->receipts)->toHaveCount(1)
        ->and($customer->checks)->toHaveCount(1);
});

it('links a payable check to its supplier and payment', function () {
    $party = Party::query()->create([
        'store_id' => storeForTreasuryTest()->id,
        'code' => 'S-002',
        'name' => 'تأمین‌کننده چک',
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
    ]);

    $check = Check::query()->create([
        'check_number' => '700001',
        'bank_name' => 'بانک نمونه',
        'amount' => 2_000_000,
        'due_date' => '2026-12-01',
        'type' => CheckType::Payable,
        'status' => CheckStatus::Pending,
        'supplier_id' => $supplier->id,
    ]);

    $payment = Payment::query()->create([
        'payment_number' => 'P-001',
        'payment_date' => '2026-10-22',
        'amount' => 2_000_000,
        'payment_method' => PaymentMethod::Check,
        'supplier_id' => $supplier->id,
        'check_id' => $check->id,
    ]);

    expect($payment->supplier->is($supplier))->toBeTrue()
        ->and($payment->check->is($check))->toBeTrue()
        ->and($check->payment->is($payment))->toBeTrue()
        ->and($supplier->payments)->toHaveCount(1)
        ->and($supplier->checks)->toHaveCount(1);
});

it('rejects a sales invoice connected to a supplier', function () {
    $party = Party::query()->create([
        'store_id' => storeForTreasuryTest()->id,
        'code' => 'S-003',
        'name' => 'تأمین‌کننده نامعتبر',
    ]);
    $supplier = Supplier::query()->create([
        'party_id' => $party->id,
    ]);

    Invoice::query()->create([
        'invoice_number' => 'S-003',
        'type' => InvoiceType::Sales,
        'invoice_date' => '2026-10-22',
        'supplier_id' => $supplier->id,
    ]);
})->throws(DomainException::class);
