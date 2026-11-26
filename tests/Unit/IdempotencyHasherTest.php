<?php

use App\Enums\InventoryMovementType;
use App\Support\IdempotencyHasher;
use Carbon\CarbonImmutable;

it('hashes idempotency payloads independently of map key order', function () {
    $left = IdempotencyHasher::hash('test.operation', [
        'store_id' => 1,
        'items' => ['b' => 2, 'a' => 1],
    ]);
    $right = IdempotencyHasher::hash('test.operation', [
        'items' => ['a' => 1, 'b' => 2],
        'store_id' => 1,
    ]);

    expect($right)->toBe($left)
        ->and($left)->toMatch('/^[a-f0-9]{64}$/');
});

it('preserves material list order and normalizes enums dates and unicode', function () {
    $payload = [
        'type' => InventoryMovementType::Opening,
        'date' => CarbonImmutable::parse('2026-11-26T12:00:00+03:30'),
        'description' => 'رسید آزمایشی',
    ];

    expect(IdempotencyHasher::hash('test.operation', $payload))->toBe(
        IdempotencyHasher::hash('test.operation', [
            'description' => 'رسید آزمایشی',
            'date' => '2026-11-26T12:00:00+03:30',
            'type' => 'opening',
        ]),
    )->and(IdempotencyHasher::hash('test.operation', ['items' => [1, 2]]))->not->toBe(
        IdempotencyHasher::hash('test.operation', ['items' => [2, 1]]),
    );
});

it('separates operations and rejects unsupported payload values', function () {
    expect(IdempotencyHasher::hash('test.first', ['id' => 1]))->not->toBe(
        IdempotencyHasher::hash('test.second', ['id' => 1]),
    )->and(fn () => IdempotencyHasher::hash('test.invalid', [
        'value' => new stdClass,
    ]))->toThrow(InvalidArgumentException::class);
});
