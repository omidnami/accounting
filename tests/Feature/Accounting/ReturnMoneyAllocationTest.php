<?php

use App\Services\Returns\ReturnableQuantityService;
use App\Support\Decimal;

it('never over-allocates money across repeated partial returns', function () {
    $service = app(ReturnableQuantityService::class);
    $allocated = '0.00';
    $returnedQuantity = '0.000';
    $allocations = [];

    for ($index = 1; $index <= 6; $index++) {
        $amount = $service->allocateMoney(
            '0.05',
            $allocated,
            '1',
            $returnedQuantity,
            '6',
            $index === 6,
        );
        $allocations[] = $amount;
        $allocated = Decimal::add($allocated, $amount, 2);
        $returnedQuantity = Decimal::add($returnedQuantity, '1', 3);
    }

    expect($allocations)->toBe([
        '0.01',
        '0.01',
        '0.01',
        '0.00',
        '0.01',
        '0.01',
    ])
        ->and($allocated)->toBe('0.05');
});

it('assigns the final residual to the last partial return', function () {
    $service = app(ReturnableQuantityService::class);

    $first = $service->allocateMoney('0.07', '0.00', '1', '0', '3', false);
    $second = $service->allocateMoney('0.07', $first, '1', '1', '3', false);
    $third = $service->allocateMoney('0.07', Decimal::add($first, $second, 2), '1', '2', '3', true);

    expect([$first, $second, $third])->toBe(['0.02', '0.03', '0.02'])
        ->and(Decimal::add(Decimal::add($first, $second, 2), $third, 2))->toBe('0.07');
});
