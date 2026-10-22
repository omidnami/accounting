<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarDays,
    CircleDollarSign,
    ReceiptText,
    Sparkles,
    TrendingUp,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'داشبورد',
                href: dashboard(),
            },
        ],
    },
});

const user = computed(() => usePage().props.auth.user);

const stats = [
    {
        label: 'موجودی نقد',
        value: '—',
        hint: 'پس از ثبت اولین تراکنش',
        icon: Wallet,
        tone: 'bg-primary/10 text-primary',
    },
    {
        label: 'فروش امروز',
        value: '—',
        hint: 'فروش امروز شما',
        icon: CircleDollarSign,
        tone: 'bg-amber-400/15 text-amber-600 dark:text-amber-400',
    },
    {
        label: 'فاکتورهای باز',
        value: '—',
        hint: 'در انتظار بررسی',
        icon: ReceiptText,
        tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    },
];
</script>

<template>
    <Head title="داشبورد" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.08)_100%)] p-6 sm:p-8"
        >
            <div
                class="pointer-events-none absolute -top-20 left-4 size-64 rounded-full bg-primary/10 blur-3xl"
            />
            <div
                class="relative flex flex-col justify-between gap-6 sm:flex-row sm:items-center"
            >
                <div>
                    <div
                        class="mb-3 inline-flex items-center gap-2 rounded-full border border-primary/15 bg-card/70 px-3 py-1 text-[11px] font-semibold text-primary backdrop-blur"
                    >
                        <Sparkles class="size-3.5" />
                        فضای کاری شما آماده است
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        سلام {{ user.name }}، خوش آمدید
                    </h1>
                    <p
                        class="mt-2 max-w-xl text-sm leading-7 text-muted-foreground"
                    >
                        از اینجا می‌توانید حساب‌های فروشگاه را منظم کنید و تصویر
                        روشنی از وضعیت مالی داشته باشید.
                    </p>
                </div>
                <Button variant="outline" class="h-10 rounded-xl bg-card/70">
                    راهنمای شروع
                    <ArrowLeft class="size-4" />
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <article
                v-for="stat in stats"
                :key="stat.label"
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-medium text-muted-foreground">
                            {{ stat.label }}
                        </p>
                        <p class="mt-3 text-2xl font-black">{{ stat.value }}</p>
                    </div>
                    <span
                        class="flex size-10 items-center justify-center rounded-xl"
                        :class="stat.tone"
                    >
                        <component :is="stat.icon" class="size-5" />
                    </span>
                </div>
                <p class="mt-4 text-[11px] text-muted-foreground">
                    {{ stat.hint }}
                </p>
            </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-[1.55fr_1fr]">
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold">روند فعالیت مالی</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            پس از ثبت داده‌ها، گزارش‌ها اینجا نمایش داده
                            می‌شوند.
                        </p>
                    </div>
                    <span
                        class="flex items-center gap-1.5 rounded-lg bg-muted px-2.5 py-1 text-[10px] text-muted-foreground"
                    >
                        <CalendarDays class="size-3" />
                        این ماه
                    </span>
                </div>

                <div class="mt-8 flex h-52 items-end gap-3 sm:gap-4">
                    <div
                        v-for="height in [
                            32, 48, 42, 65, 54, 78, 61, 83, 69, 92, 76, 88,
                        ]"
                        :key="height"
                        class="group flex h-full flex-1 items-end"
                    >
                        <span
                            class="w-full rounded-t-lg bg-primary/15 transition-colors group-hover:bg-primary/30"
                            :style="{ height: `${height}%` }"
                        />
                    </div>
                </div>
                <div
                    class="mt-4 flex justify-between border-t border-dashed border-border pt-4 text-[10px] text-muted-foreground"
                >
                    <span>فروردین</span>
                    <span>خرداد</span>
                    <span>شهریور</span>
                    <span>آذر</span>
                    <span>اسفند</span>
                </div>
            </article>

            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold">شروع سریع</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            حساب‌یار را برای خود آماده کنید.
                        </p>
                    </div>
                    <TrendingUp class="size-5 text-primary" />
                </div>

                <div class="mt-6 space-y-4">
                    <div
                        v-for="(item, index) in [
                            'اطلاعات فروشگاه را کامل کنید',
                            'اولین حساب مالی را ثبت کنید',
                            'گزارش‌های فروش را بررسی کنید',
                        ]"
                        :key="item"
                        class="flex gap-3"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-xs font-bold text-primary"
                            >{{ index + 1 }}</span
                        >
                        <div class="pt-1">
                            <p class="text-xs font-semibold">{{ item }}</p>
                            <p
                                class="mt-1 text-[10px] leading-5 text-muted-foreground"
                            >
                                {{
                                    index === 0
                                        ? 'برای شروع، اطلاعات پایه حساب را تکمیل کنید.'
                                        : 'این بخش پس از تکمیل مراحل قبل فعال می‌شود.'
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </article>
        </section>
    </div>
</template>
