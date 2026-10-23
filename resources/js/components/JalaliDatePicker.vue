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
    }>(),
    {
        min: undefined,
        max: undefined,
        id: undefined,
        placeholder: 'انتخاب تاریخ',
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
const initial = toJalali(props.modelValue || todayIso());
const viewYear = ref(initial.year);
const viewMonth = ref(initial.month);

const selected = computed(() =>
    props.modelValue ? toJalali(props.modelValue) : null,
);
const displayValue = computed(() =>
    selected.value
        ? `${toPersianDigits(selected.value.year)}/${toPersianDigits(
              selected.value.month.toString().padStart(2, '0'),
          )}/${toPersianDigits(selected.value.day.toString().padStart(2, '0'))}`
        : '',
);
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
};
const selectDay = (day: number) => {
    if (isDisabled(day)) {
        return;
    }

    emit('update:modelValue', toIsoDate(viewYear.value, viewMonth.value, day));
    open.value = false;
};
const clear = () => {
    emit('update:modelValue', '');
    open.value = false;
};
const toggle = async () => {
    open.value = !open.value;

    if (open.value) {
        const current = selected.value ?? toJalali(todayIso());
        viewYear.value = current.year;
        viewMonth.value = current.month;
        await nextTick();
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
        }
    },
);

onMounted(() => document.addEventListener('mousedown', clickOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', clickOutside));
</script>

<template>
    <div ref="root" class="relative" dir="rtl">
        <button
            :id="id"
            type="button"
            class="flex h-11 w-full items-center justify-between rounded-xl border border-input bg-background px-3 text-right text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
            :aria-expanded="open"
            @click="toggle"
        >
            <span :class="displayValue ? '' : 'text-muted-foreground'">
                {{ displayValue || placeholder }}
            </span>
            <CalendarDays class="size-4 text-muted-foreground" />
        </button>

        <div
            v-if="open"
            class="absolute z-50 mt-2 w-72 rounded-2xl border border-border bg-popover p-3 text-popover-foreground shadow-xl"
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

            <div class="mt-3 grid grid-cols-7 text-center">
                <span
                    v-for="weekDay in weekDays"
                    :key="weekDay"
                    class="py-1 text-[9px] font-bold text-muted-foreground"
                >
                    {{ weekDay }}
                </span>
            </div>
            <div class="grid grid-cols-7 gap-1">
                <template v-for="(day, index) in days" :key="`${index}-${day}`">
                    <span v-if="day === null" class="size-8" />
                    <button
                        v-else
                        type="button"
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
                v-if="!min || !max || modelValue"
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
