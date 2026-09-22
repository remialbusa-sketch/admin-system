// Sidebar SPA highlight: wire:navigate keeps the Livewire island alive, so
// server request()->routeIs() is stale (POST /livewire/update). Drive the
// active state client-side from window.location, toggling the same Tailwind
// classes the Blade fallback uses.

function syncSidebarActive() {
    const currentPath = window.location.pathname.replace(/\/$/, '') || '/';

    document.querySelectorAll('[data-sidebar-link]').forEach((link) => {
        let hrefPath = '/';
        try {
            hrefPath = new URL(link.href, window.location.origin).pathname.replace(/\/$/, '') || '/';
        } catch {
            return;
        }

        const isPrefix = link.hasAttribute('data-sidebar-prefix');
        const active = isPrefix
            ? (currentPath === hrefPath || currentPath.startsWith(hrefPath + '/') || currentPath === '/visualize')
            : hrefPath === currentPath;
        const activeClasses = (link.dataset.active || '').split(/\s+/).filter(Boolean);
        const inactiveClasses = (link.dataset.inactive || '').split(/\s+/).filter(Boolean);

        if (active) {
            activeClasses.forEach((c) => link.classList.add(c));
            inactiveClasses.forEach((c) => link.classList.remove(c));
            link.setAttribute('aria-current', 'page');
        } else {
            activeClasses.forEach((c) => link.classList.remove(c));
            inactiveClasses.forEach((c) => link.classList.add(c));
            link.removeAttribute('aria-current');
        }

        // Analytics / Tables trailing dot
        const indicator = link.querySelector('[data-sidebar-indicator]');
        if (indicator) {
            indicator.classList.toggle('hidden', !active);
        }

        // Dashboards sub-dot color
        const sub = link.querySelector('[data-sidebar-subdot]');
        if (sub) {
            sub.classList.toggle('bg-primary', active);
            sub.classList.toggle('bg-base-content/25', !active);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncSidebarActive);
} else {
    syncSidebarActive();
}

document.addEventListener('livewire:navigated', syncSidebarActive);
