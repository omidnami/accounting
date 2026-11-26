<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CircleAlert,
    FileText,
    PencilLine,
    ReceiptText,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { normalizeNumber, parseNumber } from '@/lib/number';
import {
    index as checksIndex,
    show as showCheck,
    update as updateCheck,
} from '@/routes/checks';

type CheckType = 'receivable' | 'payable';

const props = defineProps<{
    check: {
        id: number;
        document_number: string | null;
        check_number: string;
        bank_name: string;
        branch_name: string | null;
        amount: string;
        issue_date: string | null;
        due_date: string;
        type: CheckType;
        party: {
            kind: 'customer' | 'supplier';
            id: number | null;
            code: string | null;
            name: string | null;
            legacy_current: boolean;
        };
        description: string | null;
    };
}>();

const form = useForm({
    check_number: props.check.check_number,
    bank_name: props.check.bank_name,
    branch_name: props.check.branch_name ?? '',
    amount: props.check.amount,
    issue_date: props.check.issue_date ?? '',
    due_date: props.check.due_date,
    description: props.check.description ?? '',
});
const clientError = ref('');
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];

watch(
    () => form.issue_date,
    (value) => {
        if (form.due_date && value && form.due_date < value) {
            form.due_date = '';
        }
    },
);

const submit = () => {
    clientError.value = '';

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
    })).patch(updateCheck(props.check.id).url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'چک‌ها', href: checksIndex() },
            { title: 'جزئیات چک', href: checksIndex() },
            { title: 'ویرایش چک', href: '/checks' },
        ],
    },
});
</script>

<template>
    <Head :title="`ویرایش چک ${check.document_number ?? check.check_number}`" />

    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <PencilLine class="size-5" />
                        <span class="text-xs font-bold"
                            >ویرایش چک در انتظار</span
                        >
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        مشخصات چک را به‌روزرسانی کنید
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        ویرایش فقط پیش از اتصال به دریافت یا پرداخت و برای چک در
                        انتظار مجاز است.
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="showCheck(check.id)">
                        <ArrowRight class="size-4" />
                        بازگشت به جزئیات
                    </Link>
                </Button>
            </div>
        </section>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_330px]"
            @submit.prevent="submit"
        >
            <section
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <ReceiptText class="size-5" />
                    </span>
                    <div>
                        <h2 class="text-sm font-bold">مشخصات چک</h2>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            شناسه خارجی چک در فروشگاه نباید تکراری باشد.
                        </p>
                    </div>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="check_number">شماره چک *</Label>
                        <Input
                            id="check_number"
                            v-model="form.check_number"
                            dir="ltr"
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
                            class="h-11 rounded-xl text-left"
                        />
                        <InputError :message="form.errors.amount" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="bank_name">نام بانک *</Label>
                        <Input
                            id="bank_name"
                            v-model="form.bank_name"
                            class="h-11 rounded-xl"
                        />
                        <InputError :message="form.errors.bank_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="branch_name">شعبه بانک</Label>
                        <Input
                            id="branch_name"
                            v-model="form.branch_name"
                            class="h-11 rounded-xl"
                        />
                        <InputError :message="form.errors.branch_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="issue_date">تاریخ صدور</Label>
                        <JalaliDatePicker
                            id="issue_date"
                            v-model="form.issue_date"
                            placeholder="بدون تاریخ صدور"
                        />
                        <InputError :message="form.errors.issue_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="due_date">تاریخ سررسید *</Label>
                        <JalaliDatePicker
                            id="due_date"
                            v-model="form.due_date"
                            :min="form.issue_date"
                            required
                        />
                        <InputError :message="form.errors.due_date" />
                    </div>
                </div>

                <div class="mt-5 grid gap-2">
                    <Label for="description">توضیحات</Label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="4"
                        class="rounded-xl border border-input bg-background px-4 py-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                    />
                    <InputError :message="form.errors.description" />
                </div>
            </section>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center gap-2">
                        <FileText class="size-4 text-primary" />
                        <h2 class="text-sm font-bold">هویت ثابت چک</h2>
                    </div>
                    <dl class="mt-5 space-y-3 text-xs">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">شماره سند</dt>
                            <dd class="font-mono font-semibold" dir="ltr">
                                {{
                                    check.document_number ?? check.check_number
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">نوع</dt>
                            <dd class="font-semibold">
                                {{
                                    check.type === 'receivable'
                                        ? 'دریافتی'
                                        : 'پرداختی'
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">طرف‌حساب</dt>
                            <dd class="truncate font-semibold">
                                {{ check.party.name ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">مبلغ</dt>
                            <dd class="font-black">
                                {{ money.format(parseNumber(form.amount)) }}
                                <small class="text-[9px]">ریال</small>
                            </dd>
                        </div>
                    </dl>
                    <p
                        class="mt-4 border-t pt-3 text-[10px] leading-6 text-muted-foreground"
                    >
                        نوع، طرف‌حساب، فروشگاه، شعبه سازمانی و شماره سند قابل
                        ویرایش نیستند.
                    </p>
                </section>

                <div
                    v-if="clientError || fieldError('check')"
                    class="flex items-start gap-2 rounded-xl bg-red-500/10 px-3 py-2 text-[10px] leading-5 text-red-700 dark:text-red-300"
                >
                    <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                    {{ clientError || fieldError('check') }}
                </div>
                <Button
                    type="submit"
                    class="h-11 w-full rounded-xl"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <PencilLine v-else class="size-4" />
                    ذخیره تغییرات
                </Button>
            </aside>
        </form>
    </div>
</template>
