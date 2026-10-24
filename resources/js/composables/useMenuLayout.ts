import { readonly, ref } from 'vue';
import type { MenuLayout } from '@/types';

const STORAGE_KEY = 'menu_layout';
const COOKIE_NAME = 'menu_layout';

function readStoredMenuLayout(): MenuLayout {
    if (typeof window === 'undefined') {
        return 'sidebar';
    }

    const stored = window.localStorage.getItem(STORAGE_KEY);

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

        window.localStorage.setItem(STORAGE_KEY, layout);
        document.cookie = `${COOKIE_NAME}=${layout};path=/;max-age=31536000;SameSite=Lax`;
        document.documentElement.dataset.menuLayout = layout;
    }

    return {
        menuLayout: readonly(menuLayout),
        setMenuLayout,
    };
}
