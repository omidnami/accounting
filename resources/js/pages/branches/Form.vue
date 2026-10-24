<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Building2, Save } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as branchesIndex,
    store as storeBranch,
    update as updateBranch,
} from '@/routes/branches';

const props = defineProps<{
    branch: {
        id: number;
        code: string;
        name: string;
        phone: string | null;
        address: string | null;
        is_default: boolean;
        is_active: boolean;
    } | null;
}>();

const form = useForm({
    code: props.branch?.code ?? '',
    name: props.branch?.name ?? '',
    phone: props.branch?.phone ?? '',
    address: props.branch?.address ?? '',
});
const branchError = computed(
    () => (form.errors as Record<string, string>).branch,
);
const submit = () => {
    if (props.branch) {
        form.put(updateBranch(props.branch.id).url, { preserveScroll: true });
        return;
    }

    form.post(storeBranch().url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'شعبه‌ها', href: branchesIndex() }],
    },
});
</script>

<template>
    <Head :title="branch ? 'ویرایش شعبه' : 'شعبه جدید'" />

    <div class="mx-auto w-full max-w-4xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <Building2 class="size-5" />
                        <span class="text-xs font-bold">ساختار عملیاتی</span>
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        {{ branch ? 'ویرایش شعبه' : 'ایجاد شعبه جدید' }}
                    </h1>
                    <p class="mt-2 text-sm leading-7 text-muted-foreground">
                        برای شعبه جدید یک انبار پیش‌فرض مستقل به‌صورت خودکار
                        ساخته می‌شود.
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="branchesIndex()">
                        <ArrowRight class="size-4" /> بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <form
            class="space-y-5 rounded-2xl border bg-card p-5 shadow-sm sm:p-6"
            @submit.prevent="submit"
        >
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">نام شعبه *</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        class="h-11 rounded-xl"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="code">کد شعبه *</Label>
                    <Input
                        id="code"
                        v-model="form.code"
                        dir="ltr"
                        class="h-11 rounded-xl text-left font-mono"
                        placeholder="BRANCH-02"
                    />
                    <InputError :message="form.errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">تلفن</Label>
                    <Input
                        id="phone"
                        v-model="form.phone"
                        dir="ltr"
                        class="h-11 rounded-xl text-left"
                    />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="address">آدرس</Label>
                    <textarea
                        id="address"
                        v-model="form.address"
                        rows="3"
                        class="w-full rounded-xl border bg-background px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.address" />
                </div>
            </div>

            <div
                v-if="branchError"
                class="rounded-xl bg-red-500/10 p-3 text-xs text-red-700"
            >
                {{ branchError }}
            </div>

            <Button
                type="submit"
                class="h-11 rounded-xl px-6"
                :disabled="form.processing"
            >
                <Spinner v-if="form.processing" />
                <Save v-else class="size-4" />
                {{ branch ? 'ذخیره تغییرات' : 'ایجاد شعبه' }}
            </Button>
        </form>
    </div>
</template>
