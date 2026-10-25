<script setup lang="ts">
import { ImagePlus, Trash2, Upload } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    existingUrl?: string | null;
    error?: string;
    disabled?: boolean;
}>();
const image = defineModel<File | null>({ required: true });
const remove = defineModel<boolean>('remove', { required: true });
const input = ref<HTMLInputElement | null>(null);
const objectUrl = ref<string | null>(null);
const localError = ref('');
const preview = computed(
    () => objectUrl.value ?? (remove.value ? null : props.existingUrl),
);

watch(image, (file) => {
    if (objectUrl.value) URL.revokeObjectURL(objectUrl.value);
    objectUrl.value = file ? URL.createObjectURL(file) : null;
});
onBeforeUnmount(() => {
    if (objectUrl.value) URL.revokeObjectURL(objectUrl.value);
});

function selectImage(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    localError.value = '';
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        localError.value = 'تصویر باید از نوع JPEG، PNG یا WebP باشد.';
    } else if (file.size > 2 * 1024 * 1024) {
        localError.value = 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد.';
    }
    if (!localError.value) {
        image.value = file;
        remove.value = false;
    }
    (event.target as HTMLInputElement).value = '';
}
function clearImage() {
    image.value = null;
    remove.value = true;
    localError.value = '';
}
</script>

<template>
    <section class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm">
        <h2 class="text-sm font-bold">تصویر کالا</h2>
        <p class="mt-1 text-xs leading-6 text-muted-foreground">
            اختیاری؛ در پنل انتخاب کالای صندوق نمایش داده می‌شود.
        </p>
        <div
            class="mt-4 flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl border border-dashed bg-muted/25"
        >
            <img
                v-if="preview"
                :src="preview"
                alt="پیش‌نمایش تصویر کالا"
                class="h-full w-full object-contain p-3"
            />
            <div
                v-else
                class="flex flex-col items-center gap-2 text-muted-foreground"
            >
                <ImagePlus class="size-9 opacity-50" />
                <span class="text-xs">بدون تصویر</span>
            </div>
        </div>
        <input
            ref="input"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            aria-label="انتخاب تصویر کالا"
            class="sr-only"
            :disabled="disabled"
            @change="selectImage"
        />
        <div class="mt-3 flex gap-2">
            <Button
                type="button"
                variant="outline"
                class="flex-1 rounded-xl"
                :disabled="disabled"
                @click="input?.click()"
            >
                <Upload class="size-4" />{{
                    preview ? 'تعویض تصویر' : 'انتخاب تصویر'
                }}
            </Button>
            <Button
                v-if="preview"
                type="button"
                variant="ghost"
                class="rounded-xl text-destructive"
                aria-label="حذف تصویر کالا"
                :disabled="disabled"
                @click="clearImage"
            >
                <Trash2 class="size-4" />
            </Button>
        </div>
        <p class="mt-3 text-[10px] leading-5 text-muted-foreground">
            JPEG، PNG یا WebP؛ حداکثر ۲ مگابایت و ۴۰۹۶ × ۴۰۹۶ پیکسل.
        </p>
        <InputError class="mt-2" :message="localError || error" />
    </section>
</template>
