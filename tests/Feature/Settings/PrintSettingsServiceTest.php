<?php

use App\Enums\InvoicePrintFormat;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use App\Services\Printing\PrintSettingsService;

/**
 * @return array<string, mixed>
 */
function printSettingsServiceContext(): array
{
    $store = Store::withoutEvents(fn (): Store => Store::query()->create([
        'code' => 'PRINT-SERVICE-MAIN',
        'name' => 'فروشگاه تنظیمات چاپ',
        'is_active' => true,
    ]));
    $branch = Branch::withoutEvents(fn (): Branch => Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'MAIN',
        'name' => 'شعبه اصلی',
        'is_default' => true,
        'is_active' => true,
    ]));
    $manager = User::withoutEvents(fn (): User => User::factory()->create(['role' => UserRole::Manager]));
    $accountant = User::withoutEvents(fn (): User => User::factory()->create(['role' => UserRole::Accountant]));

    return compact('store', 'branch', 'manager', 'accountant');
}

it('resolves print format defaults and thermal paper widths for a branch', function () {
    $context = printSettingsServiceContext();
    $branch = $context['branch'];
    $service = app(PrintSettingsService::class);

    foreach ([
        [null, 80, 'thermal_80', 80],
        [null, 58, 'thermal_58', 58],
        ['a3', 58, 'thermal_58', 58],
        ['a4', 58, 'a4', 58],
        ['thermal_58', 80, 'thermal_58', 58],
    ] as [$format, $width, $expectedFormat, $expectedWidth]) {
        $candidate = (new Branch)->forceFill([
            'invoice_print_format' => $format,
            'thermal_paper_width' => $width,
            'thermal_copies' => 2,
            'invoice_header_note' => 'یادداشت چاپ',
        ]);

        expect($service->forBranch($candidate))->toBe([
            'format' => $expectedFormat,
            'paper_width_mm' => $expectedWidth,
            'copies' => 2,
            'header_note' => 'یادداشت چاپ',
        ]);
    }

    $defaults = (new Branch)->forceFill([
        'invoice_print_format' => null,
        'thermal_paper_width' => null,
        'thermal_copies' => null,
        'invoice_header_note' => null,
    ]);

    expect($service->forBranch($defaults))->toBe([
        'format' => 'thermal_80',
        'paper_width_mm' => 80,
        'copies' => 1,
        'header_note' => null,
    ]);
});

it('updates only the selected branch print settings and preserves non-thermal width', function () {
    $context = printSettingsServiceContext();
    $branch = $context['branch'];
    $otherBranch = Branch::withoutEvents(fn (): Branch => Branch::query()->create([
        'store_id' => $context['store']->id,
        'code' => 'OTHER',
        'name' => 'شعبه دیگر',
        'is_active' => true,
        'invoice_print_format' => 'a5',
        'thermal_paper_width' => 58,
        'thermal_copies' => 1,
    ]));
    $branch->forceFill([
        'invoice_print_format' => 'a5',
        'thermal_paper_width' => 58,
    ])->save();
    $service = app(PrintSettingsService::class);

    $service->update($branch, [
        'format' => InvoicePrintFormat::Thermal80,
        'copies' => 4,
        'header_note' => 'شرایط فروش',
    ]);

    expect($branch->refresh()->invoice_print_format)->toBe('thermal_80')
        ->and($branch->thermal_paper_width)->toBe(80)
        ->and($branch->thermal_copies)->toBe(4)
        ->and($branch->invoice_header_note)->toBe('شرایط فروش');

    $service->update($branch, [
        'format' => InvoicePrintFormat::A4,
        'copies' => 2,
        'header_note' => null,
    ]);

    expect($branch->refresh()->invoice_print_format)->toBe('a4')
        ->and($branch->thermal_paper_width)->toBe(80)
        ->and($branch->thermal_copies)->toBe(2)
        ->and($branch->invoice_header_note)->toBeNull()
        ->and($otherBranch->refresh()->invoice_print_format)->toBe('a5')
        ->and($otherBranch->thermal_paper_width)->toBe(58)
        ->and($otherBranch->thermal_copies)->toBe(1)
        ->and($context['manager']->canManageStoreSettings())->toBeTrue()
        ->and($context['accountant']->canManageStoreSettings())->toBeFalse();
});
