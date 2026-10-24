<script setup lang="ts">
import { Check, ReceiptText } from '@lucide/vue';
import { ref } from 'vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { posRequest, type PendingCheck } from '@/lib/pos';
import { register } from '@/routes/pos/checks';

const props = defineProps<{
    open: boolean;
    checks: PendingCheck[];
    today: string;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean]; resolved: [] }>();
const selected = ref<PendingCheck | null>(null);
const number = ref('');
const bank = ref('');
const dueDate = ref('');
const receiptDate = ref(props.today);
const busy = ref(false);
const error = ref('');
const money = (value: string) => Number(value).toLocaleString('fa-IR');
function select(check: PendingCheck) {
    selected.value = check;
    number.value = check.check_number || '';
    bank.value = check.bank_name || '';
    dueDate.value = check.due_date || '';
    receiptDate.value = props.today;
    error.value = '';
}
async function resolve() {
    if (!selected.value || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        await posRequest(register.url(selected.value.id), {
            check_number: number.value,
            bank_name: bank.value,
            due_date: dueDate.value,
            receipt_date: receiptDate.value,
        });
        selected.value = null;
        emit('resolved');
    } catch (exception) {
        error.value =
            exception instanceof Error ? exception.message : 'ثبت کامل نشد.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <Dialog :open="open" @update:open="!busy && emit('update:open', $event)">
        <DialogContent
            class="pos-dialog max-h-[90dvh] overflow-y-auto sm:max-w-3xl"
            dir="rtl"
        >
            <DialogHeader class="text-right"
                ><DialogTitle>رسیدگی به چک‌های صندوق</DialogTitle
                ><DialogDescription
                    >با ثبت دریافت، چک به خزانه و دریافت آن به فاکتور اصلی متصل
                    می‌شود.</DialogDescription
                ></DialogHeader
            >
            <form v-if="selected" class="space-y-4" @submit.prevent="resolve">
                <div class="pos-payment-total">
                    <div>
                        <strong>{{ selected.customer_name }}</strong>
                        <p class="mt-1 text-sm">
                            فاکتور {{ selected.invoice_number }}
                        </p>
                    </div>
                    <strong>{{ money(selected.amount) }} ریال</strong>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-2 text-sm"
                        >شماره / شناسه چک<input
                            v-model="number"
                            class="pos-input"
                            required
                            maxlength="100"
                    /></label>
                    <label class="space-y-2 text-sm"
                        >نام بانک<input
                            v-model="bank"
                            class="pos-input"
                            required
                            maxlength="100"
                    /></label>
                    <label class="space-y-2 text-sm"
                        >سررسید چک<JalaliDatePicker v-model="dueDate" required
                    /></label>
                    <label class="space-y-2 text-sm"
                        >تاریخ دریافت<JalaliDatePicker
                            v-model="receiptDate"
                            required
                    /></label>
                </div>
                <p class="text-sm leading-7 text-slate-500">
                    ثبت نهایی به معنی تحویل و پذیرش چک توسط حسابدار است. وصول
                    بانکی از بخش چک‌ها انجام می‌شود.
                </p>
                <p v-if="error" class="pos-error" role="alert">{{ error }}</p>
                <div class="flex gap-3">
                    <button
                        type="submit"
                        class="pos-primary flex-1"
                        :disabled="busy"
                    >
                        <Check :size="18" />{{
                            busy ? 'در حال ثبت…' : 'ثبت چک و دریافت'
                        }}</button
                    ><button
                        type="button"
                        class="pos-secondary"
                        :disabled="busy"
                        @click="selected = null"
                    >
                        بازگشت
                    </button>
                </div>
            </form>
            <template v-else>
                <div
                    v-if="!checks.length"
                    class="py-12 text-center text-slate-500"
                >
                    <ReceiptText class="mx-auto mb-3" :size="36" />چک منتظر
                    رسیدگی ندارید.
                </div>
                <button
                    v-for="check in checks"
                    :key="check.id"
                    type="button"
                    class="pos-review-row"
                    @click="select(check)"
                >
                    <div class="text-right">
                        <strong>{{ check.customer_name }}</strong>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ check.invoice_number }} ·
                            {{ check.cashier_name }}
                        </p>
                        <p
                            v-if="check.notes"
                            class="mt-1 text-xs text-slate-500"
                        >
                            {{ check.notes }}
                        </p>
                    </div>
                    <div class="text-left">
                        <strong>{{ money(check.amount) }} ریال</strong>
                        <p
                            class="mt-1 text-xs"
                            :class="
                                check.check_received
                                    ? 'text-emerald-700'
                                    : 'text-amber-700'
                            "
                        >
                            {{
                                check.check_received
                                    ? 'چک تحویل گرفته شده'
                                    : 'در انتظار تحویل مشتری'
                            }}
                        </p>
                    </div>
                </button>
                <p v-if="checks.length === 100" class="text-xs text-slate-500">
                    ۱۰۰ مورد قدیمی‌تر نمایش داده می‌شود؛ پس از رسیدگی، موارد
                    بعدی بارگذاری می‌شوند.
                </p>
            </template>
        </DialogContent>
    </Dialog>
</template>
