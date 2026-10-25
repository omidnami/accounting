import { readonly, ref } from 'vue';

export const panelColors = [
    { value: 'green', label: 'سبز پیش‌فرض', swatch: 'hsl(164 61% 24%)' },
    { value: 'red', label: 'قرمز', swatch: 'var(--color-red-700)' },
    { value: 'blue', label: 'آبی', swatch: 'var(--color-blue-700)' },
    { value: 'violet', label: 'بنفش', swatch: 'var(--color-violet-700)' },
    { value: 'indigo', label: 'نیلی', swatch: 'var(--color-indigo-700)' },
    { value: 'rose', label: 'رز', swatch: 'var(--color-rose-700)' },
    { value: 'orange', label: 'نارنجی', swatch: 'var(--color-orange-700)' },
    { value: 'amber', label: 'کهربایی', swatch: 'var(--color-amber-700)' },
    { value: 'teal', label: 'سبزآبی', swatch: 'var(--color-teal-700)' },
    { value: 'cyan', label: 'فیروزه‌ای', swatch: 'var(--color-cyan-700)' },
] as const;

type PanelColor = (typeof panelColors)[number]['value'];
const panelColor = ref<PanelColor>('green');
const isPanelColor = (value: string | null): value is PanelColor =>
    panelColors.some((color) => color.value === value);

export function initializePanelColor(): void {
    if (typeof window === 'undefined') return;

    let saved: string | null =
        document.documentElement.dataset.panelColor ?? null;
    try {
        saved = localStorage.getItem('panel_color') ?? saved;
    } catch {
        // Keep the in-memory preference when browser storage is unavailable.
    }
    panelColor.value = isPanelColor(saved) ? saved : 'green';
    document.documentElement.dataset.panelColor = panelColor.value;
}

export function usePanelColor() {
    function setPanelColor(value: PanelColor): void {
        panelColor.value = isPanelColor(value) ? value : 'green';
        if (typeof window === 'undefined') return;
        document.documentElement.dataset.panelColor = panelColor.value;
        try {
            localStorage.setItem('panel_color', panelColor.value);
        } catch {
            // The choice still applies for the current browser session.
        }
    }

    return { panelColor: readonly(panelColor), setPanelColor };
}
