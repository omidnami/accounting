import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

let activePopStateHandler: ((event: PopStateEvent) => void) | undefined;
let initialized = false;

// Install before Inertia initializes its window history listener. On window,
// popstate handlers can run in registration order even with capture enabled.
export function initializePosExitGuard() {
    if (initialized || typeof window === 'undefined') return;
    initialized = true;
    window.addEventListener(
        'popstate',
        (event) => activePopStateHandler?.(event),
        true,
    );
}

/** Browsers use their own text for tab closing/reloading; in-app exits use ours. */
export function usePosExitGuard(isBusy: () => boolean) {
    let leaving = false;
    let hadPreviousEntry = false;
    let originalUrl = '';
    let originalState: Record<string, unknown> | null = null;
    let removeBefore: (() => void) | undefined;
    const question = 'آیا از صندوق خارج می‌شوید؟';
    const confirmExit = () => {
        if (isBusy()) {
            window.alert('ثبت عملیات در حال انجام است؛ چند لحظه صبر کنید.');
            return false;
        }
        return window.confirm(question);
    };
    const beforeUnload = (event: BeforeUnloadEvent) => {
        if (!leaving) {
            event.preventDefault();
            event.returnValue = question;
        }
    };
    const onPopState = (event: PopStateEvent) => {
        if (leaving) return;
        event.stopImmediatePropagation();
        // A same-page history entry lets us cancel Back before Inertia unmounts POS.
        if (confirmExit()) {
            leaving = true;
            if (window.location.href !== originalUrl) {
                window.dispatchEvent(
                    new PopStateEvent('popstate', { state: event.state }),
                );
                return;
            }
            if (hadPreviousEntry) window.history.back();
            else router.visit('/dashboard');
        } else {
            window.history.pushState(
                { ...originalState, posGuard: true },
                '',
                originalUrl,
            );
        }
    };
    onMounted(() => {
        hadPreviousEntry = window.history.length > 1;
        originalUrl = window.location.href;
        originalState = window.history.state;
        window.history.pushState(
            { ...window.history.state, posGuard: true },
            '',
            window.location.href,
        );
        activePopStateHandler = onPopState;
        window.addEventListener('beforeunload', beforeUnload);
        removeBefore = router.on('before', (event) => {
            if (leaving) return;
            if (event.detail.visit.url.pathname === window.location.pathname)
                return;
            if (!confirmExit()) event.preventDefault();
            else leaving = true;
        });
    });
    onBeforeUnmount(() => {
        removeBefore?.();
        if (activePopStateHandler === onPopState)
            activePopStateHandler = undefined;
        window.removeEventListener('beforeunload', beforeUnload);
    });
}
