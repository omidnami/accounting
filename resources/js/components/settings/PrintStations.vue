<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Ban, Copy, RefreshCw, Router as RouterIcon } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    revoke as revokeStationRoute,
    rotate as rotateStationRoute,
} from '@/routes/print-stations';

type ConnectionStatus = 'online' | 'offline' | 'never-seen';

type PrintStationItem = {
    id: number;
    name: string;
    platform: string;
    status: 'active' | 'revoked';
    last_seen_at: string | null;
    revoked_at: string | null;
    connection_status: ConnectionStatus;
};

type RotatedToken = {
    station_id: number;
    token: string;
};

defineProps<{
    stations: PrintStationItem[];
}>();

const page = usePage();
const flashToken = computed(() => {
    const flash = page.flash as
        | { print_station_token?: RotatedToken }
        | undefined;

    return flash?.print_station_token;
});

const issuedToken = ref<RotatedToken | null>(null);
const tokenDialogOpen = ref(false);
const rotatingStation = ref<PrintStationItem | null>(null);
const revokingStation = ref<PrintStationItem | null>(null);
const rotateDialogOpen = ref(false);
const revokeDialogOpen = ref(false);
const rotateProcessing = ref(false);
const revokeProcessing = ref(false);
const rotateErrors = ref<Record<string, string>>({});
const revokeErrors = ref<Record<string, string>>({});
const { copy } = useClipboard();
const copiedToken = ref<string | null>(null);

const connectionLabels: Record<ConnectionStatus, string> = {
    online: 'آنلاین',
    offline: 'آفلاین',
    'never-seen': 'دیده‌نشده',
};

const connectionClasses: Record<ConnectionStatus, string> = {
    online: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    offline: 'bg-muted text-muted-foreground',
    'never-seen': 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
};

watch(
    flashToken,
    (value) => {
        if (value) {
            issuedToken.value = value;
            tokenDialogOpen.value = true;
        }
    },
    { immediate: true },
);

watch(tokenDialogOpen, (open) => {
    if (!open) {
        issuedToken.value = null;
        copiedToken.value = null;
    }
});

const openRotate = (station: PrintStationItem) => {
    rotateErrors.value = {};
    rotatingStation.value = station;
    rotateDialogOpen.value = true;
};

const closeRotate = () => {
    rotateDialogOpen.value = false;
    rotatingStation.value = null;
    rotateErrors.value = {};
};

const submitRotate = () => {
    const station = rotatingStation.value;

    if (!station) {
        return;
    }

    rotateErrors.value = {};
    router.post(
        rotateStationRoute({ station: station.id }).url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                rotateProcessing.value = true;
            },
            onSuccess: closeRotate,
            onError: (errors) => {
                rotateErrors.value = errors;
            },
            onFinish: () => {
                rotateProcessing.value = false;
            },
        },
    );
};

const openRevoke = (station: PrintStationItem) => {
    revokeErrors.value = {};
    revokingStation.value = station;
    revokeDialogOpen.value = true;
};

const closeRevoke = () => {
    revokeDialogOpen.value = false;
    revokingStation.value = null;
    revokeErrors.value = {};
};

const submitRevoke = () => {
    const station = revokingStation.value;

    if (!station) {
        return;
    }

    revokeErrors.value = {};
    router.post(
        revokeStationRoute({ station: station.id }).url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                revokeProcessing.value = true;
            },
            onSuccess: closeRevoke,
            onError: (errors) => {
                revokeErrors.value = errors;
            },
            onFinish: () => {
                revokeProcessing.value = false;
            },
        },
    );
};

const copyIssuedToken = async () => {
    const token = issuedToken.value?.token;

    if (!token) {
        return;
    }

    await copy(token);
    copiedToken.value = token;
};
</script>

