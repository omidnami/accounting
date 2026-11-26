<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Branches\BranchManagementService;

/**
 * @return array<string, mixed>
 */
function branchManagementServiceContext(): array
{
    $store = Store::withoutEvents(fn (): Store => Store::query()->create([
        'code' => 'BRANCH-SERVICE-MAIN',
        'name' => 'فروشگاه مدیریت شعبه',
        'is_active' => true,
    ]));
    $default = Branch::withoutEvents(fn (): Branch => Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'MAIN',
        'name' => 'شعبه اصلی',
        'is_default' => true,
        'is_active' => true,
    ]));
    Warehouse::withoutEvents(fn (): Warehouse => Warehouse::query()->create([
        'store_id' => $store->id,
        'branch_id' => $default->id,
        'code' => 'MAIN',
        'name' => 'انبار اصلی',
        'is_default' => true,
        'is_active' => true,
    ]));
    $manager = User::withoutEvents(fn (): User => User::factory()->create(['role' => UserRole::Manager]));
    $accountant = User::withoutEvents(fn (): User => User::factory()->create(['role' => UserRole::Accountant]));

    return compact('store', 'default', 'manager', 'accountant');
}

it('creates a normalized branch and its default warehouse atomically', function () {
    $context = branchManagementServiceContext();
    $this->actingAs($context['manager']);

    $branch = app(BranchManagementService::class)->create($context['store'], [
        'code' => ' second ',
        'name' => ' شعبه دوم ',
        'phone' => '021-12345678',
        'address' => 'نشانی شعبه دوم',
    ]);
    $warehouse = $branch->warehouses()->firstOrFail();
    $audit = AuditLog::query()
        ->where('auditable_type', Branch::class)
        ->where('auditable_id', $branch->id)
        ->where('event', 'created')
        ->firstOrFail();

    expect($branch->store_id)->toBe($context['store']->id)
        ->and($branch->code)->toBe('SECOND')
        ->and($branch->name)->toBe('شعبه دوم')
        ->and($branch->is_default)->toBeFalse()
        ->and($branch->is_active)->toBeTrue()
        ->and($warehouse->store_id)->toBe($context['store']->id)
        ->and($warehouse->branch_id)->toBe($branch->id)
        ->and($warehouse->code)->toBe('MAIN')
        ->and($warehouse->is_default)->toBeTrue()
        ->and($audit->user_id)->toBe($context['manager']->id)
        ->and($context['manager']->canManageBranches())->toBeTrue()
        ->and($context['accountant']->canManageBranches())->toBeFalse();
});

it('rolls back duplicate branch creation and preserves updated branch data', function () {
    $context = branchManagementServiceContext();
    $service = app(BranchManagementService::class);
    $branchCount = Branch::query()->count();
    $warehouseCount = Warehouse::query()->count();

    expect(fn () => $service->create($context['store'], [
        'code' => ' main ',
        'name' => 'شعبه تکراری',
        'phone' => null,
        'address' => null,
    ]))->toThrow(DomainException::class, 'کد شعبه در این فروشگاه قبلاً استفاده شده است.');

    expect(Branch::query()->count())->toBe($branchCount)
        ->and(Warehouse::query()->count())->toBe($warehouseCount);

    $second = $service->create($context['store'], [
        'code' => 'SECOND',
        'name' => 'شعبه دوم',
        'phone' => null,
        'address' => null,
    ]);
    $updated = $service->update($second, [
        'code' => ' second-updated ',
        'name' => ' شعبه ویرایش‌شده ',
        'phone' => '021-87654321',
        'address' => 'نشانی جدید',
    ]);

    expect($updated->code)->toBe('SECOND-UPDATED')
        ->and($updated->name)->toBe('شعبه ویرایش‌شده')
        ->and($updated->phone)->toBe('021-87654321')
        ->and($updated->address)->toBe('نشانی جدید');

    expect(fn () => $service->update($second, [
        'code' => 'MAIN',
        'name' => 'نام نباید ذخیره شود',
        'phone' => null,
        'address' => null,
    ]))->toThrow(DomainException::class);

    expect($second->refresh()->code)->toBe('SECOND-UPDATED')
        ->and($second->name)->toBe('شعبه ویرایش‌شده');
});

it('sets a default branch only within its store and rejects inactive branches', function () {
    $context = branchManagementServiceContext();
    $service = app(BranchManagementService::class);
    $second = $service->create($context['store'], [
        'code' => 'SECOND',
        'name' => 'شعبه دوم',
        'phone' => null,
        'address' => null,
    ]);
    $otherStore = Store::withoutEvents(fn (): Store => Store::query()->create([
        'code' => 'BRANCH-SERVICE-OTHER',
        'name' => 'فروشگاه دیگر',
        'is_active' => true,
    ]));
    $otherDefault = Branch::withoutEvents(fn (): Branch => Branch::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'MAIN',
        'name' => 'شعبه پیش‌فرض دیگر',
        'is_default' => true,
        'is_active' => true,
    ]));

    $service->setDefault($second);

    expect($context['default']->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and($otherDefault->refresh()->is_default)->toBeTrue()
        ->and(session('active_branch_id'))->toBe($second->id);

    $second->forceFill(['is_active' => false])->save();

    expect(fn () => $service->setDefault($second))
        ->toThrow(DomainException::class, 'شعبهٔ غیرفعال را نمی‌توان شعبهٔ پیش‌فرض کرد.');
});

it('guards branch deactivation and clears a stale active branch session', function () {
    $context = branchManagementServiceContext();
    $service = app(BranchManagementService::class);
    $second = $service->create($context['store'], [
        'code' => 'SECOND',
        'name' => 'شعبه دوم',
        'phone' => null,
        'address' => null,
    ]);
    $employee = User::withoutEvents(fn (): User => User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $second->id,
    ]));

    expect(fn () => $service->updateStatus($context['default'], false))
        ->toThrow(DomainException::class, 'شعبهٔ پیش‌فرض را ابتدا تغییر دهید');
    expect(fn () => $service->updateStatus($second, false))
        ->toThrow(DomainException::class, 'کارمندان این شعبه');

    $employee->forceFill(['branch_id' => null])->save();
    session(['active_branch_id' => $second->id]);

    $updated = $service->updateStatus($second, false);

    expect($updated->is_active)->toBeFalse()
        ->and(session('active_branch_id'))->toBe($context['default']->id)
        ->and($context['default']->refresh()->is_active)->toBeTrue();
});

it('keeps at least one active branch in a store', function () {
    $store = Store::withoutEvents(fn (): Store => Store::query()->create([
        'code' => 'BRANCH-SERVICE-SINGLE',
        'name' => 'فروشگاه تک‌شعبه',
        'is_active' => true,
    ]));
    $branch = Branch::withoutEvents(fn (): Branch => Branch::query()->create([
        'store_id' => $store->id,
        'code' => 'ONLY',
        'name' => 'شعبه تنها',
        'is_default' => false,
        'is_active' => true,
    ]));

    expect(fn () => app(BranchManagementService::class)->updateStatus($branch, false))
        ->toThrow(DomainException::class, 'حداقل یک شعبهٔ فعال باید باقی بماند.')
        ->and($branch->refresh()->is_active)->toBeTrue();
});
