<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CalendarDays,
    Check,
    CircleAlert,
    Landmark,
    ReceiptText,
    Search,
    UserRound,
    WalletCards,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { normalizeNumber, parseNumber } from '@/lib/number';
import {
    create as createCheck,
    index as checksIndex,
    store as storeCheck,
} from '@/routes/checks';
import { suppliers as supplierLookup } from '@/routes/purchases/lookups';
import { customers as customerLookup } from '@/routes/sales/lookups';

type CheckType = 'receivable' | 'payable';
type PartyOption = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
};

const props = defineProps<{
    defaults: {
        type: CheckType;
        issue_date: string;
        due_date: string;
        submission_token: string;
    };
    initial_party: PartyOption | null;
    return_context: {
        kind: 'receipt' | 'payment';
        invoice_ids: string;
    } | null;
}>();

const form = useForm({
    submission_token: props.defaults.submission_token,
    return_to: props.return_context?.kind ?? '',
    invoice_ids: props.return_context?.invoice_ids ?? '',
    type: props.defaults.type,
    customer_id: '',
    supplier_id: '',
    check_number: '',
    bank_name: '',
    branch_name: '',
    amount: '',
    issue_date: props.defaults.issue_date,
    due_date: props.defaults.due_date,
    description: '',
});
const selectedParty = ref<PartyOption | null>(
    props.defaults.type === 'receivable' ? props.initial_party : null,
);
const selectedSupplier = ref<PartyOption | null>(
    props.defaults.type === 'payable' ? props.initial_party : null,
);
const partySearch = ref('');
const partyResults = ref<PartyOption[]>([]);
const partyLoading = ref(false);
const lookupError = ref('');
const clientError = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let requestId = 0;

if (props.defaults.type === 'receivable' && props.initial_party) {
    form.customer_id = props.initial_party.id.toString();
}

if (props.defaults.type === 'payable' && props.initial_party) {
    form.supplier_id = props.initial_party.id.toString();
}

const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const partyLabel = computed(() =>
    form.type === 'receivable' ? 'مشتری' : 'تأمین‌کننده',
);
const partyHint = computed(() =>
    form.type === 'receivable'
        ? 'چک دریافتی به مشتری متصل می‌شود و تا وصول در حساب چک‌های دریافتنی می‌ماند.'
        : 'چک پرداختی به تأمین‌کننده متصل می‌شود و تا خرج‌شدن بدهی بانکی ایجاد نمی‌کند.',
);
const returnLabel = computed(() =>
    props.return_context?.kind === 'receipt'
        ? 'دریافت مشتری'
        : props.return_context?.kind === 'payment'
          ? 'پرداخت تأمین‌کننده'
          : null,
);

const getJson = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error('lookup-failed');
    }

    return (await response.json()) as T;
};
const loadParties = async () => {
    const id = ++requestId;
    partyLoading.value = true;
    lookupError.value = '';

    try {
        const response =
            form.type === 'receivable'
                ? await getJson<{ data: PartyOption[] }>(
                      customerLookup.url({
                          query: {
                              search: partySearch.value.trim() || undefined,
                          },
                      }),
                  )
                : await getJson<{ data: PartyOption[] }>(
                      supplierLookup.url({
                          query: {
                              search: partySearch.value.trim() || undefined,
                          },
                      }),
                  );

        if (id === requestId) {
            partyResults.value = response.data;
        }
    } catch {
        if (id === requestId) {
            partyResults.value = [];
            lookupError.value = `دریافت فهرست ${partyLabel.value} انجام نشد.`;
        }
    } finally {
        if (id === requestId) {
            partyLoading.value = false;
        }
    }
};

watch(partySearch, () => {
    if (timer) {
        clearTimeout(timer);
    }
    timer = setTimeout(loadParties, 250);
});

const setType = (type: CheckType) => {
    form.type = type;
    selectedParty.value = null;
    selectedSupplier.value = null;
    form.customer_id = '';
    form.supplier_id = '';
    partySearch.value = '';
    partyResults.value = [];
    void loadParties();
};

const selectParty = (party: PartyOption) => {
    partyResults.value = [];
    partySearch.value = '';

    if (form.type === 'receivable') {
        selectedParty.value = party;
        form.customer_id = party.id.toString();
    } else {
        selectedSupplier.value = party;
        form.supplier_id = party.id.toString();
    }
};

const changeParty = () => {
    selectedParty.value = null;
    selectedSupplier.value = null;
    form.customer_id = '';
    form.supplier_id = '';
    void loadParties();
};

