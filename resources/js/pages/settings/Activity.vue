<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Clock3, History } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index as myActivity } from '@/routes/my-activity';

const props = defineProps<{
    activities: {
        data: Array<{
            id: number;
            event_label: string;
            entity_label: string;
            entity_id: number;
            description: string;
            created_at: string;
        }>;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { date: 'today' | '7_days' | null };
}>();

const count = new Intl.NumberFormat('fa-IR');
const dateTime = new Intl.DateTimeFormat('fa-IR', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const changeDate = (event: Event) => {
    const date = (event.target as HTMLSelectElement).value;
    router.get(
        myActivity(),
        { date: date || undefined },
        { preserveScroll: true, replace: true },
    );
};
const goToPage = (url: string | null) => {
    if (url) router.visit(url, { preserveScroll: true });
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'آخرین فعالیت من', href: myActivity() }] },
});
</script>

<template>
    <Head title="آخرین فعالیت من" />
    <div class="space-y-5">
        <Heading
            variant="small"
            title="آخرین فعالیت من"
            description="تاریخچهٔ عملیات ثبت‌شده با حساب شما؛ از جدیدترین فعالیت به قدیمی‌ترین."
        />
        <div
            class="flex items-center justify-between gap-3 rounded-2xl border border-border/70 bg-card p-4"
        >
            <div class="flex items-center gap-2 text-sm font-semibold">
                <History class="size-4 text-primary" />
                {{ count.format(activities.total) }} فعالیت
            </div>
            <select
                aria-label="بازه فعالیت‌ها"
                :value="props.filters.date ?? ''"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm"
                @change="changeDate"
            >
                <option value="">همه زمان‌ها</option>
                <option value="today">امروز</option>
                <option value="7_days">۷ روز اخیر</option>
            </select>
        </div>
        <section aria-label="فعالیت‌های من" class="space-y-3">
            <article
                v-for="activity in activities.data"
                :key="activity.id"
                class="flex gap-3 rounded-2xl border border-border/70 bg-card p-4 sm:p-5"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                >
                    <History class="size-4" />
                </span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <p class="text-sm font-semibold break-words">
                            {{ activity.description }}
                        </p>
                        <span
                            class="rounded-full bg-muted px-2.5 py-1 text-[10px] text-muted-foreground"
                        >
                            {{ activity.event_label }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ activity.entity_label }} · شناسه
                        {{ count.format(activity.entity_id) }}
                    </p>
                    <time
                        :datetime="activity.created_at"
                        class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                    >
                        <Clock3 class="size-3.5" />
                        {{ dateTime.format(new Date(activity.created_at)) }}
                    </time>
                </div>
            </article>
            <div
                v-if="activities.data.length === 0"
                class="rounded-2xl border border-dashed bg-card px-4 py-12 text-center"
            >
                <History class="mx-auto size-9 text-muted-foreground" />
                <p class="mt-3 text-sm font-semibold">
                    فعالیتی در این بازه ثبت نشده است.
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    عملیات ثبت‌شده با حساب شما در این بخش نمایش داده می‌شوند.
                </p>
            </div>
        </section>
        <nav
            v-if="activities.last_page > 1"
            aria-label="صفحه‌بندی فعالیت‌ها"
            class="flex items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3"
        >
            <p class="text-xs text-muted-foreground">
                {{ count.format(activities.from ?? 0) }} تا
                {{ count.format(activities.to ?? 0) }} از
                {{ count.format(activities.total) }}
            </p>
            <div class="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!activities.prev_page_url"
                    @click="goToPage(activities.prev_page_url)"
                >
                    <ChevronRight class="size-4" /> قبلی
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!activities.next_page_url"
                    @click="goToPage(activities.next_page_url)"
                >
                    بعدی <ChevronLeft class="size-4" />
                </Button>
            </div>
        </nav>
    </div>
</template>
