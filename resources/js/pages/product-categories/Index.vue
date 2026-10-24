<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, FolderTree, PencilLine, Plus, Save } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    index as categoriesIndex,
    store as storeCategory,
    update as updateCategory,
} from '@/routes/product-categories';
import { index as productsIndex } from '@/routes/products';

type Category = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    is_active: boolean;
    products_count: number;
};

defineProps<{ categories: Category[] }>();
const editing = ref<Category | null>(null);
const form = useForm({
    code: '',
    name: '',
    description: '',
    is_active: true,
});
const edit = (category: Category) => {
    editing.value = category;
    form.code = category.code;
    form.name = category.name;
    form.description = category.description ?? '';
    form.is_active = category.is_active;
    form.clearErrors();
};
const reset = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
};
const submit = () => {
    if (editing.value) {
        form.put(updateCategory(editing.value.id).url, {
            preserveScroll: true,
            onSuccess: reset,
        });
        return;
    }

    form.post(storeCategory().url, {
        preserveScroll: true,
        onSuccess: reset,
    });
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'دسته‌بندی کالا', href: categoriesIndex() }],
    },
});
</script>

<template>
    <Head title="دسته‌بندی کالا" />

    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="flex flex-col justify-between gap-4 rounded-3xl border border-primary/10 bg-card p-6 sm:flex-row sm:items-end"
        >
            <div>
                <div class="mb-3 flex items-center gap-2 text-primary">
                    <FolderTree class="size-5" />
                    <span class="text-xs font-bold">ساختار کاتالوگ</span>
                </div>
                <h1 class="text-2xl font-black sm:text-3xl">دسته‌بندی کالا</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    کالاهای مرتبط را گروه‌بندی کنید و در گزارش‌ها و فهرست
                    کاتالوگ سریع‌تر پیدا کنید.
                </p>
            </div>
            <Button as-child variant="outline" class="rounded-xl">
                <Link :href="productsIndex()">
                    <ArrowRight class="size-4" />
                    کالا و موجودی
                </Link>
            </Button>
        </section>

        <div class="grid items-start gap-5 lg:grid-cols-[360px_minmax(0,1fr)]">
            <form
                class="rounded-2xl border bg-card p-5"
                @submit.prevent="submit"
            >
                <h2 class="text-sm font-bold">
                    {{ editing ? 'ویرایش دسته' : 'دسته جدید' }}
                </h2>
                <div class="mt-4 space-y-4">
                    <div>
                        <Label for="category-code">کد *</Label>
                        <Input
                            id="category-code"
                            v-model="form.code"
                            dir="ltr"
                            class="mt-2 text-left font-mono"
                        />
                        <InputError :message="form.errors.code" />
                    </div>
                    <div>
                        <Label for="category-name">نام *</Label>
                        <Input
                            id="category-name"
                            v-model="form.name"
                            class="mt-2"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div>
                        <Label for="category-description">توضیحات</Label>
                        <textarea
                            id="category-description"
                            v-model="form.description"
                            rows="3"
                            class="mt-2 w-full rounded-xl border bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <label class="flex items-center gap-2 text-xs">
                        <input v-model="form.is_active" type="checkbox" />
                        دسته فعال باشد
                    </label>
                    <InputError
                        :message="
                            (form.errors as Record<string, string | undefined>)
                                .category
                        "
                    />
                </div>
                <div class="mt-5 flex gap-2">
                    <Button type="submit" class="flex-1 rounded-xl">
                        <component :is="editing ? Save : Plus" class="size-4" />
                        {{ editing ? 'ذخیره' : 'ایجاد' }}
                    </Button>
                    <Button
                        v-if="editing"
                        type="button"
                        variant="ghost"
                        @click="reset"
                        >انصراف</Button
                    >
                </div>
            </form>

            <section class="overflow-hidden rounded-2xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="text-sm font-bold">دسته‌های موجود</h2>
                </div>
                <div class="divide-y">
                    <div
                        v-for="category in categories"
                        :key="category.id"
                        class="flex items-center justify-between gap-4 px-5 py-4"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-bold">
                                    {{ category.name }}
                                </p>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[9px]"
                                    :class="
                                        category.is_active
                                            ? 'bg-emerald-500/10 text-emerald-700'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{
                                        category.is_active ? 'فعال' : 'غیرفعال'
                                    }}
                                </span>
                            </div>
                            <p
                                class="mt-1 font-mono text-[10px] text-muted-foreground"
                            >
                                {{ category.code }} ·
                                {{ category.products_count }} کالا
                            </p>
                            <p
                                v-if="category.description"
                                class="mt-2 text-xs text-muted-foreground"
                            >
                                {{ category.description }}
                            </p>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="edit(category)"
                        >
                            <PencilLine class="size-4" /> ویرایش
                        </Button>
                    </div>
                    <p
                        v-if="categories.length === 0"
                        class="py-12 text-center text-sm text-muted-foreground"
                    >
                        دسته‌بندی ثبت نشده است.
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
