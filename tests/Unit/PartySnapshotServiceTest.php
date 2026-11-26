<?php

use App\Enums\PartyEntityType;
use App\Models\Party;
use App\Services\Parties\PartySnapshotService;

it('captures the complete legal party identity without transient status', function () {
    $party = new Party([
        'store_id' => 7,
        'code' => 'PARTY-1',
        'entity_type' => PartyEntityType::Organization,
        'name' => 'شرکت نمونه',
        'phone' => '02100000000',
        'email' => 'legal@example.test',
        'national_id' => '1234567890',
        'tax_id' => '987654321',
        'address' => 'تهران',
        'is_active' => false,
        'version' => 4,
    ]);
    $party->id = 9;

    $snapshot = app(PartySnapshotService::class)->capture($party);

    expect($snapshot)->toBe([
        'schema_version' => PartySnapshotService::SCHEMA_VERSION,
        'party_id' => 9,
        'party_version' => 4,
        'store_id' => 7,
        'code' => 'PARTY-1',
        'entity_type' => 'organization',
        'name' => 'شرکت نمونه',
        'phone' => '02100000000',
        'email' => 'legal@example.test',
        'national_id' => '1234567890',
        'tax_id' => '987654321',
        'address' => 'تهران',
    ])->and($snapshot)->not->toHaveKey('is_active');
});

it('marks legacy fallback identity as current rather than historical', function () {
    $party = new Party([
        'store_id' => 7,
        'code' => 'LEGACY-1',
        'entity_type' => PartyEntityType::Individual,
        'name' => 'نام فعلی',
        'version' => 2,
    ]);
    $party->id = 11;

    $snapshot = app(PartySnapshotService::class)->resolve(null, $party);

    expect($snapshot['name'])->toBe('نام فعلی')
        ->and($snapshot['legacy_current'])->toBeTrue()
        ->and($snapshot)->not->toHaveKey('captured_at');
});

it('decodes stored snapshots and marks database fallback as legacy current', function () {
    $service = app(PartySnapshotService::class);
    $stored = ['schema_version' => 1, 'party_id' => 3, 'name' => 'نام ذخیره‌شده'];
    $fallback = ['id' => 4, 'code' => 'FALLBACK', 'name' => 'نام فعلی'];

    expect($service->fromDatabase(json_encode($stored, JSON_THROW_ON_ERROR), $fallback))->toBe($stored)
        ->and($service->fromDatabase(null, $fallback))->toBe([...$fallback, 'legacy_current' => true]);
});
