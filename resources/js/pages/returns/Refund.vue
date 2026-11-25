<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Save } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { show as showSale } from '@/routes/sales';
import { show as showPurchase } from '@/routes/purchases';
import { store } from '@/routes/returns/refund';

const props = defineProps<{
    invoice: {
        id: number;
        number: string;
        type: string;
        total: string;
        remaining: string;
    };
    treasury_accounts: Array<{ id: number; name: string; type: string }>;
    default_date: string;
}>();
const form = useForm({
    treasury_account_id: props.treasury_accounts[0]?.id.toString() ?? '',
    refund_date: props.default_date,
    amount: props.invoice.remaining,
    submission_token: crypto.randomUUID(),
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const backUrl =
    props.invoice.type === 'sales_return'
        ? showSale(props.invoice.id)
        : showPurchase(props.invoice.id);
</script>

<template>
    <Head title="refund مرجوعی" />
    <div class="mx-auto w-full max-w-2xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section class="flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black">refund مرجوعی</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    مرجوعی {{ invoice.number }} — مانده قابل refund:
                    {{ invoice.remaining }}
                </p>
            </div>
            <Button as-child variant="outline" class="rounded-xl">
                <Link :href="backUrl">
                    <ArrowRight class="size-4" /> بازگشت
                </Link>
            </Button>
        </section>
        <form
            class="space-y-5 rounded-2xl border bg-card p-5 sm:p-6"
            @submit.prevent="form.post(store(invoice.id).url)"
        >
            <div
                v-if="fieldError('refund')"
                class="rounded-xl bg-red-500/10 p-3 text-xs text-red-700 dark:text-red-300"
            >
                {{ fieldError('refund') }}
            </div>
            <div class="grid gap-2">
                <Label for="treasury_account_id">حساب خزانه *</Label>
                <select
                    id="treasury_account_id"
                    v-model="form.treasury_account_id"
                    class="h-10 rounded-md border bg-background px-3 text-sm"
                >
                    <option
                        v-for="account in treasury_accounts"
                        :key="account.id"
                        :value="account.id"
                    >
                        {{ account.name }} ({{ account.type }})
                    </option>
                </select>
                <InputError :message="fieldError('treasury_account_id')" />
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="refund_date">تاریخ *</Label>
                    <Input
                        id="refund_date"
                        v-model="form.refund_date"
                        type="date"
                    />
                    <InputError :message="fieldError('refund_date')" />
                </div>
                <div class="grid gap-2">
                    <Label for="amount">مبلغ *</Label>
                    <Input
                        id="amount"
                        v-model="form.amount"
                        inputmode="decimal"
                    />
                    <InputError :message="fieldError('amount')" />
                </div>
            </div>
            <p
                class="rounded-xl bg-muted/40 p-3 text-xs leading-6 text-muted-foreground"
            >
                refund در همان transaction سند خزانهٔ متوازن ایجاد می‌کند.
                ماندهٔ طرف‌حساب پس از مرجوعی پرداخت‌شده به‌صورت علامت‌دار گزارش
                می‌شود.
            </p>
            <div class="flex justify-end">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    ثبت refund
                </Button>
            </div>
        </form>
    </div>
</template>
