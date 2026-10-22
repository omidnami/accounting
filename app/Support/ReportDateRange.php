<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportDateRange
{
    private function __construct(
        public string $from,
        public string $to,
    ) {}

    public static function between(string $from, string $to): self
    {
        $fromDate = CarbonImmutable::parse($from)->toDateString();
        $toDate = CarbonImmutable::parse($to)->toDateString();

        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('تاریخ شروع گزارش نمی‌تواند بعد از تاریخ پایان باشد.');
        }

        return new self($fromDate, $toDate);
    }

    public static function through(string $to): string
    {
        return CarbonImmutable::parse($to)->toDateString();
    }
}
