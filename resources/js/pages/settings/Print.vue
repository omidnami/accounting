<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Printer, Router as RouterIcon, Store } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    invoicePrintFormatMap,
    invoicePrintFormats,
    type InvoicePrintFormat,
} from '@/lib/printing';
import {
    edit as printSettings,
    update as updatePrintSettings,
} from '@/routes/print-settings';

type BranchOption = {
    id: number;
    code: string;
    name: string;
    is_default: boolean;
    is_active: boolean;
};

const props = defineProps<{
    branches: BranchOption[];
    selected_branch_id: number;
    settings: {
        format: InvoicePrintFormat;
        paper_width_mm: 58 | 80;
        copies: number;
        header_note: string | null;
    };
}>();

const form = useForm({
    branch_id: props.selected_branch_id,
    format: props.settings.format,
    copies: props.settings.copies,
    header_note: props.settings.header_note ?? '',
});
const selectedBranch = computed(() =>
    props.branches.find((branch) => branch.id === props.selected_branch_id),
);
const summary = computed(
    () =>
        `${invoicePrintFormatMap[form.format].label}، ${form.copies} نسخه برای هر چاپ`,
);
const changeBranch = () => {
    router.get(
        printSettings(),
        { branch_id: form.branch_id },
        {
            preserveScroll: true,
            replace: true,
        },
    );
};
const submit = () => {
    form.patch(updatePrintSettings().url, {
        preserveScroll: true,
    });
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'تنظیمات چاپ',
                href: printSettings(),
            },
        ],
    },
});
</script>

<template>
    <Head title="تنظیمات چاپ" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="تنظیمات چاپ فروشگاه"
            description="قالب پیش‌فرض، تعداد نسخه و متن سربرگ فاکتور را برای هر شعبه تعیین کنید."
        />

        <section class="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            <div class="flex items-start gap-3">
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                >
                    <Store class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold">شعبه فعال</p>
                    <p class="mt-1 text-xs leading-6 text-muted-foreground">
                        تنظیمات چاپ به شعبهٔ فاکتور وابسته است؛ هر شعبه می‌تواند
                        چاپگر و کاغذ متفاوتی داشته باشد.
                    </p>
                    <label class="mt-4 block max-w-md">
                        <span class="mb-2 block text-xs font-semibold">
                            انتخاب شعبه
                        </span>
                        <select
                            v-model="form.branch_id"
                            class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                            @change="changeBranch"
                        >
                            <option
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }} — {{ branch.code }}
                                {{ branch.is_active ? '' : '(غیرفعال)' }}
                            </option>
                        </select>
                    </label>
                </div>
            </div>
        </section>

        <form class="grid gap-5 lg:grid-cols-2" @submit.prevent="submit">
            <section class="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
                <div class="flex items-center gap-2">
                    <Printer class="size-5 text-primary" />
                    <h2 class="text-sm font-bold">قالب پیش‌فرض چاپ</h2>
                </div>
                <p class="mt-2 text-xs leading-6 text-muted-foreground">
                    این قالب هنگام بازکردن فاکتور انتخاب می‌شود و کاربر می‌تواند
                    برای همان چاپ آن را تغییر دهد.
                </p>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="format in invoicePrintFormats"
                        :key="format.value"
                        class="cursor-pointer rounded-2xl border p-4 transition"
                        :class="
                            form.format === format.value
                                ? 'border-primary bg-primary/5 ring-2 ring-primary/15'
                                : 'hover:border-primary/35'
                        "
                    >
                        <input
                            v-model="form.format"
                            type="radio"
                            name="format"
                            :value="format.value"
                            class="sr-only"
                        />
                        <span class="flex items-start justify-between gap-3">
                            <span>
                                <span class="block text-sm font-bold">{{
                                    format.label
                                }}</span>
                                <span
                                    class="mt-1 block text-[10px] leading-5 text-muted-foreground"
                                >
                                    {{ format.description }}
                                </span>
                            </span>
                            <span
                                class="flex size-6 shrink-0 items-center justify-center rounded-full border"
                                :class="
                                    form.format === format.value
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'text-transparent'
                                "
                            >
                                <Check class="size-3.5" />
                            </span>
                        </span>
                    </label>
                </div>
                <InputError class="mt-3" :message="form.errors.format" />
            </section>

            <section class="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
                <h2 class="text-sm font-bold">تعداد نسخه</h2>
                <p class="mt-2 text-xs leading-6 text-muted-foreground">
                    در هر بار چاپ، همین تعداد نسخه پشت‌سرهم آماده می‌شود.
                </p>
                <label class="mt-5 block">
                    <span class="mb-2 block text-xs font-semibold">
                        تعداد نسخهٔ فاکتور
                    </span>
                    <select
                        v-model="form.copies"
                        class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                    >
                        <option
                            v-for="copy in [1, 2, 3, 4, 5]"
                            :key="copy"
                            :value="copy"
                        >
                            {{ copy }} نسخه
                        </option>
                    </select>
                </label>
                <InputError class="mt-3" :message="form.errors.copies" />

                <div
                    class="mt-5 rounded-xl border border-primary/15 bg-primary/5 p-3 text-xs leading-6"
                >
                    <p class="font-bold">پیش‌نمایش تنظیمات</p>
                    <p class="mt-1 text-muted-foreground">{{ summary }}</p>
                    <p class="mt-1 text-[10px] text-muted-foreground">
                        انتخاب چاپگر در پنجرهٔ Print سیستم‌عامل انجام می‌شود.
                    </p>
                </div>
            </section>

            <section
                class="rounded-2xl border bg-card p-5 shadow-sm sm:p-6 lg:col-span-2"
            >
                <label class="block">
                    <span class="text-sm font-bold">متن سربرگ فاکتور</span>
                    <span
                        class="mt-1 block text-xs leading-6 text-muted-foreground"
                    >
                        این متن در همهٔ قالب‌ها زیر اطلاعات فروشنده چاپ می‌شود؛
                        مانند شمارهٔ تماس، آدرس یا شرایط فروش.
                    </span>
                    <textarea
                        v-model="form.header_note"
                        rows="3"
                        maxlength="255"
                        class="mt-4 w-full rounded-xl border bg-background px-3 py-2 text-sm"
                        placeholder="متن اختیاری سربرگ فاکتور"
                    />
                </label>
                <InputError class="mt-2" :message="form.errors.header_note" />
            </section>

            <section
                class="rounded-2xl border border-dashed bg-muted/20 p-5 lg:col-span-2"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-background text-muted-foreground"
                    >
                        <RouterIcon class="size-5" />
                    </span>
                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-bold">
                                چاپ خودکار با Print Agent
                            </h2>
                            <span
                                class="rounded-full bg-amber-500/10 px-2 py-1 text-[9px] font-bold text-amber-700"
                            >
                                فاز بعد
                            </span>
                        </div>
                        <p
                            class="mt-2 max-w-3xl text-xs leading-6 text-muted-foreground"
                        >
                            چاپ فعلی از پنجرهٔ مرورگر انجام می‌شود و به درایور
                            سیستم‌عامل نیاز دارد. عامل چاپ اختیاری در فاز بعد
                            امکان چاپ بی‌صدا، صف محلی و گزارش وضعیت چاپگر را
                            بدون بازکردن چاپگر روی اینترنت فراهم می‌کند.
                        </p>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-4 lg:col-span-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="h-11 rounded-xl px-6"
                >
                    ذخیره تنظیمات چاپ
                </Button>
                <p class="text-xs text-muted-foreground">
                    {{ selectedBranch?.name }}
                </p>
            </div>
        </form>
    </div>
</template>
