// Dashboard grid layout editor: in customize mode, drag widgets to reorder,
// resize from the right edge (width), the bottom edge (height) or the
// corner (both at once, snapped to the 12-column grid and row units),
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

const gridKeyOf = (grid) => grid.dataset.gridKey || 'default';

// Draft sync: keeps the server-side draft aligned with the DOM without
// persisting anything and without leaving customize mode. The grid key
// lets several independent grids share one page. Resize releases push
// immediately; drag/remove keep the debounce.
const pushLayout = (grid) => {
    if (!window.Livewire) {
        return;
    }

    window.Livewire.dispatch('dashboard-layout-sync', { layout: collectLayout(grid), grid: gridKeyOf(grid) });
};

const syncDraft = debounce(pushLayout, 400);

// Optimistic spans from a finished resize stay on the section until the
// Livewire rerender brings the draft's classes back — then they are
// cleared so the responsive classes own the layout again. (Clearing them
// at pointerup instead made the widget snap back to its old size for the
// debounce + round-trip window.)
const clearPendingSpans = (root = document) => {
    const targets = [];

    if (root?.matches?.('[data-span-pending]')) {
        targets.push(root);
    }

    root?.querySelectorAll?.('[data-span-pending]').forEach((el) => targets.push(el));

    targets.forEach((el) => {
        el.style.gridColumn = '';
        el.style.gridRow = '';
        delete el.dataset.spanPending;
    });
};

let morphCleanupHooked = false;

const hookMorphCleanup = () => {
    if (morphCleanupHooked || !window.Livewire?.hook) {
        return;
    }

    morphCleanupHooked = true;
    window.Livewire.hook('morphed', ({ el }) => clearPendingSpans(el ?? document));
};

// Done: commit the draft (persist + exit customize mode).
const commitLayout = (grid) => {
    if (!window.Livewire) {
        return;
    }

    window.Livewire.dispatch('dashboard-layout-save', { layout: collectLayout(grid), grid: gridKeyOf(grid) });
};

const initDashboardGrid = () => {
    document.querySelectorAll('[data-dashboard-grid]').forEach((grid) => {
        initGrid(grid);
    });
};

