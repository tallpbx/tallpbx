window.themeData = function themeData() {
    return {
        theme: 'system',
        systemDark: window.matchMedia('(prefers-color-scheme: dark)').matches,

        initTheme() {
            const saved = localStorage.getItem('theme');
            if (saved) {
                this.theme = saved;
            }
            this.applyTheme();

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                this.systemDark = e.matches;
                if (this.theme === 'system') this.applyTheme();
            });

            Livewire.on('theme-changed', (event) => {
                this.theme = event.theme;
                this.applyTheme();
            });

            // Per Livewire 4 docs, use livewire:navigating + onSwap to apply
            // critical styles (dark mode) before the new page is painted.
            // This prevents flickering during wire:navigate transitions.
            // Scripts in <head> only run on the initial page load, so this
            // is the recommended hook for re-applying theme on navigation.
            document.addEventListener('livewire:navigating', (e) => {
                e.detail.onSwap(() => {
                    const saved = localStorage.getItem('theme') || 'system';
                    const isDark = saved === 'dark'
                        || (saved === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
                });
            });
        },

        applyTheme() {
            const isDark = this.theme === 'dark' || (this.theme === 'system' && this.systemDark);
            document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
            localStorage.setItem('theme', this.theme);
        },
    };
};

window.layoutData = function layoutData() {
    return {
        layoutMode: 'sidebar',
        sidebarCollapsed: false,
        mobileSidebarOpen: false,

        initLayout() {
            const savedLayout = localStorage.getItem('tallpbx:layout_mode');
            if (savedLayout) {
                this.layoutMode = savedLayout;
            }
            const savedCollapsed = localStorage.getItem('tallpbx:sidebar_collapsed');
            if (savedCollapsed !== null) {
                this.sidebarCollapsed = savedCollapsed === 'true';
            }
            this.applyLayout();

            Livewire.on('layout-changed', (event) => {
                this.layoutMode = event.mode;
                this.applyLayout();
            });

            Livewire.on('sidebar-collapse-changed', (event) => {
                this.sidebarCollapsed = event.collapsed;
                this.applyLayout();
            });

            document.addEventListener('livewire:navigating', (e) => {
                e.detail.onSwap(() => {
                    const mode = localStorage.getItem('tallpbx:layout_mode') || 'sidebar';
                    const collapsed = localStorage.getItem('tallpbx:sidebar_collapsed') === 'true';
                    document.documentElement.setAttribute('data-layout-mode', mode);
                    document.documentElement.setAttribute('data-sidebar-collapsed', collapsed ? 'true' : 'false');
                });
            });
        },

        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            this.applyLayout();
            if (window.Livewire) {
                Livewire.dispatch('sidebar-collapse-toggled', { collapsed: this.sidebarCollapsed });
            }
        },

        applyLayout() {
            document.documentElement.setAttribute('data-layout-mode', this.layoutMode);
            document.documentElement.setAttribute('data-sidebar-collapsed', this.sidebarCollapsed ? 'true' : 'false');
            localStorage.setItem('tallpbx:layout_mode', this.layoutMode);
            localStorage.setItem('tallpbx:sidebar_collapsed', this.sidebarCollapsed ? 'true' : 'false');
        },
    };
};

const sidebarScrollSelector = '[data-panel-sidebar-scroll]';
const sidebarLinkSelector = '[data-panel-sidebar-scroll] a[href]';
const sidebarNavigateLinkSelector = '[data-panel-sidebar-scroll] a[wire\\:navigate], [data-panel-sidebar-scroll] a[wire\\:navigate\\.preserve-scroll]';
const sidebarScrollStorageKey = 'pbx:sidebar-scroll';
const sidebarTargetStorageKey = 'pbx:sidebar-target';

let isRestoringSidebarScroll = false;
let sidebarScrollSaveLockedUntil = 0;

function sidebarScrollElements() {
    return document.querySelectorAll(sidebarScrollSelector);
}

