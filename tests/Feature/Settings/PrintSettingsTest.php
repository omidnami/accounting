<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\AccountingSetupSeeder;
use Database\Seeders\AccountSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{store: Store, branch: Branch, manager: User}
 */
function printSettingsContext(): array
{
    app(AccountSeeder::class)->run();
    app(AccountingSetupSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch()->firstOrFail();
    $manager = User::factory()->create();
    $manager->forceFill(['role' => UserRole::Manager])->save();

    return compact('store', 'branch', 'manager');
}

it('protects print settings by role', function () {
    $this->get(route('print-settings.edit'))->assertRedirect(route('login'));

    $accountant = User::factory()->create();
    $accountant->forceFill(['role' => UserRole::Accountant])->save();

    $this->actingAs($accountant)
        ->get(route('print-settings.edit'))
        ->assertForbidden();

    $this->actingAs($accountant)
        ->patch(route('print-settings.update'), [
            'branch_id' => 1,
            'format' => 'thermal_58',
            'copies' => 2,
        ])
        ->assertForbidden();
});

it('updates branch thermal print settings with an audit trail', function () {
    $context = printSettingsContext();

    $this->actingAs($context['manager'])
        ->get(route('print-settings.edit', [
            'branch_id' => $context['branch']->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Print')
            ->where('selected_branch_id', $context['branch']->id)
            ->where('settings.format', 'thermal_80')
            ->where('settings.copies', 1)
            ->where('settings.header_note', null));

    $this->actingAs($context['manager'])
        ->patch(route('print-settings.update'), [
            'branch_id' => $context['branch']->id,
            'format' => 'a5',
            'copies' => 3,
            'header_note' => 'شرایط فروش و ضمانت',
        ])
        ->assertRedirect(route('print-settings.edit', [
            'branch_id' => $context['branch']->id,
        ]))
        ->assertSessionHasNoErrors();

    $context['branch']->refresh();

    expect($context['branch']->invoice_print_format)->toBe('a5')
        ->and($context['branch']->invoice_header_note)->toBe('شرایط فروش و ضمانت')
        ->and($context['branch']->thermal_paper_width)->toBe(80)
        ->and($context['branch']->thermal_copies)->toBe(3);

    $audit = AuditLog::query()
        ->where('auditable_type', Branch::class)
        ->where('auditable_id', $context['branch']->id)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($audit->new_values['invoice_print_format'])->toBe('a5')
        ->and($audit->new_values['invoice_header_note'])->toBe('شرایط فروش و ضمانت')
        ->and($audit->new_values['thermal_copies'])->toBe(3);
});

it('validates print settings and branch scope', function () {
    $context = printSettingsContext();
    $otherStore = Store::query()->create([
        'code' => 'PRINT-OTHER',
        'name' => 'فروشگاه دیگر چاپ',
        'is_active' => true,
    ]);
    $otherBranch = Branch::query()->create([
        'store_id' => $otherStore->id,
        'code' => 'OTHER',
        'name' => 'شعبه دیگر',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->actingAs($context['manager'])
        ->patch(route('print-settings.update'), [
            'branch_id' => $context['branch']->id,
            'format' => 'a3',
            'copies' => 6,
        ])
        ->assertSessionHasErrors(['format', 'copies']);

    $this->actingAs($context['manager'])
        ->patch(route('print-settings.update'), [
            'branch_id' => $otherBranch->id,
            'format' => 'thermal_58',
            'copies' => 2,
        ])
        ->assertSessionHasErrors('branch_id');

    $context['branch']->refresh();

    expect($context['branch']->invoice_print_format)->toBe('thermal_80')
        ->and($context['branch']->thermal_paper_width)->toBe(80)
        ->and($context['branch']->thermal_copies)->toBe(1);
});
