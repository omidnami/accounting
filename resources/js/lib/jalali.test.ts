import assert from 'node:assert/strict';
import test from 'node:test';
import {
    jalaliMonthLength,
    parseJalaliDate,
    toIsoDate,
    toJalali,
    todayIso,
} from './jalali.ts';

void test('converts known Gregorian dates to Jalali', () => {
    assert.deepEqual(toJalali('2020-03-20'), {
        year: 1399,
        month: 1,
        day: 1,
    });
    assert.deepEqual(toJalali('2024-03-20'), {
        year: 1403,
        month: 1,
        day: 1,
    });
    assert.deepEqual(toJalali('2026-10-23'), {
        year: 1405,
        month: 8,
        day: 1,
    });
});

void test('round-trips every day from 1390 through 1410', () => {
    for (let year = 1390; year <= 1410; year++) {
        for (let month = 1; month <= 12; month++) {
            const monthLength = jalaliMonthLength(year, month);

            for (let day = 1; day <= monthLength; day++) {
                const iso = toIsoDate(year, month, day);
                assert.deepEqual(toJalali(iso), { year, month, day });
            }
        }
    }
});

void test('calculates leap Esfand and Tehran today', () => {
    assert.equal(jalaliMonthLength(1403, 12), 30);
    assert.equal(jalaliMonthLength(1404, 12), 29);
    assert.match(todayIso(), /^\d{4}-\d{2}-\d{2}$/);
});

void test('parses typed Persian, Arabic and Latin Jalali dates', () => {
    assert.equal(parseJalaliDate('۱۴۰۵/۰۸/۰۲')?.iso, '2026-10-24');
    assert.equal(parseJalaliDate('١٤٠٥.٨.٢')?.iso, '2026-10-24');
    assert.equal(parseJalaliDate('1405-08-02')?.iso, '2026-10-24');
    assert.equal(parseJalaliDate('۱۴۰۵/۱۳/۰۲'), null);
    assert.equal(parseJalaliDate('۱۴۰۴/۱۲/۳۰'), null);
    assert.equal(parseJalaliDate('تاریخ نامعتبر'), null);
});