const submit = () => {
    clientError.value = '';

    if (!selectedParty.value && !selectedSupplier.value) {
        clientError.value = `${partyLabel.value} را انتخاب کنید.`;
        return;
    }

    if (parseNumber(form.amount) <= 0) {
        clientError.value = 'مبلغ چک باید بیشتر از صفر باشد.';
        return;
    }

    if (
        form.issue_date &&
        new Date(`${form.due_date}T00:00:00`) <
            new Date(`${form.issue_date}T00:00:00`)
    ) {
        clientError.value = 'تاریخ سررسید نمی‌تواند قبل از تاریخ صدور باشد.';
        return;
    }

    form.transform((data) => ({
        ...data,
        amount: normalizeNumber(data.amount),
        customer_id:
            data.type === 'receivable' ? Number(data.customer_id) : undefined,
        supplier_id:
            data.type === 'payable' ? Number(data.supplier_id) : undefined,
    })).post(storeCheck().url, { preserveScroll: true });
};

onMounted(() => {
    if (!selectedParty.value && !selectedSupplier.value) {
        void loadParties();
    }
});
onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'چک‌ها', href: checksIndex() },
            { title: 'ثبت چک', href: createCheck() },
        ],
    },
});
</script>

<template>
    <Head title="ثبت چک جدید" />

    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <WalletCards class="size-5" />
                        <span class="text-xs font-bold">ثبت در دفتر چک</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        یک چک جدید ثبت کنید
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        ثبت چک هنوز اثر نقدی ایجاد نمی‌کند. پس از اتصال به
                        دریافت یا پرداخت، وصول، خرج و برگشت از صفحه جزئیات چک
                        انجام می‌شود.
                    </p>
                    <p
                        v-if="returnLabel"
                        class="mt-3 rounded-xl bg-primary/10 px-3 py-2 text-[10px] leading-5 text-primary"
                    >
                        پس از ثبت، به فرم {{ returnLabel }} با همان فاکتورها و
                        چک انتخاب‌شده بازمی‌گردید.
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="checksIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <div
            v-if="lookupError"
            class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-200"
        >
            {{ lookupError }}
        </div>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_330px]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <h2 class="text-sm font-bold">نوع چک</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            class="rounded-2xl border p-4 text-right transition"
                            :class="
                                form.type === 'receivable'
                                    ? 'border-primary/40 bg-primary/[.055] ring-2 ring-primary/10'
                                    : 'bg-background'
                            "
                            @click="setType('receivable')"
                        >
                            <UserRound class="mb-3 size-5 text-primary" />
                            <span class="block text-sm font-bold"
                                >چک دریافتی</span
                            >
                            <span
                                class="mt-1 block text-[10px] leading-5 text-muted-foreground"
                                >از مشتری دریافت شده و باید وصول شود.</span
                            >
                        </button>
                        <button
                            type="button"
                            class="rounded-2xl border p-4 text-right transition"
                            :class="
                                form.type === 'payable'
                                    ? 'border-primary/40 bg-primary/[.055] ring-2 ring-primary/10'
                                    : 'bg-background'
                            "
                            @click="setType('payable')"
                        >
                            <Building2 class="mb-3 size-5 text-primary" />
                            <span class="block text-sm font-bold"
                                >چک پرداختی</span
                            >
                            <span
                                class="mt-1 block text-[10px] leading-5 text-muted-foreground"
                                >به تأمین‌کننده داده شده و باید خرج شود.</span
                            >
                        </button>
                    </div>
                    <InputError class="mt-2" :message="form.errors.type" />
                </section>

                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <div class="mb-4 flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <UserRound class="size-5" />
                        </span>
                        <div>
                            <h2 class="text-sm font-bold">{{ partyLabel }}</h2>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ partyHint }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="selectedParty || selectedSupplier"
                        class="flex items-center justify-between rounded-2xl border border-primary/20 bg-primary/[.045] p-4"
                    >
                        <div>
                            <p class="text-sm font-bold">
                                {{ (selectedParty ?? selectedSupplier)?.name }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ (selectedParty ?? selectedSupplier)?.code }}
                                ·
                                {{
                                    (selectedParty ?? selectedSupplier)
                                        ?.phone ?? 'بدون تلفن'
                                }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="changeParty"
                            >تغییر</Button
                        >
                    </div>
                    <div v-else>
                        <div class="relative">
                            <Search
                                class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                            />
                            <Input
                                v-model="partySearch"
                                :placeholder="`جست‌وجوی ${partyLabel}...`"
                                class="h-11 rounded-xl pr-9"
                            />
                        </div>
                        <div
                            class="mt-2 max-h-60 overflow-y-auto rounded-xl border bg-background"
                        >
                            <p
                                v-if="partyLoading"
                                class="px-4 py-3 text-xs text-muted-foreground"
                            >
                                در حال جست‌وجو...
                            </p>
                            <button
                                v-for="party in partyResults"
                                :key="party.id"
                                type="button"
                                class="flex w-full justify-between border-b px-4 py-3 text-right last:border-0 hover:bg-muted/40"
                                @click="selectParty(party)"
                            >
                                <span>
                                    <span class="block text-xs font-semibold">{{
                                        party.name
                                    }}</span>
                                    <span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                        >{{ party.code }} ·
                                        {{ party.phone ?? 'بدون تلفن' }}</span
                                    >
                                </span>
                                <Check class="size-4 text-primary" />
                            </button>
                            <p
                                v-if="!partyLoading && !partyResults.length"
                                class="px-4 py-4 text-xs text-muted-foreground"
                            >
                                {{ partyLabel }} فعالی پیدا نشد.
                            </p>
                        </div>
                    </div>
                    <InputError
                        class="mt-2"
                        :message="
                            form.errors.customer_id ?? form.errors.supplier_id
                        "
                    />
                </section>

                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <h2 class="text-sm font-bold">مشخصات چک</h2>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="check_number">شماره چک *</Label>
                            <Input
                                id="check_number"
                                v-model="form.check_number"
                                dir="ltr"
                                placeholder="مثلاً ۱۲۳۴۵۶"
                                class="h-11 rounded-xl text-left"
                            />
                            <InputError :message="form.errors.check_number" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="amount">مبلغ چک *</Label>
                            <Input
                                id="amount"
                                v-model="form.amount"
                                inputmode="decimal"
                                dir="ltr"
                                placeholder="۰"
                                class="h-11 rounded-xl text-left"
                            />
                            <InputError :message="form.errors.amount" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="bank_name">نام بانک *</Label>
                            <Input
                                id="bank_name"
                                v-model="form.bank_name"
                                placeholder="نام بانک صادرکننده"
                                class="h-11 rounded-xl"
                            />
                            <InputError :message="form.errors.bank_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="branch_name">شعبه بانک</Label>
                            <Input
                                id="branch_name"
                                v-model="form.branch_name"
                                placeholder="نام یا کد شعبه"
                                class="h-11 rounded-xl"
                            />
                            <InputError :message="form.errors.branch_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="issue_date">تاریخ صدور</Label>
                            <div class="relative">
                                <CalendarDays
                                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                                />
                                <Input
                                    id="issue_date"
                                    v-model="form.issue_date"
                                    type="date"
                                    class="h-11 rounded-xl pr-9"
                                />
                            </div>
                            <InputError :message="form.errors.issue_date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="due_date">تاریخ سررسید *</Label>
                            <div class="relative">
                                <CalendarDays
                                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                                />
                                <Input
                                    id="due_date"
                                    v-model="form.due_date"
                                    type="date"
                                    class="h-11 rounded-xl pr-9"
                                />
                            </div>
                            <InputError :message="form.errors.due_date" />
                        </div>
                    </div>
                    <div class="mt-5 grid gap-2">
                        <Label for="description">توضیحات</Label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            placeholder="توضیح اختیاری برای پیگیری چک"
                            class="rounded-xl border border-input bg-background px-4 py-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center gap-2">
                        <ReceiptText class="size-4 text-primary" />
                        <h2 class="text-sm font-bold">خلاصه چک</h2>
                    </div>
                    <div class="mt-5 space-y-3 text-xs">
                        <div class="flex justify-between gap-4">
                            <span class="text-muted-foreground">نوع</span>
                            <span class="font-semibold">
                                {{
                                    form.type === 'receivable'
                                        ? 'دریافتی'
                                        : 'پرداختی'
                                }}
                            </span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-muted-foreground">طرف‌حساب</span>
                            <span class="truncate font-semibold">
                                {{
                                    (selectedParty ?? selectedSupplier)?.name ??
                                    'انتخاب نشده'
                                }}
                            </span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-muted-foreground">مبلغ</span>
                            <span class="font-black">
                                {{ money.format(parseNumber(form.amount)) }}
                                <small class="text-[9px]">ریال</small>
                            </span>
                        </div>
                        <div class="flex items-start gap-2 border-t pt-3">
                            <Landmark
                                class="mt-0.5 size-4 shrink-0 text-primary"
                            />
                            <span
                                class="text-[10px] leading-5 text-muted-foreground"
                            >
                                {{
                                    form.bank_name || 'بانک هنوز وارد نشده است'
                                }}
                                <template v-if="form.branch_name">
                                    · {{ form.branch_name }}
                                </template>
                            </span>
                        </div>
                    </div>
                    <div
                        v-if="clientError || fieldError('check')"
                        class="mt-4 flex items-start gap-2 rounded-xl bg-red-500/10 px-3 py-2 text-[10px] leading-5 text-red-700 dark:text-red-300"
                    >
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                        {{ clientError || fieldError('check') }}
                    </div>
                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <ReceiptText v-else class="size-4" />
                        ثبت چک
                    </Button>
                </section>

                <section
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-4"
                >
                    <p class="text-[10px] leading-6 text-muted-foreground">
                        پس از ثبت، چک با وضعیت «در انتظار» در فهرست چک‌ها قرار
                        می‌گیرد. برای وصول یا خرج، آن را در فرم دریافت یا پرداخت
                        به فاکتورهای باز متصل کنید.
                    </p>
                </section>
            </aside>
        </form>
    </div>
</template>
