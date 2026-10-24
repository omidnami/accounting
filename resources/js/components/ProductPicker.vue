<script setup lang="ts">
import { Check, PackageSearch, Search, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type ProductChoice = {
    id: number;
    code: string;
    barcode: string | null;
    name: string;
    unit: string;
    stock_quantity: string;
    is_active: boolean;
};

const props = withDefaults(
    defineProps<{
        modelValue: string | number;
        endpoint: string;
        selectedLabel?: string;
        warehouseId?: string | number | null;
        placeholder?: string;
        disabled?: boolean;
        excludeIds?: number[];
        includeInactive?: boolean;
    }>(),
    {
        selectedLabel: '',
        warehouseId: null,
        placeholder: 'جست‌وجوی نام، کد یا بارکد کالا...',
        disabled: false,
        excludeIds: () => [],
        includeInactive: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    select: [product: ProductChoice];
}>();

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const query = ref('');
const loading = ref(false);
const products = ref<ProductChoice[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;
let requestId = 0;
const selected = computed(() => String(props.modelValue ?? '') !== '');

const close = () => {
    open.value = false;
    query.value = '';
};

const load = async () => {
    if (props.disabled) {
        return;
    }

    const currentRequest = ++requestId;
    loading.value = true;

    try {
        const params = new URLSearchParams();

        if (props.warehouseId) {
            params.set('warehouse_id', String(props.warehouseId));
        }

        if (query.value.trim()) {
            params.set('search', query.value.trim());
        }

        if (props.includeInactive) {
            params.set('include_inactive', '1');
        }

        const response = await fetch(`${props.endpoint}?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('lookup');
        }

        const payload = (await response.json()) as { data: ProductChoice[] };

        if (currentRequest === requestId) {
            products.value = payload.data.filter(
                (product) => !props.excludeIds.includes(product.id),
            );
        }
    } catch {
        if (currentRequest === requestId) {
            products.value = [];
        }
    } finally {
        if (currentRequest === requestId) {
            loading.value = false;
        }
    }
};

watch(query, () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(load, 220);
});

watch(
    () => props.warehouseId,
    () => {
        products.value = [];
        emit('update:modelValue', '');
        close();
    },
);

const choose = (product: ProductChoice) => {
    emit('update:modelValue', String(product.id));
    emit('select', product);
    close();
};

const clear = () => {
    emit('update:modelValue', '');
    products.value = [];
    open.value = true;
    void load();
};

const focusSearch = () => {
    if (props.disabled || selected.value) {
        return;
    }

    open.value = true;
    void load();
};

const clickOutside = (event: MouseEvent) => {
    if (root.value && !root.value.contains(event.target as Node)) {
        close();
    }
};

onMounted(() => document.addEventListener('mousedown', clickOutside));
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', clickOutside);

    if (timer) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <div ref="root" class="relative">
        <div
            v-if="selected"
            class="flex min-h-10 items-center justify-between gap-2 rounded-lg border bg-background px-3 py-2"
        >
            <span class="min-w-0">
                <span class="block truncate text-xs font-semibold">
                    {{ selectedLabel || 'کالای انتخاب‌شده' }}
                </span>
            </span>
            <button
                type="button"
                class="flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
                :disabled="disabled"
                aria-label="تغییر کالا"
                @click="clear"
            >
                <X class="size-4" />
            </button>
        </div>
        <template v-else>
            <Search
                class="pointer-events-none absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
            />
            <input
                v-model="query"
                type="search"
                :placeholder="placeholder"
                :disabled="disabled"
                class="h-10 w-full rounded-lg border bg-background pr-9 pl-3 text-xs outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                @focus="focusSearch"
            />
            <div
                v-if="open"
                class="absolute inset-x-0 top-11 z-40 max-h-64 overflow-y-auto rounded-xl border bg-popover p-1 shadow-xl"
            >
                <p
                    v-if="loading"
                    class="px-3 py-4 text-xs text-muted-foreground"
                >
                    در حال جست‌وجو...
                </p>
                <button
                    v-for="product in products"
                    v-else
                    :key="product.id"
                    type="button"
                    class="flex w-full items-start justify-between gap-3 rounded-lg px-3 py-2.5 text-right hover:bg-muted/60"
                    @click="choose(product)"
                >
                    <span class="min-w-0">
                        <span class="block truncate text-xs font-semibold">
                            {{ product.name }}
                        </span>
                        <span
                            class="mt-1 block text-[9px] text-muted-foreground"
                        >
                            {{ product.code }}
                            <template v-if="product.barcode">
                                · {{ product.barcode }}
                            </template>
                            <template v-if="!product.is_active">
                                · غیرفعال
                            </template>
                            · موجودی
                            {{
                                Number(product.stock_quantity).toLocaleString(
                                    'fa-IR',
                                )
                            }}
                            {{ product.unit }}
                        </span>
                    </span>
                    <Check class="mt-1 size-3.5 shrink-0 text-primary" />
                </button>
                <div
                    v-if="!loading && products.length === 0"
                    class="px-3 py-5 text-center text-xs text-muted-foreground"
                >
                    <PackageSearch class="mx-auto mb-2 size-5 opacity-50" />
                    کالایی پیدا نشد.
                </div>
            </div>
        </template>
    </div>
</template>