const initGrid = (grid) => {
    if (!grid || grid.dataset.gridInit === '1') {
        return;
    }

    grid.dataset.gridInit = '1';
    const isEditing = () => grid.dataset.editing === 'true';

    // --- Reorder via HTML5 drag & drop ---------------------------------
    let dragEl = null;

    grid.addEventListener('dragstart', (event) => {
        const section = event.target.closest('[data-widget-id]');

        // A native drag must never start while a resize session is armed:
        // touch drag-and-drop can fire dragstart even though pointerdown
        // was canceled, and a native drag stops all pointer events —
        // hijacking the gesture and freezing the resize mid-way.
        if (resizing || !isEditing() || !section) {
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

    // --- Resize: right edge = width, bottom edge = height, corner = both.
    // Width snaps to the 12 columns, height to row spans (1-6, mirroring the
    // server clamp in mergeWidgetGeometry). Feedback is inline grid spans
    // that stay until the Livewire rerender lands (clearPendingSpans), so a
    // release never snaps the widget back to the pre-drag size. -------------
    let resizing = null;

    const resizeCursor = (mode) => (mode === 'y' ? 'ns-resize' : mode === 'xy' ? 'nwse-resize' : 'ew-resize');

    grid.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-widget-resize]');

        if (!handle || !isEditing()) {
            return;
        }

        // Primary button only: a right-click on a grip must not arm a resize
        // (the context menu swallows the pointerup and the session stays
        // stuck, resizing again on the next unbuttoned move).
        if (event.button !== 0) {
            return;
        }

        event.preventDefault();

        const section = handle.closest('[data-widget-id]');

        // Self-heal: a previous session's optimistic spans are moot once a
        // new drag starts on the same widget.
        clearPendingSpans(section);

        // Explicit capture keeps events flowing when the cursor leaves the
        // viewport mid-drag (height shrink pulls toward the window edge) and
        // guarantees the release is delivered even outside the window.
        // Without it the resize froze part-way and a release outside was
        // never received, leaving the drag stuck armed.
        try {
            handle.setPointerCapture(event.pointerId);
        } catch {
            // Capture may refuse a stale pointer; the document listeners
            // still cover the in-viewport part of the gesture.
        }

        const mode = handle.dataset.widgetResize || 'x';
        const gridRect = grid.getBoundingClientRect();
        const styles = getComputedStyle(grid);
        const colGap = parseFloat(styles.columnGap || '0') || 0;
        const rowGap = parseFloat(styles.rowGap || styles.columnGap || '0') || 0;
        const colWidth = (gridRect.width - colGap * 11) / 12;
        const startH = parseInt(section.dataset.h || '2', 10);
        // Row heights are `auto` (content-driven), so derive the row unit
        // from this widget's own rendered height.
        const rowUnit = startH > 0 && section.offsetHeight > 0
            ? (section.offsetHeight - rowGap * (startH - 1)) / startH
            : 92;

        resizing = {
            section,
            mode,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            startW: parseInt(section.dataset.w || '4', 10),
            startH,
            colWidth,
            colGap,
            rowUnit,
            rowGap,
        };

        section.classList.add('is-dragging');
        document.body.style.cursor = resizeCursor(mode);
        document.body.style.userSelect = 'none';
    });

    document.addEventListener('pointermove', (event) => {
        if (!resizing || event.pointerId !== resizing.pointerId) {
            return;
        }

        if (resizing.mode === 'x' || resizing.mode === 'xy') {
            const deltaCols = Math.round((event.clientX - resizing.startX) / (resizing.colWidth + resizing.colGap));
            const next = Math.min(12, Math.max(1, resizing.startW + deltaCols));

            if (next !== parseInt(resizing.section.dataset.w, 10)) {
                resizing.section.dataset.w = String(next);
                resizing.section.style.gridColumn = `span ${next} / span ${next}`;
            }
        }

        if (resizing.mode === 'y' || resizing.mode === 'xy') {
            const deltaRows = Math.round((event.clientY - resizing.startY) / (resizing.rowUnit + resizing.rowGap));
            const next = Math.min(6, Math.max(1, resizing.startH + deltaRows));

            if (next !== parseInt(resizing.section.dataset.h, 10)) {
                resizing.section.dataset.h = String(next);
                resizing.section.style.gridRow = `span ${next} / span ${next}`;
            }
        }
    });

    const endResize = () => {
        if (!resizing) {
            return;
        }

        const { section } = resizing;

        section.classList.remove('is-dragging');

        // The optimistic spans stay until the Livewire rerender lands (the
        // morph hook above clears them); push the draft immediately instead
        // of debouncing so that rerender arrives as soon as possible.
        section.dataset.spanPending = '1';
        pushLayout(grid);

        resizing = null;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    };

    document.addEventListener('pointerup', endResize);
    document.addEventListener('pointercancel', endResize);
    document.addEventListener('lostpointercapture', endResize);
    window.addEventListener('blur', endResize);

    // --- Remove widget (draft only; Done persists) ------------------------
    grid.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-widget-remove]');

        if (!remove || !isEditing()) {
            return;
        }

        event.preventDefault();

        // The last widget is flagged, not silently kept: deleting it leaves
        // an intentionally empty dashboard (Reset restores the default).
        if (grid.querySelectorAll('[data-widget-id]').length <= 1) {
            if (!window.confirm('Delete the last widget? The dashboard will be empty until you add a widget.')) {
                return;
            }
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

        const key = done.dataset.gridDone || 'default';
        const grid = document.querySelector(`[data-dashboard-grid][data-grid-key="${key}"][data-editing]`)
            ?? document.querySelector('[data-dashboard-grid][data-editing]');

        if (grid) {
            event.preventDefault();
            commitLayout(grid);
        }
    });
};

const bootDashboardGrid = () => {
    hookMorphCleanup();
    bindDoneButton();
    initDashboardGrid();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootDashboardGrid);
} else {
    bootDashboardGrid();
}

// Wire up the morph cleanup as soon as Livewire is available: a boot can
// run before the Livewire script has defined window.Livewire.
document.addEventListener('livewire:init', hookMorphCleanup);

// wire:navigate swaps page content — re-arm after every navigation.
document.addEventListener('livewire:navigated', bootDashboardGrid);