<template>
    <section
        class="rounded-2xl border bg-card p-5 shadow-sm sm:p-6 lg:col-span-2"
    >
        <div class="flex items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
            >
                <RouterIcon class="size-5" />
            </span>
            <div>
                <h2 class="text-sm font-bold">چاپ خودکار با Print Agent</h2>
                <p class="mt-2 text-xs leading-6 text-muted-foreground">
                    Agent محلی، چاپ بی‌صدا و صف چاپ را بدون بازکردن چاپگر مرورگر
                    انجام می‌دهد. station جدید را با دستور pairing فعلی ایجاد
                    کنید:
                </p>
                <code
                    dir="ltr"
                    class="mt-3 block overflow-x-auto rounded-lg bg-muted px-3 py-2 text-left text-[11px]"
                    >php artisan print:pair-station --store=MAIN</code
                >
            </div>
        </div>

        <div v-if="stations.length" class="mt-6 space-y-3">
            <article
                v-for="station in stations"
                :key="station.id"
                class="rounded-xl border p-4"
            >
                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-bold">
                                {{ station.name }}
                            </h3>
                            <span
                                class="rounded-full px-2 py-1 text-[10px] font-bold"
                                :class="
                                    station.status === 'active'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-destructive/10 text-destructive'
                                "
                            >
                                {{
                                    station.status === 'active'
                                        ? 'فعال'
                                        : 'باطل‌شده'
                                }}
                            </span>
                            <span
                                class="rounded-full px-2 py-1 text-[10px] font-bold"
                                :class="
                                    connectionClasses[station.connection_status]
                                "
                            >
                                {{
                                    connectionLabels[station.connection_status]
                                }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-muted-foreground">
                            <span dir="ltr">{{ station.platform }}</span>
                            · شناسهٔ {{ station.id }}
                            ·
                            {{
                                station.last_seen_at
                                    ? `آخرین اتصال: ${station.last_seen_at}`
                                    : 'هنوز اتصالی ثبت نشده است'
                            }}
                        </p>
                    </div>

                    <div v-if="station.status === 'active'" class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="rotateProcessing"
                            @click="openRotate(station)"
                        >
                            <RefreshCw class="size-4" />
                            چرخش توکن
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            :disabled="revokeProcessing"
                            @click="openRevoke(station)"
                        >
                            <Ban class="size-4" />
                            ابطال
                        </Button>
                    </div>
                </div>
            </article>
        </div>

        <p
            v-else
            class="mt-6 rounded-xl border border-dashed p-5 text-center text-xs text-muted-foreground"
        >
            هنوز Print Agentی برای شعبهٔ فعال ثبت نشده است.
        </p>
    </section>

    <Dialog :open="rotateDialogOpen" @update:open="rotateDialogOpen = $event">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submitRotate">
                <DialogHeader>
                    <DialogTitle>چرخش توکن Print Agent</DialogTitle>
                    <DialogDescription>
                        توکن فعلی «{{ rotatingStation?.name }}» فوراً باطل
                        می‌شود. توکن جدید فقط یک‌بار نمایش داده می‌شود و Agent
                        باید با همان توکن دوباره پیکربندی شود.
                    </DialogDescription>
                </DialogHeader>

                <InputError class="mt-4" :message="rotateErrors.station" />

                <DialogFooter class="mt-6 gap-2">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="rotateProcessing"
                            @click="closeRotate"
                        >
                            انصراف
                        </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="rotateProcessing">
                        <Spinner v-if="rotateProcessing" />
                        صدور توکن جدید
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="revokeDialogOpen" @update:open="revokeDialogOpen = $event">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submitRevoke">
                <DialogHeader>
                    <DialogTitle>ابطال Print Agent</DialogTitle>
                    <DialogDescription>
                        «{{ revokingStation?.name }}» دیگر نمی‌تواند job بگیرد.
                        jobهای در صف و در حال چاپ این station شکست می‌خورند، اما
                        سابقهٔ station و jobها حفظ می‌شود.
                    </DialogDescription>
                </DialogHeader>

                <InputError class="mt-4" :message="revokeErrors.station" />

                <DialogFooter class="mt-6 gap-2">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="revokeProcessing"
                            @click="closeRevoke"
                        >
                            انصراف
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="revokeProcessing"
                    >
                        <Spinner v-if="revokeProcessing" />
                        تأیید ابطال
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="tokenDialogOpen" @update:open="tokenDialogOpen = $event">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>توکن جدید Print Agent</DialogTitle>
                <DialogDescription>
                    این توکن فقط یک‌بار نمایش داده می‌شود. آن را همین حالا در
                    Agent ثبت کنید؛ در دیتابیس فقط hash توکن نگهداری می‌شود.
                </DialogDescription>
            </DialogHeader>

            <div
                dir="ltr"
                class="rounded-xl border bg-muted p-4 text-left font-mono text-xs break-all select-all"
            >
                {{ issuedToken?.token }}
            </div>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!issuedToken"
                    @click="copyIssuedToken"
                >
                    <Copy class="size-4" />
                    {{
                        copiedToken === issuedToken?.token
                            ? 'کپی شد'
                            : 'کپی توکن'
                    }}
                </Button>
                <DialogClose as-child>
                    <Button type="button">توکن را ذخیره کردم</Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
