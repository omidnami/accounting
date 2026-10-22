<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;
use Hekmatinasser\Verta\Verta;
use InvalidArgumentException;

class JalaliDateService
{
    /** @var array<int, string> */
    private const MONTH_NAMES = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    /**
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}
     */
    public function fiscalYearRange(int $year): array
    {
        $this->assertValidYear($year);
        $lastMonthDays = $this->daysInMonth($year, 12);

        return [
            'starts_on' => $this->toGregorian($year, 1, 1),
            'ends_on' => $this->toGregorian($year, 12, $lastMonthDays),
        ];
    }

    /**
     * @return array<int, array{
     *     period_number: int,
     *     name: string,
     *     starts_on: CarbonImmutable,
     *     ends_on: CarbonImmutable
     * }>
     */
    public function periods(int $year): array
    {
        $this->assertValidYear($year);
        $periods = [];

        for ($month = 1; $month <= 12; $month++) {
            $periods[] = [
                'period_number' => $month,
                'name' => self::MONTH_NAMES[$month].' '.$this->toPersianDigits((string) $year),
                'starts_on' => $this->toGregorian($year, $month, 1),
                'ends_on' => $this->toGregorian($year, $month, $this->daysInMonth($year, $month)),
            ];
        }

        return $periods;
    }

    public function daysInMonth(int $year, int $month): int
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('شماره ماه شمسی باید بین ۱ و ۱۲ باشد.');
        }

        return (int) Verta::parse(sprintf('%04d-%02d-01', $year, $month))->format('t');
    }

    public function isLeapYear(int $year): bool
    {
        return Verta::isLeapYear($year);
    }

    public function currentYear(): int
    {
        return (int) verta()->year;
    }

    public function toJalali(CarbonImmutable $date): Verta
    {
        return Verta::instance($date);
    }

    private function toGregorian(int $year, int $month, int $day): CarbonImmutable
    {
        $carbon = Verta::parse(sprintf('%04d-%02d-%02d', $year, $month, $day))->toCarbon();

        return CarbonImmutable::parse($carbon->toDateString(), 'Asia/Tehran');
    }

    private function assertValidYear(int $year): void
    {
        if ($year < 1200 || $year > 1700) {
            throw new InvalidArgumentException('سال شمسی خارج از محدوده مجاز است.');
        }
    }

    private function toPersianDigits(string $value): string
    {
        return strtr($value, [
            '0' => '۰',
            '1' => '۱',
            '2' => '۲',
            '3' => '۳',
            '4' => '۴',
            '5' => '۵',
            '6' => '۶',
            '7' => '۷',
            '8' => '۸',
            '9' => '۹',
        ]);
    }
}
