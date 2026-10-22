<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BarChart3,
    Check,
    ReceiptText,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    Wallet,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const features = [
    {
        icon: TrendingUp,
        title: 'تصویر روشن از فروش',
        description:
            'گردش مالی و عملکرد فروشگاه را در یک نگاه ساده و خوانا دنبال کنید.',
    },
    {
        icon: ReceiptText,
        title: 'حساب‌های منظم',
        description:
            'فاکتورها، پرداخت‌ها و اسناد مالی با ساختاری یکپارچه کنار هم قرار می‌گیرند.',
    },
    {
        icon: ShieldCheck,
        title: 'امنیت از ابتدا',
        description:
            'احراز هویت مدرن و کنترل دسترسی، پایه‌ای مطمئن برای رشد کسب‌وکار است.',
    },
];
</script>

<template>
    <div dir="rtl" class="relative min-h-screen overflow-hidden bg-background">
        <div
            class="pointer-events-none absolute -top-40 right-[-10%] size-[34rem] rounded-full bg-primary/10 blur-3xl"
        />
        <div
            class="pointer-events-none absolute top-[28rem] left-[-14rem] size-[30rem] rounded-full bg-amber-300/15 blur-3xl dark:bg-amber-400/5"
        />

        <header
            class="relative z-20 border-b border-border/60 bg-background/80 backdrop-blur-xl"
        >
            <div
                class="mx-auto flex h-20 w-full max-w-7xl items-center justify-between px-5 sm:px-8"
            >
                <Link
                    :href="dashboard()"
                    class="flex items-center gap-1 text-foreground"
                    aria-label="حساب‌یار"
                >
                    <AppLogo />
                </Link>

                <nav class="flex items-center gap-2">
                    <Button v-if="$page.props.auth.user" as-child>
                        <Link :href="dashboard()">
                            ورود به داشبورد
                            <ArrowLeft class="size-4" />
                        </Link>
                    </Button>
                    <template v-else>
                        <Button variant="ghost" as-child>
                            <Link :href="login()">ورود</Link>
                        </Button>
                        <Button as-child class="hidden sm:inline-flex">
                            <Link :href="register()">شروع رایگان</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <main class="relative z-10">
            <section
                class="mx-auto grid w-full max-w-7xl items-center gap-14 px-5 py-16 sm:px-8 lg:grid-cols-[1.02fr_.98fr] lg:py-24"
            >
                <div class="max-w-2xl text-right">
                    <div
                        class="mb-6 inline-flex items-center gap-2 rounded-full border border-primary/15 bg-primary/8 px-3.5 py-1.5 text-xs font-semibold text-primary"
                    >
                        <Sparkles class="size-3.5" />
                        حسابداری، ساده‌تر از همیشه
                    </div>

                    <h1
                        class="text-4xl leading-[1.35] font-black tracking-[-0.03em] text-balance sm:text-5xl lg:text-[3.65rem]"
                    >
                        فروشگاه را دقیق ببینید؛
                        <span class="relative whitespace-nowrap text-primary">
                            با خیال راحت
                            <svg
                                class="absolute right-0 -bottom-2 h-3 w-full text-amber-400/70"
                                viewBox="0 0 240 12"
                                fill="none"
                                aria-hidden="true"
                            >
                                <path
                                    d="M2 9c52-8 119-8 236-2"
                                    stroke="currentColor"
                                    stroke-width="4"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </span>
                        مدیریت کنید.
                    </h1>

                    <p
                        class="mt-7 max-w-xl text-base leading-8 text-muted-foreground sm:text-lg"
                    >
                        حساب‌یار فضایی تمیز و حرفه‌ای برای مدیریت مالی فروشگاه
                        است؛ از حساب‌های روزانه تا تصمیم‌های بزرگ‌تر، همه‌چیز
                        منظم، خوانا و در دسترس.
                    </p>

                    <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                        <Button
                            size="lg"
                            as-child
                            class="h-12 rounded-xl px-7 text-[15px] shadow-lg shadow-primary/15"
                        >
                            <Link :href="register()">
                                ساخت حساب فروشگاه
                                <ArrowLeft class="size-4" />
                            </Link>
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            as-child
                            class="h-12 rounded-xl border-border/80 bg-card/60 px-7 text-[15px] backdrop-blur"
                        >
                            <Link :href="login()">حساب دارم، وارد می‌شوم</Link>
                        </Button>
                    </div>

                    <div
                        class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3 text-sm text-muted-foreground"
                    >
                        <span
                            v-for="item in [
                                'شروع سریع',
                                'رابط فارسی و راست‌چین',
                                'امن و قابل اعتماد',
                            ]"
                            :key="item"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="flex size-5 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Check class="size-3" />
                            </span>
                            {{ item }}
                        </span>
                    </div>
                </div>

                <div class="relative mx-auto w-full max-w-[38rem] lg:mx-0">
                    <div
                        class="absolute -inset-5 rounded-[2.5rem] bg-gradient-to-br from-primary/15 via-transparent to-amber-300/20 blur-2xl"
                    />

                    <div
                        class="relative overflow-hidden rounded-[2rem] border border-white/70 bg-card/90 p-3 shadow-[0_30px_80px_-36px_rgba(15,23,42,.45)] backdrop-blur-xl dark:border-white/10"
                    >
                        <div
                            class="rounded-[1.5rem] border border-border/70 bg-background/80 p-4 sm:p-5"
                        >
                            <div class="mb-5 flex items-center justify-between">
                                <div class="text-right">
                                    <p class="text-sm font-bold">
                                        نمای کلی فروشگاه
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        پیش‌نمایش محیط حساب‌یار
                                    </p>
                                </div>
                                <span
                                    class="rounded-full border border-primary/15 bg-primary/10 px-3 py-1 text-[10px] font-bold text-primary"
                                    >زنده</span
                                >
                            </div>

                            <div
                                class="relative overflow-hidden rounded-2xl bg-[linear-gradient(135deg,#0b3935_0%,#0f5147_55%,#257c66_100%)] p-5 text-white shadow-lg shadow-emerald-950/15"
                            >
                                <div
                                    class="absolute -top-12 -left-8 size-40 rounded-full border border-white/10"
                                />
                                <div
                                    class="absolute -top-5 -left-1 size-24 rounded-full border border-white/10"
                                />
                                <div
                                    class="relative flex items-start justify-between"
                                >
                                    <div>
                                        <p class="text-xs text-white/65">
                                            موجودی کل
                                        </p>
                                        <p
                                            class="mt-2 text-2xl font-black tracking-tight"
                                        >
                                            —
                                        </p>
                                    </div>
                                    <div
                                        class="flex size-10 items-center justify-center rounded-xl bg-white/12 ring-1 ring-white/15"
                                    >
                                        <Wallet class="size-5" />
                                    </div>
                                </div>
                                <div
                                    class="relative mt-6 flex items-center gap-2"
                                >
                                    <span
                                        class="rounded-lg bg-white/12 px-2.5 py-1 text-[10px] text-white/80"
                                        >به‌روزرسانی خودکار</span
                                    >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-emerald-300"
                                    />
                                    <span class="text-[10px] text-white/60"
                                        >همه‌چیز آماده است</span
                                    >
                                </div>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3">
                                <div
                                    class="rounded-2xl border border-border/70 bg-card p-4"
                                >
                                    <div
                                        class="mb-4 flex items-center justify-between"
                                    >
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >روند فروش</span
                                        >
                                        <TrendingUp
                                            class="size-4 text-primary"
                                        />
                                    </div>
                                    <div class="flex h-14 items-end gap-1.5">
                                        <span
                                            v-for="height in [
                                                32, 52, 40, 66, 48, 78, 62,
                                            ]"
                                            :key="height"
                                            class="flex-1 rounded-t-md bg-primary/20 last:bg-primary"
                                            :style="{ height: `${height}%` }"
                                        />
                                    </div>
                                </div>
                                <div
                                    class="rounded-2xl border border-border/70 bg-card p-4"
                                >
                                    <div
                                        class="mb-3 flex items-center justify-between"
                                    >
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >فاکتورهای باز</span
                                        >
                                        <ReceiptText
                                            class="size-4 text-amber-500"
                                        />
                                    </div>
                                    <p class="text-2xl font-black">—</p>
                                    <p
                                        class="mt-1 text-[10px] text-muted-foreground"
                                    >
                                        آماده ثبت اولین فاکتور
                                    </p>
                                </div>
                            </div>

                            <div
                                class="mt-3 flex items-center justify-between rounded-2xl border border-dashed border-border bg-card/70 px-4 py-3"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex size-9 items-center justify-center rounded-xl bg-amber-400/15 text-amber-600 dark:text-amber-400"
                                    >
                                        <BarChart3 class="size-4" />
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold">
                                            گزارش‌های شفاف
                                        </p>
                                        <p
                                            class="mt-0.5 text-[10px] text-muted-foreground"
                                        >
                                            تصمیم بهتر با داده‌های مرتب
                                        </p>
                                    </div>
                                </div>
                                <Check class="size-4 text-primary" />
                            </div>
                        </div>
                    </div>

                    <div
                        class="absolute -right-3 -bottom-5 hidden items-center gap-3 rounded-2xl border border-border/70 bg-card/95 px-4 py-3 shadow-xl backdrop-blur sm:flex"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-xl bg-primary/12 text-primary"
                        >
                            <ShieldCheck class="size-4" />
                        </span>
                        <div>
                            <p class="text-xs font-bold">امنیت حساب</p>
                            <p class="mt-0.5 text-[10px] text-muted-foreground">
                                فعال و آماده استفاده
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="mx-auto w-full max-w-7xl px-5 pb-20 sm:px-8 lg:pb-28"
            >
                <div
                    class="grid overflow-hidden rounded-[1.75rem] border border-border/70 bg-card/75 shadow-sm backdrop-blur md:grid-cols-3"
                >
                    <article
                        v-for="(feature, index) in features"
                        :key="feature.title"
                        class="group p-6 transition-colors hover:bg-muted/45 sm:p-8"
                        :class="{
                            'border-t md:border-t-0 md:border-r': index > 0,
                        }"
                    >
                        <div
                            class="mb-5 flex size-11 items-center justify-center rounded-2xl bg-primary/10 text-primary transition-transform duration-300 group-hover:-translate-y-0.5"
                        >
                            <component :is="feature.icon" class="size-5" />
                        </div>
                        <h2 class="text-base font-bold">{{ feature.title }}</h2>
                        <p class="mt-2 text-sm leading-7 text-muted-foreground">
                            {{ feature.description }}
                        </p>
                    </article>
                </div>
            </section>
        </main>

        <footer class="relative z-10 border-t border-border/60">
            <div
                class="mx-auto flex w-full max-w-7xl flex-col items-center justify-between gap-3 px-5 py-6 text-xs text-muted-foreground sm:flex-row sm:px-8"
            >
                <p>حساب‌یار؛ همراه دقیق کسب‌وکار شما</p>
                <p>ساخته‌شده با Laravel، Vue و Inertia</p>
            </div>
        </footer>
    </div>
</template>
