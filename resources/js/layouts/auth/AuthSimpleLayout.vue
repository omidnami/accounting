<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, ReceiptText, ShieldCheck, Sparkles } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div dir="rtl" class="relative min-h-svh overflow-hidden bg-background">
        <div
            class="pointer-events-none absolute -top-52 right-[-9rem] size-[30rem] rounded-full bg-primary/10 blur-3xl"
        />
        <div
            class="pointer-events-none absolute -bottom-64 left-[-10rem] size-[32rem] rounded-full bg-amber-300/15 blur-3xl dark:bg-amber-400/5"
        />

        <div
            class="relative mx-auto grid min-h-svh w-full max-w-6xl items-stretch lg:grid-cols-[.95fr_1.05fr] lg:p-6"
        >
            <section
                class="flex min-h-svh flex-col px-5 py-6 sm:px-10 lg:min-h-0 lg:rounded-[2rem] lg:border lg:border-border/70 lg:bg-card/85 lg:px-14 lg:py-14 lg:shadow-[0_30px_80px_-44px_rgba(15,23,42,.35)] lg:backdrop-blur"
            >
                <div class="flex items-center justify-between">
                    <Link :href="home()" class="flex items-center">
                        <AppLogo />
                    </Link>
                    <Link
                        :href="home()"
                        class="text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        بازگشت به صفحه اصلی
                    </Link>
                </div>

                <div
                    class="mx-auto flex w-full max-w-[25rem] flex-1 flex-col justify-center py-10"
                >
                    <div class="mb-8 text-right">
                        <div
                            class="mb-4 flex size-11 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                        >
                            <Sparkles class="size-5" />
                        </div>
                        <h1
                            class="text-2xl font-black tracking-tight text-balance sm:text-3xl"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="mt-3 text-sm leading-7 text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>

                    <slot />
                </div>

                <p
                    class="mt-6 text-center text-[11px] leading-6 text-muted-foreground"
                >
                    اطلاعات حساب شما با احراز هویت امن محافظت می‌شود.
                </p>
            </section>

            <aside
                class="relative hidden overflow-hidden lg:my-3 lg:ml-0 lg:flex lg:flex-col lg:justify-between lg:rounded-[2rem] lg:bg-[linear-gradient(145deg,#092f2d_0%,#0c453d_56%,#176b59_100%)] lg:p-12 lg:text-white"
            >
                <div
                    class="absolute -top-28 -left-20 size-80 rounded-full border border-white/10"
                />
                <div
                    class="absolute -top-10 left-12 size-52 rounded-full border border-white/10"
                />
                <div
                    class="absolute right-[-5rem] bottom-[-7rem] size-72 rounded-full bg-white/5 blur-2xl"
                />

                <div class="relative">
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-medium text-white/80"
                    >
                        <span class="size-1.5 rounded-full bg-emerald-300" />
                        حساب‌هایتان همیشه منظم
                    </span>
                    <h2
                        class="mt-8 max-w-md text-3xl leading-[1.5] font-black text-balance"
                    >
                        وقت بیشتری برای فروش، وقت کمتری برای سردرگمی.
                    </h2>
                    <p class="mt-4 max-w-md text-sm leading-8 text-white/60">
                        یک محیط آرام و منظم برای ثبت حساب‌ها، بررسی گزارش‌ها و
                        پیش‌بردن کسب‌وکار.
                    </p>
                </div>

                <div class="relative mt-12 space-y-3">
                    <div
                        v-for="item in [
                            'نمای شفاف از وضعیت مالی',
                            'طراحی فارسی و راست‌چین',
                            'امنیت و کنترل دسترسی',
                        ]"
                        :key="item"
                        class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.07] px-4 py-3.5 backdrop-blur"
                    >
                        <span
                            class="flex size-8 items-center justify-center rounded-xl bg-white/10"
                        >
                            <Check class="size-4 text-emerald-300" />
                        </span>
                        <span class="text-sm text-white/85">{{ item }}</span>
                    </div>
                </div>

                <div
                    class="relative mt-12 flex items-center justify-between rounded-2xl border border-white/10 bg-black/10 p-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-xl bg-white/10"
                        >
                            <ReceiptText class="size-4 text-amber-300" />
                        </span>
                        <div>
                            <p class="text-xs font-bold">همه‌چیز از یک‌جا</p>
                            <p class="mt-0.5 text-[10px] text-white/50">
                                ساده، دقیق و قابل اتکا
                            </p>
                        </div>
                    </div>
                    <ShieldCheck class="size-5 text-white/35" />
                </div>
            </aside>
        </div>
    </div>
</template>