function sidebarScrollKey(element) {
    return element.dataset.panelSidebarScroll;
}

function storedSidebarScrollPositions() {
    try {
        return JSON.parse(sessionStorage.getItem(sidebarScrollStorageKey) || '{}');
    } catch {
        return {};
    }
}

function saveSidebarScrollPosition(element) {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    const key = sidebarScrollKey(element);

    if (!key) {
        return;
    }

    const previousPosition = storedSidebarScrollPositions()[key] ?? 0;
    const isEarlyPageLoadReset = document.readyState !== 'complete'
        && element.scrollTop === 0
        && previousPosition > 0;

    if (
        isRestoringSidebarScroll
        || isEarlyPageLoadReset
        || (Date.now() < sidebarScrollSaveLockedUntil && element.scrollTop === 0 && previousPosition > 0)
    ) {
        return;
    }

    sessionStorage.setItem(
        sidebarScrollStorageKey,
        JSON.stringify({
            ...storedSidebarScrollPositions(),
            [key]: element.scrollTop,
        }),
    );
}

function saveSidebarScrollPositions() {
    sidebarScrollElements().forEach(saveSidebarScrollPosition);
}

function lockSidebarScrollSaves() {
    sidebarScrollSaveLockedUntil = Date.now() + 2000;
}

function saveSidebarNavigationTarget(link) {
    if (!(link instanceof HTMLAnchorElement)) {
        return;
    }

    sessionStorage.setItem(sidebarTargetStorageKey, link.href);
}

function currentSidebarLink() {
    const links = [...document.querySelectorAll(sidebarLinkSelector)];
    const target = sessionStorage.getItem(sidebarTargetStorageKey);

    return links.find((link) => link.href === target)
        || links.find((link) => link.hasAttribute('data-current'))
        || links.find((link) => link.classList.contains('menu-active'));
}

function scrollCurrentSidebarLinkIntoView() {
    currentSidebarLink()?.scrollIntoView({
        block: 'center',
        inline: 'nearest',
    });
}

function restoreSidebarScrollPositions() {
    const positions = storedSidebarScrollPositions();
    const hasStoredPosition = Object.values(positions).some(Number.isInteger);

    isRestoringSidebarScroll = true;

    sidebarScrollElements().forEach((element) => {
        const position = positions[sidebarScrollKey(element)];

        if (Number.isInteger(position)) {
            element.scrollTop = position;
        }
    });

    if (!hasStoredPosition) {
        scrollCurrentSidebarLinkIntoView();
    }

    requestAnimationFrame(() => {
        isRestoringSidebarScroll = false;
    });
}

function restoreSidebarScrollPositionsAfterNavigation() {
    restoreSidebarScrollPositions();
    requestAnimationFrame(restoreSidebarScrollPositions);
    setTimeout(restoreSidebarScrollPositions, 50);
    setTimeout(restoreSidebarScrollPositions, 250);
}

document.addEventListener('scroll', (event) => {
    if (event.target instanceof HTMLElement && event.target.matches(sidebarScrollSelector)) {
        saveSidebarScrollPosition(event.target);
    }
}, true);

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const link = event.target.closest(sidebarNavigateLinkSelector);

    if (link instanceof HTMLAnchorElement) {
        lockSidebarScrollSaves();
        saveSidebarNavigationTarget(link);
        saveSidebarScrollPositions();
    }
}, true);

document.addEventListener('livewire:navigating', () => {
    lockSidebarScrollSaves();
    saveSidebarScrollPositions();
});
document.addEventListener('beforeunload', saveSidebarScrollPositions);
document.addEventListener('DOMContentLoaded', restoreSidebarScrollPositionsAfterNavigation);
window.addEventListener('pageshow', restoreSidebarScrollPositionsAfterNavigation);
window.addEventListener('load', restoreSidebarScrollPositionsAfterNavigation);

document.addEventListener('livewire:navigated', restoreSidebarScrollPositionsAfterNavigation);

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
