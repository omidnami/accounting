<script setup lang="ts">
import {
    Banknote,
    Check,
    CreditCard,
    Layers,
    NotebookPen,
    ReceiptText,
    UserRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    amountString,
    cents,
    type PosCustomer,
    type PosTender,
} from '@/lib/pos';
import { normalizeNumber } from '@/lib/number';

const props = defineProps<{
    open: boolean;
    blocked?: boolean;
    total: string;
    customer: PosCustomer | null;
    cardAvailable: boolean;
    today: string;
    busy: boolean;
    error: string;
}>();
const emit = defineEmits<{
    'update:open': [value: boolean];
    submit: [tenders: PosTender[], dueDate: string];
}>();
const mode = ref('cash');
const dueDate = ref('');
const methods = [
    { key: 'cash', label: 'نقد', icon: Banknote },
    { key: 'card', label: 'کارت', icon: CreditCard },
    { key: 'check', label: 'چک', icon: ReceiptText },
    { key: 'credit', label: 'نسیه', icon: NotebookPen },
    { key: 'mixed', label: 'ترکیبی', icon: Layers },
];
const rows = ref<PosTender[]>([]);
const blank = (method: PosTender['method'], amount = ''): PosTender => ({
    method,
    amount,
    confirmed: false,
    reference: '',
    check_received: true,
    check_number: '',
    bank_name: '',
    due_date: '',
    notes: '',
});
function resetRows() {
    rows.value =
        mode.value === 'mixed'
            ? [
                  blank('cash', props.total),
                  blank('card'),
                  blank('check'),
                  blank('credit'),
              ]
            : [blank(mode.value as PosTender['method'], props.total)];
}
watch(mode, resetRows);
watch(
    () => props.open,
    (open) => {
        if (open) {
            mode.value = 'cash';
            dueDate.value = '';
            resetRows();
        }
    },
);
const activeRows = computed(() =>
    rows.value.filter((row) => cents(row.amount) > 0n),
);
const allocated = computed(() =>
    activeRows.value.reduce((sum, row) => sum + cents(row.amount), 0n),
);
const difference = computed(() => cents(props.total) - allocated.value);
const needsCustomer = computed(() =>
    activeRows.value.some((row) => ['check', 'credit'].includes(row.method)),
);
const invalid = computed(
    () =>
        difference.value !== 0n ||
        activeRows.value.length === 0 ||
        (needsCustomer.value && !props.customer) ||
        activeRows.value.some(
            (row) =>
                (['cash', 'card'].includes(row.method) && !row.confirmed) ||
                (row.method === 'card' && !props.cardAvailable) ||
                (row.method === 'credit' && !dueDate.value),
        ),
);
const money = (value: string) =>
    new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(
        Number(value),
    );
function submit() {
    if (!invalid.value && !props.busy) {
        emit(
            'submit',
            activeRows.value.map((row) => ({
                ...row,
                amount: normalizeNumber(row.amount),
            })),
            dueDate.value,
        );
    }
}
</script>

