<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookOpen,
    CalendarDays,
    CircleAlert,
    Layers3,
    Plus,
    Save,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { Button } from '@/components/ui/button';
import {
    index as journalsIndex,
    show as showJournal,
    store as storeJournal,
    update as updateJournal,
} from '@/routes/journals';

type AccountOption = {
    id: number;
    code: string;
    name: string;
    type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
    normal_balance: 'debit' | 'credit';
    requires_party: boolean;
    can_use_manual: boolean;
    can_use_opening: boolean;
};
type PartyOption = { id: number; code: string; name: string };
type FormLine = {
    account_id: string;
    party_id: string;
    debit: string;
    credit: string;
    description: string;
};
type EditableEntry = {
    id: number;
    entry_kind: 'manual' | 'opening';
    entry_date: string;
    description: string;
    lines: Array<{
        account_id: number;
        party_id: number | null;
        debit: string;
        credit: string;
        description: string | null;
    }>;
};

const props = defineProps<{
    entry: EditableEntry | null;
    default_kind: 'manual' | 'opening';
    accounts: AccountOption[];
    parties: PartyOption[];
    fiscal_year: {
        id: number;
        title: string;
        starts_on: string;
        ends_on: string;
    } | null;
    default_date: string;
}>();

const blankLine = (): FormLine => ({
    account_id: '',
    party_id: '',
    debit: '0',
    credit: '0',
    description: '',
});
const initialLines = (): FormLine[] =>
    props.entry
        ? props.entry.lines.map((line) => ({
              account_id: line.account_id.toString(),
              party_id: line.party_id?.toString() ?? '',
              debit: line.debit,
              credit: line.credit,
              description: line.description ?? '',
          }))
        : [blankLine(), blankLine()];
const form = useForm({
    entry_kind: props.entry?.entry_kind ?? props.default_kind,
    entry_date:
        props.entry?.entry_date ??
        (props.default_kind === 'opening'
            ? (props.fiscal_year?.starts_on ?? props.default_date)
            : props.default_date),
    description: props.entry?.description ?? '',
    lines: initialLines(),
});
const serverError = ref<string | null>(null);
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const isOpening = computed(() => form.entry_kind === 'opening');
const availableAccounts = computed(() =>
    props.accounts.filter((account) =>
        isOpening.value ? account.can_use_opening : account.can_use_manual,
    ),
);
const debitTotal = computed(() =>
    form.lines.reduce((total, line) => total + Number(line.debit || 0), 0),
);
const creditTotal = computed(() =>
    form.lines.reduce((total, line) => total + Number(line.credit || 0), 0),
);
const difference = computed(() =>
    Math.abs(debitTotal.value - creditTotal.value),
);
const journalError = computed(
    () =>
        serverError.value ??
        (form.errors as Record<string, string | undefined>).journal,
);
const selectedAccount = (line: FormLine) =>
    props.accounts.find((account) => account.id.toString() === line.account_id);
const lineError = (index: number, field: string) =>
    (form.errors as Record<string, string | undefined>)[
        `lines.${index}.${field}`
    ];
const addLine = () => {
    if (form.lines.length < 100) {
        form.lines.push(blankLine());
    }
};
const removeLine = (index: number) => {
    if (form.lines.length > 2) {
        form.lines.splice(index, 1);
    }
};
const setDebit = (line: FormLine) => {
    if (Number(line.debit || 0) > 0) {
        line.credit = '0';
    }
};
const setCredit = (line: FormLine) => {
    if (Number(line.credit || 0) > 0) {
        line.debit = '0';
    }
};
const changeAccount = (line: FormLine) => {
    if (!selectedAccount(line)?.requires_party) {
        line.party_id = '';
    }
};
const changeKind = () => {
    if (!props.entry) {
        form.lines = [blankLine(), blankLine()];
    }

    if (isOpening.value && props.fiscal_year) {
        form.entry_date = props.fiscal_year.starts_on;
    }
};
const submit = () => {
    form.clearErrors();
    serverError.value = null;

    if (!props.fiscal_year) {
        serverError.value = 'برای ثبت سند، یک سال مالی جاری باز لازم است.';
        return;
    }

    if (difference.value > 0.009) {
        serverError.value = 'جمع بدهکار و بستانکار باید کاملاً برابر باشد.';
        return;
    }

    if (debitTotal.value <= 0) {
        serverError.value = 'مبلغ سند باید بزرگ‌تر از صفر باشد.';
        return;
    }

    if (props.entry) {
        form.put(updateJournal(props.entry.id).url, {
            preserveScroll: true,
        });
    } else {
        form.post(storeJournal().url, {
            preserveScroll: true,
        });
    }
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'اسناد دستی',
                href: journalsIndex(),
            },
            {
                title: 'مدیریت سند',
                href: '#',
            },
        ],
    },
});
</script>

