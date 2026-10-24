<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight, X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import {
    jalaliMonthLength,
    jalaliWeekDay,
    parseJalaliDate,
    toIsoDate,
    toJalali,
    toPersianDigits,
    todayIso,
} from '@/lib/jalali';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        min?: string;
        max?: string;
        id?: string;
        placeholder?: string;
        required?: boolean;
    }>(),
    {
        min: undefined,
        max: undefined,
        id: undefined,
        placeholder: 'انتخاب تاریخ',
        required: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const monthNames = [
    'فروردین',
    'اردیبهشت',
    'خرداد',
    'تیر',
    'مرداد',
    'شهریور',
    'مهر',
    'آبان',
    'آذر',
    'دی',
    'بهمن',
    'اسفند',
];
const weekDays = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const typedValue = ref('');
const inputError = ref('');
const focusedDay = ref(1);
const initial = toJalali(props.modelValue || todayIso());
const viewYear = ref(initial.year);
const viewMonth = ref(initial.month);

const selected = computed(() =>
    props.modelValue ? toJalali(props.modelValue) : null,
);
const formatted = (year: number, month: number, day: number) =>
    `${toPersianDigits(year)}/${toPersianDigits(
        month.toString().padStart(2, '0'),
    )}/${toPersianDigits(day.toString().padStart(2, '0'))}`;
const days = computed(() => {
    const length = jalaliMonthLength(viewYear.value, viewMonth.value);
    const offset = jalaliWeekDay(viewYear.value, viewMonth.value, 1);
    const cells: Array<number | null> = Array.from(
        { length: offset },
        () => null,
    );

    for (let day = 1; day <= length; day++) {
        cells.push(day);
    }

    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    return cells;
});
const minDate = computed(() => (props.min ? toJalali(props.min) : null));
const maxDate = computed(() => (props.max ? toJalali(props.max) : null));
const dateKey = (date: { year: number; month: number; day: number }) =>
    date.year * 10000 + date.month * 100 + date.day;
const isDisabled = (day: number) => {
    const key = dateKey({
        year: viewYear.value,
        month: viewMonth.value,
        day,
    });

    return (
        (minDate.value !== null && key < dateKey(minDate.value)) ||
        (maxDate.value !== null && key > dateKey(maxDate.value))
    );
};
const isSelected = (day: number) =>
    selected.value?.year === viewYear.value &&
    selected.value?.month === viewMonth.value &&
    selected.value?.day === day;
const changeMonth = (direction: number) => {
    let month = viewMonth.value + direction;
    let year = viewYear.value;

    if (month < 1) {
        month = 12;
        year--;
    } else if (month > 12) {
        month = 1;
        year++;
    }

    viewMonth.value = month;
    viewYear.value = year;
    focusedDay.value = Math.min(
        focusedDay.value,
        jalaliMonthLength(year, month),
    );
};
const focusDay = async () => {
    await nextTick();
    root.value
        ?.querySelector<HTMLButtonElement>(`[data-day="${focusedDay.value}"]`)
        ?.focus();
};
const close = () => {
    open.value = false;
    void nextTick(() => trigger.value?.focus());
};
const selectDay = (day: number) => {
    if (isDisabled(day)) {
        return;
    }

    emit('update:modelValue', toIsoDate(viewYear.value, viewMonth.value, day));
    close();
};
const clear = () => {
    emit('update:modelValue', '');
    typedValue.value = '';
    inputError.value = '';
    close();
};
const parseTyped = () => {
    const value = typedValue.value.trim();

    if (value === '') {
        inputError.value =
            props.required && !props.modelValue ? 'تاریخ را وارد کنید.' : '';

        if (!props.required) {
            emit('update:modelValue', '');
        }

        return;
    }

    const parsed = parseJalaliDate(value);

    if (!parsed) {
        inputError.value = 'تاریخ را به شکل ۱۴۰۵/۰۸/۰۲ وارد کنید.';
        return;
    }

    const { iso, year, month, day } = parsed;

    if (
        (props.min && iso < props.min.slice(0, 10)) ||
        (props.max && iso > props.max.slice(0, 10))
    ) {
        inputError.value = 'تاریخ خارج از بازهٔ مجاز است.';
        return;
    }

    inputError.value = '';
    typedValue.value = formatted(year, month, day);
    emit('update:modelValue', iso);
};
const onTypedKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        parseTyped();
    }
};
const toggle = async () => {
    open.value = !open.value;

    if (open.value) {
        const current = selected.value ?? toJalali(todayIso());
        viewYear.value = current.year;
        viewMonth.value = current.month;
        focusedDay.value = selected.value?.day ?? 1;
        await focusDay();
    }
};
const moveFocus = (days: number) => {
    const iso = toIsoDate(viewYear.value, viewMonth.value, focusedDay.value);
    const date = new Date(`${iso}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    const next = toJalali(date.toISOString().slice(0, 10));
    const nextKey = dateKey(next);

    if (
        (minDate.value !== null && nextKey < dateKey(minDate.value)) ||
        (maxDate.value !== null && nextKey > dateKey(maxDate.value))
    ) {
        return;
    }

    viewYear.value = next.year;
    viewMonth.value = next.month;
    focusedDay.value = next.day;
    void focusDay();
};
const onCalendarKeydown = (event: KeyboardEvent) => {
    const keys: Record<string, number> = {
        ArrowLeft: 1,
        ArrowRight: -1,
        ArrowDown: 7,
        ArrowUp: -7,
    };

    if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
    }

    if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        const nextDay =
            event.key === 'Home'
                ? 1
                : jalaliMonthLength(viewYear.value, viewMonth.value);

        if (!isDisabled(nextDay)) {
            focusedDay.value = nextDay;
            void focusDay();
        }

        return;
    }

    if (event.key in keys) {
        event.preventDefault();
        moveFocus(keys[event.key]);
    }
};
const clickOutside = (event: MouseEvent) => {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
};

watch(
    () => props.modelValue,
    (value) => {
        if (value) {
            const converted = toJalali(value);
            viewYear.value = converted.year;
            viewMonth.value = converted.month;
            typedValue.value = formatted(
                converted.year,
                converted.month,
                converted.day,
            );
            inputError.value = '';
        } else {
            typedValue.value = '';
        }
    },
    { immediate: true },
);

onMounted(() => document.addEventListener('mousedown', clickOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', clickOutside));
</script>

<template>
    <div ref="root" class="relative" dir="rtl">
        <div
            class="flex h-11 items-center rounded-xl border border-input bg-background transition focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/20"
            :class="inputError ? 'border-red-500' : ''"
        >
            <input
                :id="id"
                v-model="typedValue"
                type="text"
                inputmode="numeric"
                dir="ltr"
                autocomplete="off"
                :placeholder="
                    placeholder === 'انتخاب تاریخ' ? '۱۴۰۵/۰۸/۰۲' : placeholder
                "
                :required="required"
                :aria-invalid="inputError !== ''"
                class="h-full min-w-0 flex-1 bg-transparent px-3 text-left text-sm outline-none placeholder:text-right placeholder:text-muted-foreground"
                @blur="parseTyped"
                @keydown="onTypedKeydown"
            />
            <button
                ref="trigger"
                type="button"
                class="flex size-10 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted"
                :aria-expanded="open"
                aria-label="بازکردن تقویم"
                @click="toggle"
            >
                <CalendarDays class="size-4" />
            </button>
        </div>
        <p v-if="inputError" class="mt-1 text-[10px] text-red-600">
            {{ inputError }}
        </p>

        <div
            v-if="open"
            role="dialog"
            aria-label="انتخاب تاریخ شمسی"
            class="absolute right-0 z-50 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-2xl border border-border bg-popover p-3 text-popover-foreground shadow-xl"
            @keydown="onCalendarKeydown"
        >
            <div class="flex items-center justify-between">
                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-lg hover:bg-muted"
                    aria-label="ماه بعد"
                    @click="changeMonth(1)"
                >
                    <ChevronLeft class="size-4" />
                </button>
                <div class="text-center">
                    <p class="text-sm font-bold">
                        {{ monthNames[viewMonth - 1] }}
                    </p>
                    <p class="text-[10px] text-muted-foreground">
                        {{ toPersianDigits(viewYear) }}
                    </p>
                </div>
                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-lg hover:bg-muted"
                    aria-label="ماه قبل"
                    @click="changeMonth(-1)"
                >
                    <ChevronRight class="size-4" />
                </button>
            </div>

            <div role="row" class="mt-3 grid grid-cols-7 text-center">
                <span
                    v-for="weekDay in weekDays"
                    :key="weekDay"
                    role="columnheader"
                    class="py-1 text-[9px] font-bold text-muted-foreground"
                >
                    {{ weekDay }}
                </span>
            </div>
            <div role="grid" class="grid grid-cols-7 gap-1">
                <template v-for="(day, index) in days" :key="`${index}-${day}`">
                    <span v-if="day === null" class="size-8" />
                    <button
                        v-else
                        type="button"
                        role="gridcell"
                        :data-day="day"
                        :tabindex="focusedDay === day ? 0 : -1"
                        :aria-label="`${day} ${monthNames[viewMonth - 1]} ${toPersianDigits(viewYear)}`"
                        class="flex size-8 items-center justify-center rounded-lg text-xs transition"
                        :class="[
                            isSelected(day)
                                ? 'bg-primary font-bold text-primary-foreground'
                                : 'hover:bg-muted',
                            isDisabled(day)
                                ? 'cursor-not-allowed opacity-25'
                                : '',
                        ]"
                        :disabled="isDisabled(day)"
                        @click="selectDay(day)"
                    >
                        {{ toPersianDigits(day) }}
                    </button>
                </template>
            </div>
            <div
                v-if="modelValue && !required"
                class="mt-2 flex justify-end border-t pt-2"
            >
                <button
                    type="button"
                    class="flex items-center gap-1 rounded-lg px-2 py-1 text-[10px] text-muted-foreground hover:bg-muted"
                    @click="clear"
                >
                    <X class="size-3" />
                    پاک‌کردن
                </button>
            </div>
        </div>
    </div>
</template>