<template>
    <Dialog
        :open="open && !blocked"
        @update:open="!blocked && !busy && emit('update:open', $event)"
    >
        <DialogContent
            class="pos-dialog max-h-[92dvh] overflow-y-auto sm:max-w-3xl"
            dir="rtl"
        >
            <DialogHeader class="text-right">
                <DialogTitle class="text-xl">تکمیل فروش</DialogTitle>
                <DialogDescription
                    >روش دریافت را مشخص کنید؛ فاکتور با ثبت نهایی قطعی
                    می‌شود.</DialogDescription
                >
            </DialogHeader>
            <div class="pos-payment-total">
                <span class="flex items-center gap-2 text-sm"
                    ><UserRound :size="18" />{{
                        customer?.name || 'مشتری نقدی'
                    }}</span
                >
                <div>
                    <strong class="text-3xl tabular-nums">{{
                        money(total)
                    }}</strong
                    ><small class="mr-2">ریال</small>
                </div>
            </div>
            <div
                class="grid grid-cols-5 gap-2"
                role="group"
                aria-label="روش پرداخت"
            >
                <button
                    v-for="method in methods"
                    :key="method.key"
                    type="button"
                    class="pos-method"
                    :class="{ selected: mode === method.key }"
                    :disabled="
                        busy || (method.key === 'card' && !cardAvailable)
                    "
                    :aria-pressed="mode === method.key"
                    @click="mode = method.key"
                >
                    <component :is="method.icon" :size="23" /><span>{{
                        method.label
                    }}</span>
                </button>
            </div>
            <p v-if="needsCustomer && !customer" class="pos-error">
                برای چک یا نسیه، ابتدا مشتری مشخص انتخاب کنید.
            </p>
            <form class="space-y-3" @submit.prevent="submit">
                <fieldset
                    v-for="(row, index) in rows"
                    :key="row.method"
                    class="pos-tender"
                    :disabled="busy"
                >
                    <div class="flex items-center justify-between gap-3">
                        <label :for="`tender-${index}`" class="font-semibold">{{
                            methods.find((m) => m.key === row.method)?.label
                        }}</label>
                        <div class="relative w-48">
                            <input
                                :id="`tender-${index}`"
                                v-model="row.amount"
                                class="pos-input pos-amount-input text-left font-bold"
                                inputmode="decimal"
                                dir="ltr"
                                placeholder="۰"
                                :aria-label="`مبلغ ${methods.find((m) => m.key === row.method)?.label}`"
                            /><small
                                class="absolute top-3 right-3 text-slate-400"
                                >ریال</small
                            >
                        </div>
                    </div>
                    <template v-if="cents(row.amount) > 0n">
                        <label
                            v-if="
                                row.method === 'cash' || row.method === 'card'
                            "
                            class="mt-3 flex cursor-pointer items-center gap-2 text-sm"
                        >
                            <input
                                v-model="row.confirmed"
                                type="checkbox"
                                class="size-5 accent-emerald-700"
                            />
                            {{
                                row.method === 'cash'
                                    ? 'وجه نقد را تحویل گرفتم.'
                                    : 'پرداخت موفق کارت‌خوان را تأیید می‌کنم.'
                            }}
                        </label>
                        <input
                            v-if="row.method === 'card'"
                            v-model="row.reference"
                            class="pos-input mt-3"
                            aria-label="شماره پیگیری کارت"
                            placeholder="شماره پیگیری (اختیاری)"
                            maxlength="100"
                        />
                        <p
                            v-if="row.method === 'card' && !cardAvailable"
                            class="pos-error mt-2"
                        >
                            برای این شیفت کارت‌خوان انتخاب نشده است.
                        </p>
                        <template v-if="row.method === 'check'">
                            <select
                                v-model="row.check_received"
                                class="pos-input mt-3"
                                aria-label="وضعیت تحویل چک"
                            >
                                <option :value="true">چک تحویل گرفته شد</option>
                                <option :value="false">
                                    مشتری بعداً چک را تحویل می‌دهد
                                </option>
                            </select>
                            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                <input
                                    v-model="row.check_number"
                                    class="pos-input"
                                    placeholder="شماره / شناسه چک"
                                    aria-label="شماره چک"
                                    maxlength="100"
                                />
                                <input
                                    v-model="row.bank_name"
                                    class="pos-input"
                                    placeholder="نام بانک"
                                    aria-label="نام بانک"
                                    maxlength="100"
                                />
                                <JalaliDatePicker
                                    v-model="row.due_date"
                                    placeholder="سررسید چک"
                                />
                            </div>
                            <input
                                v-model="row.notes"
                                class="pos-input mt-3"
                                placeholder="یادداشت برای حسابدار (اختیاری)"
                                maxlength="2000"
                                aria-label="یادداشت چک"
                            />
                            <p class="mt-3 text-xs leading-6 text-amber-700">
                                این مبلغ تا ثبت دریافت چکی توسط حسابدار، در
                                ماندهٔ فاکتور باقی می‌ماند.
                            </p>
                        </template>
                        <div
                            v-if="row.method === 'credit'"
                            class="mt-3 space-y-2"
                        >
                            <label class="text-sm">موعد پرداخت نسیه</label
                            ><JalaliDatePicker
                                v-model="dueDate"
                                :min="today"
                                required
                            />
                            <p class="text-xs text-slate-500">
                                مانده روی حساب همین مشتری نگهداری می‌شود.
                            </p>
                        </div>
                    </template>
                </fieldset>
                <div v-if="difference !== 0n" class="pos-error">
                    {{ difference > 0n ? 'مبلغ تخصیص‌نیافته' : 'مبلغ اضافه' }}:
                    {{
                        money(
                            amountString(
                                difference > 0n ? difference : -difference,
                            ),
                        )
                    }}
                    ریال
                </div>
                <p v-if="error" role="alert" class="pos-error">{{ error }}</p>
                <button
                    type="submit"
                    class="pos-primary w-full"
                    :disabled="invalid || busy"
                >
                    <Check :size="20" />{{
                        busy ? 'در حال ثبت فروش…' : 'ثبت نهایی فروش'
                    }}
                </button>
            </form>
        </DialogContent>
    </Dialog>
</template>