<template>
    <Head :title="entry ? 'ویرایش سند دستی' : 'سند دستی جدید'" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.08)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <BookOpen class="size-5" />
                        <span class="text-xs font-bold">
                            {{ isOpening ? 'مانده آغازین' : 'سند دستی' }}
                        </span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        {{
                            entry
                                ? 'ویرایش پیش‌نویس سند'
                                : isOpening
                                  ? 'ثبت مانده افتتاحیه'
                                  : 'ثبت سند دستی'
                        }}
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        سند با شماره رسمی به‌صورت پیش‌نویس ذخیره می‌شود. ثبت
                        نهایی فقط پس از کنترل توازن و مجوز مدیر یا حسابدار انجام
                        می‌گیرد.
                    </p>
                </div>
                <Button as-child variant="outline" class="w-fit rounded-xl">
                    <Link :href="journalsIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت به اسناد
                    </Link>
                </Button>
            </div>
        </section>

        <div
            v-if="!fiscal_year"
            class="flex items-start gap-3 rounded-2xl border border-red-500/20 bg-red-500/5 p-4 text-red-700 dark:text-red-300"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="text-sm font-bold">سال مالی جاری باز نیست</p>
                <p class="mt-1 text-xs leading-6">
                    ابتدا از بخش سال‌های مالی یک سال باز و جاری تعیین کنید.
                </p>
            </div>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <section
                class="grid gap-4 rounded-2xl border border-border/70 bg-card p-5 shadow-sm md:grid-cols-3"
            >
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >نوع سند</span
                    >
                    <select
                        v-model="form.entry_kind"
                        :disabled="Boolean(entry)"
                        class="h-11 w-full rounded-xl border border-input bg-background px-3 text-sm disabled:opacity-60"
                        @change="changeKind"
                    >
                        <option value="manual">سند دستی</option>
                        <option value="opening">مانده افتتاحیه</option>
                    </select>
                    <InputError :message="form.errors.entry_kind" />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >تاریخ سند</span
                    >
                    <JalaliDatePicker
                        v-model="form.entry_date"
                        :min="fiscal_year?.starts_on"
                        :max="fiscal_year?.ends_on"
                        required
                    />
                    <InputError :message="form.errors.entry_date" />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >شرح سند</span
                    >
                    <input
                        v-model="form.description"
                        type="text"
                        maxlength="1000"
                        class="h-11 w-full rounded-xl border border-input bg-background px-3 text-sm"
                        placeholder="شرح رویداد مالی..."
                    />
                    <InputError :message="form.errors.description" />
                </label>
            </section>

            <div
                v-if="isOpening"
                class="flex items-start gap-3 rounded-2xl border border-violet-500/20 bg-violet-500/5 p-4 text-violet-700 dark:text-violet-300"
            >
                <CalendarDays class="mt-0.5 size-5 shrink-0" />
                <p class="text-xs leading-6">
                    مانده افتتاحیه فقط در اولین روز سال مالی، یک‌بار برای هر
                    شعبه و قبل از هر گردش ثبت‌شده قابل ایجاد است. موجودی کالا از
                    این مسیر ثبت نمی‌شود و در مرحله موجودی‌گیری با حرکت انبار
                    ثبت خواهد شد.
                </p>
            </div>

            <section
                class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
            >
                <div
                    class="flex items-center justify-between border-b border-border/70 px-5 py-4"
                >
                    <div>
                        <h2 class="flex items-center gap-2 text-sm font-bold">
                            <Layers3 class="size-4 text-primary" />
                            ردیف‌های سند
                        </h2>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            هر ردیف فقط بدهکار یا بستانکار دارد.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="rounded-xl"
                        @click="addLine"
                    >
                        <Plus class="size-4" />
                        ردیف جدید
                    </Button>
                </div>

                <div class="space-y-3 p-4">
                    <div
                        v-for="(line, index) in form.lines"
                        :key="index"
                        class="grid gap-3 rounded-xl border border-border/70 bg-muted/15 p-3 xl:grid-cols-[2fr_1.3fr_1fr_1fr_1.5fr_auto]"
                    >
                        <label class="space-y-1">
                            <span
                                class="text-[10px] font-semibold text-muted-foreground"
                                >حساب</span
                            >
                            <select
                                v-model="line.account_id"
                                class="h-10 w-full rounded-lg border border-input bg-background px-2 text-xs"
                                @change="changeAccount(line)"
                            >
                                <option value="">انتخاب حساب</option>
                                <option
                                    v-for="account in availableAccounts"
                                    :key="account.id"
                                    :value="account.id.toString()"
                                >
                                    {{ account.code }} — {{ account.name }}
                                </option>
                            </select>
                            <InputError
                                :message="lineError(index, 'account_id')"
                            />
                        </label>
                        <label class="space-y-1">
                            <span
                                class="text-[10px] font-semibold text-muted-foreground"
                                >طرف‌حساب</span
                            >
                            <select
                                v-model="line.party_id"
                                :required="
                                    selectedAccount(line)?.requires_party
                                "
                                :disabled="
                                    !selectedAccount(line)?.requires_party
                                "
                                class="h-10 w-full rounded-lg border border-input bg-background px-2 text-xs disabled:opacity-45"
                            >
                                <option value="">بدون طرف‌حساب</option>
                                <option
                                    v-for="party in parties"
                                    :key="party.id"
                                    :value="party.id.toString()"
                                >
                                    {{ party.name }} — {{ party.code }}
                                </option>
                            </select>
                            <InputError
                                :message="lineError(index, 'party_id')"
                            />
                        </label>
                        <label class="space-y-1">
                            <span
                                class="text-[10px] font-semibold text-muted-foreground"
                                >بدهکار</span
                            >
                            <input
                                v-model="line.debit"
                                type="text"
                                inputmode="decimal"
                                class="h-10 w-full rounded-lg border border-input bg-background px-2 text-left text-xs tabular-nums"
                                @input="setDebit(line)"
                            />
                            <InputError :message="lineError(index, 'debit')" />
                        </label>
                        <label class="space-y-1">
                            <span
                                class="text-[10px] font-semibold text-muted-foreground"
                                >بستانکار</span
                            >
                            <input
                                v-model="line.credit"
                                type="text"
                                inputmode="decimal"
                                class="h-10 w-full rounded-lg border border-input bg-background px-2 text-left text-xs tabular-nums"
                                @input="setCredit(line)"
                            />
                            <InputError :message="lineError(index, 'credit')" />
                        </label>
                        <label class="space-y-1">
                            <span
                                class="text-[10px] font-semibold text-muted-foreground"
                                >شرح ردیف</span
                            >
                            <input
                                v-model="line.description"
                                type="text"
                                maxlength="500"
                                class="h-10 w-full rounded-lg border border-input bg-background px-2 text-xs"
                            />
                            <InputError
                                :message="lineError(index, 'description')"
                            />
                        </label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="mt-[18px] size-10 text-red-600"
                            :disabled="form.lines.length <= 2"
                            aria-label="حذف ردیف"
                            @click="removeLine(index)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>

                <div
                    class="grid gap-3 border-t border-border/70 bg-muted/25 px-5 py-4 text-xs sm:grid-cols-3"
                >
                    <p>
                        جمع بدهکار:
                        <strong class="mr-1 tabular-nums">{{
                            money.format(debitTotal)
                        }}</strong>
                    </p>
                    <p>
                        جمع بستانکار:
                        <strong class="mr-1 tabular-nums">{{
                            money.format(creditTotal)
                        }}</strong>
                    </p>
                    <p
                        :class="
                            difference > 0.009
                                ? 'text-red-600'
                                : 'text-emerald-600'
                        "
                    >
                        اختلاف:
                        <strong class="mr-1 tabular-nums">{{
                            money.format(difference)
                        }}</strong>
                    </p>
                </div>
            </section>

            <InputError :message="journalError" />

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button as-child variant="outline" class="rounded-xl">
                    <Link
                        :href="entry ? showJournal(entry.id) : journalsIndex()"
                    >
                        انصراف
                    </Link>
                </Button>
                <Button
                    type="submit"
                    class="rounded-xl px-6"
                    :disabled="form.processing || !fiscal_year"
                >
                    <Save class="size-4" />
                    {{ entry ? 'ذخیره تغییرات' : 'ثبت پیش‌نویس' }}
                </Button>
            </div>
        </form>
    </div>
</template>
