<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\JournalEntryLine;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function auditLogServiceContext(): array
{
    app(AccountSeeder::class)->run();
    $store = Store::query()->where('code', 'MAIN')->firstOrFail();
    $branch = $store->defaultBranch;
    $actor = User::factory()->create(['role' => UserRole::Manager]);
    $entryId = DB::table('journal_entries')->insertGetId([
        'store_id' => $store->id,
        'branch_id' => $branch->id,
        'entry_number' => 'AUDIT-SERVICE-PARENT',
        'entry_date' => today()->toDateString(),
        'description' => 'سند والد ممیزی',
        'status' => 'posted',
        'created_by' => $actor->id,
    ]);
    $accountId = DB::table('accounts')->where('store_id', $store->id)->where('code', '1101')->value('id');
    $lineId = DB::table('journal_entry_lines')->insertGetId([
        'journal_entry_id' => $entryId,
        'account_id' => $accountId,
        'debit' => 10,
        'credit' => 0,
        'sort_order' => 1,
    ]);

    return compact('store', 'branch', 'actor', 'lineId');
}

it('records the authenticated actor and model scope through AuditLogService', function () {
    $context = auditLogServiceContext();
    $this->actingAs($context['actor']);

    $log = app(AuditLogService::class)->record(
        'updated',
        $context['branch'],
        ['name' => 'نام قبلی'],
        ['name' => 'نام جدید'],
        'تغییر شعبه توسط مدیر',
    );

    expect($log)->toBeInstanceOf(AuditLog::class)
        ->and($log->store_id)->toBe($context['store']->id)
        ->and($log->branch_id)->toBeNull()
        ->and($log->user_id)->toBe($context['actor']->id)
        ->and($log->actor_name)->toBe($context['actor']->name)
        ->and($log->actor_email)->toBe($context['actor']->email)
        ->and($log->event)->toBe('updated')
        ->and($log->auditable_type)->toBe(Branch::class)
        ->and($log->auditable_id)->toBe($context['branch']->id)
        ->and($log->description)->toBe('تغییر شعبه توسط مدیر')
        ->and($log->old_values)->toBe(['name' => 'نام قبلی'])
        ->and($log->new_values)->toBe(['name' => 'نام جدید'])
        ->and($log->ip_address)->toBeNull()
        ->and($log->user_agent)->toBeNull();
});

it('inherits store and branch scope from a journal entry line', function () {
    $context = auditLogServiceContext();
    $line = JournalEntryLine::query()->findOrFail($context['lineId']);

    $log = app(AuditLogService::class)->record('updated', $line, [], ['debit' => 20]);

    expect($log->store_id)->toBe($context['store']->id)
        ->and($log->branch_id)->toBe($context['branch']->id)
        ->and($log->auditable_type)->toBe(JournalEntryLine::class)
        ->and($log->auditable_id)->toBe($line->id)
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBe(['debit' => 20])
        ->and($log->description)->toBe('JournalEntryLine updated');
});

it('preserves a deleted users identity when no authenticated actor exists', function () {
    $target = User::factory()->create([
        'name' => 'کاربر حذف‌شده',
        'email' => 'deleted-actor@example.com',
    ]);
    Auth::logout();

    $log = app(AuditLogService::class)->record('deleted', $target, ['email' => $target->email]);

    expect($log->user_id)->toBe($target->id)
        ->and($log->actor_name)->toBe($target->name)
        ->and($log->actor_email)->toBe($target->email)
        ->and($log->event)->toBe('deleted')
        ->and($log->auditable_type)->toBe(User::class)
        ->and($log->new_values)->toBeNull();
});
