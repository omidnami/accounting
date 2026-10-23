const BREAKS = [
    -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097,
    2192, 2262, 2324, 2394, 2456, 3178,
];

const div = (a: number, b: number) => Math.floor(a / b);
const mod = (a: number, b: number) => a - Math.floor(a / b) * b;

const gregorianToDayNumber = (gy: number, gm: number, gd: number) => {
    const a = div(14 - gm, 12);
    const y = gy + 4800 - a;
    const m = gm + 12 * a - 3;

    return (
        gd +
        div(153 * m + 2, 5) +
        365 * y +
        div(y, 4) -
        div(y, 100) +
        div(y, 400) -
        32045
    );
};

const dayNumberToGregorian = (dayNumber: number) => {
    const a = dayNumber + 32044;
    const b = div(4 * a + 3, 146097);
    const c = a - div(146097 * b, 4);
    const d = div(4 * c + 3, 1461);
    const e = c - div(1461 * d, 4);
    const m = div(5 * e + 2, 153);
    const gd = e - div(153 * m + 2, 5) + 1;
    const gm = m + 3 - 12 * div(m, 10);
    const gy = 100 * b + d - 4800 + div(m, 10);

    return { gy, gm, gd };
};

const jalaliMarch = (year: number) => {
    const gy = year + 621;
    let leapJ = -14;
    let previous = BREAKS[0];
    let current = 0;
    let jump = 0;

    for (let index = 1; index < BREAKS.length; index++) {
        current = BREAKS[index];
        jump = current - previous;

        if (year < current) {
            break;
        }

        leapJ += div(jump, 33) * 8 + div(mod(jump, 33), 4);
        previous = current;
    }

    const n = year - previous;
    leapJ += div(n, 33) * 8 + div(mod(n, 33) + 3, 4);

    if (mod(jump, 33) === 4 && jump - n === 4) {
        leapJ++;
    }

    const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;

    return { gy, march: 20 + leapJ - leapG };
};

const jalaliToDayNumber = (jy: number, jm: number, jd: number) => {
    const calendar = jalaliMarch(jy);

    return (
        gregorianToDayNumber(calendar.gy, 3, calendar.march) +
        (jm - 1) * 31 -
        div(jm, 7) * (jm - 7) +
        jd -
        1
    );
};

export const jalaliMonthLength = (year: number, month: number) => {
    if (month <= 6) {
        return 31;
    }

    if (month <= 11) {
        return 30;
    }

    const candidate = toJalali(toIsoDate(year, 12, 30));

    return candidate.year === year && candidate.month === 12 ? 30 : 29;
};

const persianFormatter = new Intl.DateTimeFormat('en-US-u-ca-persian', {
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    timeZone: 'UTC',
});

export const toJalali = (isoDate: string) => {
    const parts = persianFormatter.formatToParts(
        new Date(`${isoDate.slice(0, 10)}T12:00:00Z`),
    );
    const value = (type: Intl.DateTimeFormatPartTypes) =>
        Number(parts.find((part) => part.type === type)?.value ?? 0);

    return {
        year: value('year'),
        month: value('month'),
        day: value('day'),
    };
};

export const toGregorian = (year: number, month: number, day: number) =>
    dayNumberToGregorian(jalaliToDayNumber(year, month, day));

export const toIsoDate = (year: number, month: number, day: number) => {
    const gregorian = toGregorian(year, month, day);

    return [
        gregorian.gy.toString().padStart(4, '0'),
        gregorian.gm.toString().padStart(2, '0'),
        gregorian.gd.toString().padStart(2, '0'),
    ].join('-');
};

export const jalaliWeekDay = (year: number, month: number, day: number) => {
    const gregorian = toGregorian(year, month, day);

    return (
        (new Date(
            Date.UTC(gregorian.gy, gregorian.gm - 1, gregorian.gd),
        ).getUTCDay() +
            1) %
        7
    );
};

export const todayIso = () => {
    const parts = new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone: 'Asia/Tehran',
    }).formatToParts(new Date());
    const value = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${value('year')}-${value('month')}-${value('day')}`;
};

export const toPersianDigits = (value: string | number) =>
    String(value).replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
