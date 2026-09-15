// Dashboard grid layout editor: in customize mode, drag widgets to reorder,
// drag the bottom-right handle to resize (snapped to the 12-column grid),
// remove widgets, add widgets and tune their settings.
//
// The editor works on a DRAFT: every change is synced to the Livewire
// component via `dashboard-layout-sync` (updates the draft, no persistence)
// and customize mode stays ON. Nothing is saved until the user clicks the
// Done button (`[data-grid-done]`), which collects the DOM geometry and
// dispatches `dashboard-layout-save`; the server persists and exits edit
// mode. Cancel/Reset are handled server-side via wire:click.
//
// The module initializes once per page and re-arms after wire:navigate
// navigations; dataset guards make repeated boots no-ops.

const debounce = (fn, wait = 400) => {
    let timeout = null;

    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn(...args), wait);
    };
};

const collectLayout = (grid) => [...grid.querySelectorAll('[data-widget-id]')].map((el) => ({
    id: el.dataset.widgetId,
    type: el.dataset.widgetType,
    w: parseInt(el.dataset.w || '4', 10),
    h: parseInt(el.dataset.h || '2', 10),
}));

// Draft sync: keeps the server-side draft aligned with the DOM without
// persisting anything and without leaving customize mode.
const syncDraft = debounce((grid) => {
    if (!window.Livewire) {
        return;
    }

    window.Livewire.dispatch('dashboard-layout-sync', { layout: collectLayout(grid) });
}, 400);

// Done: commit the draft (persist + exit customize mode).
const commitLayout = (grid) => {
    if (!window.Livewire) {
        return;
    }

    window.Livewire.dispatch('dashboard-layout-save', { layout: collectLayout(grid) });
};

const initDashboardGrid = () => {
    const grid = document.querySelector('[data-dashboard-grid]');

    if (!grid || grid.dataset.gridInit === '1') {
        return;
    }

    grid.dataset.gridInit = '1';
    const isEditing = () => grid.dataset.editing === 'true';

    // --- Reorder via HTML5 drag & drop ---------------------------------
    let dragEl = null;

    grid.addEventListener('dragstart', (event) => {
        const section = event.target.closest('[data-widget-id]');

        if (!isEditing() || !section) {
            event.preventDefault();
            return;
        }

        dragEl = section;
        section.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        try {
            event.dataTransfer.setData('text/plain', section.dataset.widgetId);
        } catch {
            // Some engines refuse setData; the drag still works.
        }
    });

    grid.addEventListener('dragend', () => {
        if (dragEl) {
            dragEl.classList.remove('is-dragging');
        }

        dragEl = null;
        grid.querySelectorAll('.widget-drop-target').forEach((el) => el.classList.remove('widget-drop-target'));
    });

    grid.addEventListener('dragover', (event) => {
        if (!dragEl || !isEditing()) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const over = event.target.closest('[data-widget-id]');
        grid.querySelectorAll('.widget-drop-target').forEach((el) => el.classList.remove('widget-drop-target'));

        if (over && over !== dragEl) {
            over.classList.add('widget-drop-target');
        }
    });

    grid.addEventListener('drop', (event) => {
        if (!dragEl || !isEditing()) {
            return;
        }

        event.preventDefault();

        const over = event.target.closest('[data-widget-id]');

        if (over && over !== dragEl) {
            const rect = over.getBoundingClientRect();
            const after = (event.clientY - rect.top) / rect.height > 0.5;
            over.insertAdjacentElement(after ? 'afterend' : 'beforebegin', dragEl);
            syncDraft(grid);
        }
    });

    // --- Resize width via the corner handle -----------------------------
    let resizing = null;

    grid.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-widget-resize]');

        if (!handle || !isEditing()) {
            return;
        }

        event.preventDefault();

        const section = handle.closest('[data-widget-id]');
        const gridRect = grid.getBoundingClientRect();
        const styles = getComputedStyle(grid);
        const gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
        const colWidth = (gridRect.width - gap * 11) / 12;

        resizing = {
            section,
            startX: event.clientX,
            startW: parseInt(section.dataset.w || '4', 10),
            colWidth,
            gap,
        };

        section.classList.add('is-dragging');
        document.body.style.cursor = 'ew-resize';
        document.body.style.userSelect = 'none';
    });

    document.addEventListener('pointermove', (event) => {
        if (!resizing) {
            return;
        }

        const deltaCols = Math.round((event.clientX - resizing.startX) / (resizing.colWidth + resizing.gap));
        const next = Math.min(12, Math.max(1, resizing.startW + deltaCols));

        if (next !== parseInt(resizing.section.dataset.w, 10)) {
            resizing.section.dataset.w = String(next);
            // Inline span for immediate feedback; cleared on release so the
            // draft classes take over after the Livewire rerender.
            resizing.section.style.gridColumn = `span ${next} / span ${next}`;
        }
    });

    document.addEventListener('pointerup', () => {
        if (!resizing) {
            return;
        }

        resizing.section.classList.remove('is-dragging');
        resizing.section.style.gridColumn = '';
        syncDraft(grid);
        resizing = null;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    });

    // --- Remove widget (draft only; Done persists) ------------------------
    grid.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-widget-remove]');

        if (!remove || !isEditing()) {
            return;
        }

        event.preventDefault();

        if (grid.querySelectorAll('[data-widget-id]').length <= 1) {
            return; // never leave the grid empty by accident; Reset restores all
        }

        remove.closest('[data-widget-id]').remove();
        syncDraft(grid);
    });
};

// --- Done button ---------------------------------------------------------
// Lives OUTSIDE the grid element (in the section header), so it is bound
// once at the document level with a module guard.
let doneBound = false;

const bindDoneButton = () => {
    if (doneBound) {
        return;
    }

    doneBound = true;

    document.addEventListener('click', (event) => {
        const done = event.target.closest('[data-grid-done]');

        if (!done) {
            return;
        }

        const grid = document.querySelector('[data-dashboard-grid][data-editing]');

        if (grid) {
            event.preventDefault();
            commitLayout(grid);
        }
    });
};

const bootDashboardGrid = () => {
    bindDoneButton();
    initDashboardGrid();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootDashboardGrid);
} else {
    bootDashboardGrid();
}

// wire:navigate swaps page content — re-arm after every navigation.
document.addEventListener('livewire:navigated', bootDashboardGrid);
