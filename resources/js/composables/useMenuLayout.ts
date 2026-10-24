import { readonly, ref } from 'vue';
import type { MenuLayout } from '@/types';

const STORAGE_KEY = 'menu_layout';
const COOKIE_NAME = 'menu_layout';

function readStoredMenuLayout(): MenuLayout {
    if (typeof window === 'undefined') {
        return 'sidebar';
    }

    let stored: string | null = null;

    try {
        stored = window.localStorage.getItem(STORAGE_KEY);
    } catch {
        // Storage may be unavailable; keep the cookie or in-memory preference.
    }

    if (stored === 'topbar' || stored === 'sidebar') {
        return stored;
    }

    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith(`${COOKIE_NAME}=`));
    const cookieValue = cookie?.split('=')[1];

    return cookieValue === 'topbar' ? 'topbar' : 'sidebar';
}

const menuLayout = ref<MenuLayout>(readStoredMenuLayout());

if (typeof document !== 'undefined') {
    document.documentElement.dataset.menuLayout = menuLayout.value;
}

export function useMenuLayout() {
    function setMenuLayout(layout: MenuLayout): void {
        menuLayout.value = layout;

        if (typeof window === 'undefined') {
            return;
        }

        try {
            window.localStorage.setItem(STORAGE_KEY, layout);
        } catch {
            // Changing the layout should still work when storage is blocked.
        }
        document.cookie = `${COOKIE_NAME}=${layout};path=/;max-age=31536000;SameSite=Lax`;
        document.documentElement.dataset.menuLayout = layout;
    }

    return {
        menuLayout: readonly(menuLayout),
        setMenuLayout,
    };
}
