import { TabulatorFull as Tabulator } from 'tabulator-tables';
import 'tabulator-tables/dist/css/tabulator.min.css';

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

const debounce = (fn, wait = 400) => {
    let timeout = null;

    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn(...args), wait);
    };
};

const EDITOR_BY_TYPE = {
    text: 'input',
    email: 'input',
    phone: 'input',
    link: 'input',
    long_text: 'textarea',
    number: 'number',
    formula: 'number',
    date: 'date',
};

const optionValues = (options) => Object.fromEntries((options ?? []).map((option) => [option, option]));

// Custom editor for select/status/dropdown columns: a popup dropdown with a
// "+ New Label" button so editors can add new options (persisted for everyone).
// Rendered as an overlay appended to <body> so it is NOT clipped by the cell's
// overflow:hidden / fixed height.
const buildListEditor = (getColumn, callWire, setSuppress) => {
    const editor = (cell, onRendered, success, cancel) => {
        // Read the fresh column definition each time the editor opens so
        // optionIds (and options) reflect any options added/edited/deleted.
        const column = getColumn();

        const container = document.createElement('div');
        container.className = 'grid-list-editor';

        // Wrap success/cancel so the popup container is removed from <body>
        // when the editor closes (Tabulator only cleans up the cell, not the
        // body-appended popup).
        const cleanup = () => {
            container.remove();
            document.querySelectorAll('.grid-option-menu').forEach((m) => m.remove());
        };
        const done = (fn) => (...args) => {
            cleanup();
            fn(...args);
        };
        success = done(success);
        cancel = done(cancel);

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'grid-list-editor-input';
        input.placeholder = 'Search or select...';
        input.setAttribute('autocomplete', 'off');
        container.appendChild(input);

        const list = document.createElement('div');
        list.className = 'grid-list-editor-list';
        container.appendChild(list);

        const addRow = document.createElement('div');
        addRow.className = 'grid-list-editor-add';
        addRow.innerHTML = '<button type="button" class="grid-list-add-btn">+ New Label</button>';
        container.appendChild(addRow);

        // Show an inline input (within the dropdown) for creating a new label.
        const startInlineCreate = () => {
            addRow.innerHTML = '';
            const inline = document.createElement('input');
            inline.type = 'text';
            inline.className = 'grid-list-editor-inline';
            inline.placeholder = 'New label...';
            inline.setAttribute('autocomplete', 'off');
            addRow.appendChild(inline);

            const commit = () => {
                const val = inline.value.trim();
                if (!val) {
                    return;
                }
                if (setSuppress) {
                    setSuppress(true);
                }
                callWire('addColumnOption', column.key, val).then((newId) => {
                    if (!(column.options ?? []).includes(val)) {
                        column.options.push(val);
                    }
                    if (newId) {
                        column.optionIds = column.optionIds || {};
                        column.optionIds[val] = newId;
                    }
                    if (setSuppress) {
                        setSuppress(false);
                    }
                    success(val);
                });
            };

            inline.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.stopPropagation();
                    commit();
                } else if (e.key === 'Escape') {
                    e.stopPropagation();
                    render(input.value);
                }
            });
            inline.addEventListener('mousedown', (e) => e.stopPropagation());
            inline.focus();
        };

        // Show an inline input (within the dropdown) for editing a label.
        const startInlineEdit = (item, option, optionId) => {
            const label = item.querySelector('.grid-list-editor-item-label');
            const inline = document.createElement('input');
            inline.type = 'text';
            inline.className = 'grid-list-editor-inline';
            inline.value = option;
            inline.setAttribute('autocomplete', 'off');
            label.replaceWith(inline);

            const commit = () => {
                const val = inline.value.trim();
                if (!val || val === option) {
                    render(input.value);
                    return;
                }
                // If the option has no stored id yet (a core option), create a
                // stored option first so it becomes editable.
                const apply = (id) => {
                    const idx = (column.options ?? []).indexOf(option);
                    if (idx !== -1) {
                        column.options[idx] = val;
                    }
                    column.optionIds = column.optionIds || {};
                    delete column.optionIds[option];
                    column.optionIds[val] = id;
                    if (setSuppress) {
                        setSuppress(false);
                    }
                    render(input.value);
                };
                if (setSuppress) {
                    setSuppress(true);
                }
                if (optionId) {
                    callWire('updateColumnOption', optionId, val).then(() => apply(optionId));
                } else {
                    callWire('addColumnOption', column.key, val).then((newId) => apply(newId || optionId));
                }
            };

            inline.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.stopPropagation();
                    commit();
                } else if (e.key === 'Escape') {
                    e.stopPropagation();
                    render(input.value);
                }
            });
            inline.addEventListener('mousedown', (e) => e.stopPropagation());
            inline.focus();
        };

        const render = (filter = '') => {
            list.innerHTML = '';
            const options = column.options ?? [];
            const filtered = options.filter((o) => o.toLowerCase().includes(filter.toLowerCase()));

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'grid-list-editor-empty';
                empty.textContent = 'No matching options';
                list.appendChild(empty);
                return;
            }

            filtered.forEach((option) => {
                const item = document.createElement('div');
                item.className = 'grid-list-editor-item';

                const label = document.createElement('span');
                label.className = 'grid-list-editor-item-label';
                label.textContent = option;
                label.addEventListener('click', () => success(option));
                item.appendChild(label);

                // Ellipsis menu (edit / delete) on EVERY option.
                const optionId = (column.optionIds ?? {})[option];
                const menuBtn = document.createElement('button');
                menuBtn.type = 'button';
                menuBtn.className = 'grid-list-editor-menu-btn';
                menuBtn.textContent = '⋯';
                menuBtn.title = 'More actions';
                menuBtn.setAttribute('aria-label', 'More actions');
                menuBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openOptionMenu(menuBtn, item, option, optionId);
                });
                item.appendChild(menuBtn);

                list.appendChild(item);
            });
        };

        // Open the edit/delete menu for an option.
        const openOptionMenu = (anchor, item, option, optionId) => {
            // Close any existing menu.
            document.querySelectorAll('.grid-option-menu').forEach((m) => m.remove());

            const menu = document.createElement('div');
            menu.className = 'grid-option-menu';

            const editItem = document.createElement('div');
            editItem.className = 'grid-option-menu-item';
            editItem.textContent = 'Edit';
            editItem.addEventListener('click', () => {
                menu.remove();
                startInlineEdit(item, option, optionId);
            });
            menu.appendChild(editItem);

            // Delete is only available for stored options (has an id).
            if (optionId) {
                const deleteItem = document.createElement('div');
                deleteItem.className = 'grid-option-menu-item grid-option-menu-item-danger';
                deleteItem.textContent = 'Delete';
                deleteItem.addEventListener('click', () => {
                    menu.remove();
                    if (!window.confirm(`Delete label "${option}"?`)) {
                        return;
                    }
                    if (setSuppress) {
                        setSuppress(true);
                    }
                    callWire('deleteColumnOption', optionId).then(() => {
                        const idx = (column.options ?? []).indexOf(option);
                        if (idx !== -1) {
                            column.options.splice(idx, 1);
                        }
                        if (column.optionIds) {
                            delete column.optionIds[option];
                        }
                        if (setSuppress) {
                            setSuppress(false);
                        }
                        render(input.value);
                    });
                });
                menu.appendChild(deleteItem);
            }

            const rect = anchor.getBoundingClientRect();
            const holder = anchor.closest('.tabulator-tableholder') || anchor.closest('.spreadsheet-grid') || document.body;
            const holderRect = holder.getBoundingClientRect();
            menu.style.position = 'absolute';
            menu.style.top = `${rect.bottom - holderRect.top + holder.scrollTop + 2}px`;
            menu.style.left = `${rect.left - holderRect.left + holder.scrollLeft}px`;
            menu.style.zIndex = '1001';
            holder.appendChild(menu);

            // Prevent the editor's outside-click handler from firing when the
            // user interacts with this menu (otherwise cancel() runs and breaks
            // Tabulator's editor cleanup).
            menu.addEventListener('mousedown', (e) => e.stopPropagation());

            const closeMenu = (e) => {
                if (!menu.contains(e.target)) {
                    menu.remove();
                    document.removeEventListener('mousedown', closeMenu);
                }
            };
            document.addEventListener('mousedown', closeMenu);
        };

        input.addEventListener('input', () => render(input.value));
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const val = input.value.trim();
                if (val && (column.options ?? []).includes(val)) {
                    success(val);
                }
            } else if (e.key === 'Escape') {
                cancel();
            }
        });

        addRow.querySelector('button').addEventListener('click', (e) => {
            e.stopPropagation();
            startInlineCreate();
        });

        render();

        // Anchor the dropdown to the cell's scroll container so it stays with
        // the field (not floating/sticky over the viewport). We append it to
        // the .tabulator-tableholder (the scroll container) with absolute
        // positioning so it scrolls with the table and stays attached to the
        // cell it came from.
        const cellEl = cell.getElement();
        const holder = cellEl.closest('.tabulator-tableholder') || cellEl.closest('.spreadsheet-grid') || document.body;
        holder.style.position = 'relative';
        const cellRect = cellEl.getBoundingClientRect();
        const holderRect = holder.getBoundingClientRect();
        container.style.position = 'absolute';
        container.style.top = `${cellRect.top - holderRect.top + holder.scrollTop + cellRect.height + 2}px`;
        container.style.left = `${cellRect.left - holderRect.left + holder.scrollLeft}px`;
        container.style.zIndex = '1000';

        onRendered(() => {
            holder.appendChild(container);
            input.focus();

            // Close the popup when clicking outside of it (but not on the
            // option menu, which is a separate popup).
            const onDocClick = (e) => {
                const inMenu = e.target.closest && e.target.closest('.grid-option-menu');
                if (!container.contains(e.target) && !inMenu) {
                    document.removeEventListener('mousedown', onDocClick);
                    cancel();
                }
            };
            document.addEventListener('mousedown', onDocClick);
        });

        return container;
    };

    return { editor };
};

const buildEditor = (column, callWire, getColumn, setSuppress) => {
    if (!column.editable) {
        return {};
    }

    if (column.type === 'select' || column.type === 'status' || column.type === 'dropdown') {
        return buildListEditor(getColumn || (() => column), callWire, setSuppress);
    }

    if (column.type === 'checkbox') {
        return { editor: 'tickCross' };
    }

    const editor = EDITOR_BY_TYPE[column.type];

    return editor ? { editor } : {};
};

const statusColor = (column, label) => {
    const option = (column.settings?.options ?? []).find((candidate) => candidate.label === label);

    return option?.color ?? '#64748B';
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('managedTableGrid', ({ tableKey, editable }) => ({
        tableKey,
        editable,
        status: 'Ready',
        table: null,
        /**
         * Server-rendered wire:id of THIS grid component. Never store the
         * $wire proxy on the Alpine data object: Alpine's reactive wrapper
         * turns it into the magic's no-op fallback (a plain function whose
         * `.call` is Function.prototype.call) and leaks Vue internals such as
         * __v_raw as method names — malformed calls that crash Livewire's
         * request pool ("Cannot read properties of undefined (reading
         * 'shift')"). Resolve the component from the DOM id at call time.
         */
        wireId: null,
        columnDefs: [],
        pendingChanges: [],
        hasChanges: false,
        selectedCount: 0,
        showArchived: false,
        newRowId: null,
        densitySelect: null,
        _suppressRefresh: false,

        init() {
            const root = this.$root.closest('[wire\\:id]');
            this.wireId = root ? root.getAttribute('wire:id') : null;
            this.persistLayout = debounce(() => this.saveLayout(), 450);
            this.densitySelect = this.$root.querySelector('[data-density]');
            if (this.densitySelect) {
                this.densitySelect.addEventListener('change', (e) => this.setDensity(e.target.value));
            }

            this.mountOrRefresh();

            // Livewire 3 no longer dispatches the `livewire:updated` DOM event,
            // so the grid never refreshed after page changes / search / filters.
            // The `commit` hook's `succeed` callback fires BEFORE the DOM morphs,
            // so reading the payload there yields the previous page. Instead we
            // listen to `morph.updated`, which fires AFTER each element is updated,
            // and debounce so the grid re-renders once per commit with fresh data.
            this._refresh = debounce(() => this.mountOrRefresh(), 50);
            this._onMorphUpdated = () => this._refresh();
            Livewire.hook('morph.updated', this._onMorphUpdated);
        },

        // Call a method on THIS grid's Livewire component. `Livewire.find()`
        // returns the raw $wire proxy for the server-rendered id; `$call` is
        // Livewire's documented method-invocation API and never resolves to
        // Function.prototype.call or a Vue internal.
        callWire(method, ...args) {
            const component = this.wireId ? Livewire.find(this.wireId) : null;

            if (!component) {
                return Promise.reject(new Error(`Livewire component not found for "${method}"`));
            }

            return component.$call(method, ...args);
        },

        destroy() {
            // Livewire.hook has no unregister API; mountOrRefresh() is idempotent
            // and readPayload() returns null once this component is detached, so
            // the callback is inert after this component is gone.
            this.closeColumnMenu();
            this.table?.destroy();
        },

        readPayload() {
            const script = this.$root.querySelector('[data-managed-table-payload]');

            if (!script) {
                return null;
            }

            try {
                return JSON.parse(script.textContent || '{}');
            } catch {
                return null;
            }
        },

        mountOrRefresh() {
            const payload = this.readPayload();

            if (!payload || !Array.isArray(payload.columns)) {
                return;
            }

            // Sort state for the overflow menu's checked direction.
            this._sortMeta = payload.meta || null;

            // A Livewire morph rebuilds headers from scratch — never leave a
            // menu pointing at a detached header button.
            this.closeColumnMenu();

            // Mirror the server's archive view so the row menu / action bar
            // offer Restore instead of Archive when in the archive view.
            this.showArchived = !!payload.meta?.showArchived;

            const gridEl = this.$root.querySelector('[data-managed-table-grid]');

            if (!gridEl) {
                return;
            }

            if (!this.table) {
                this.buildTable(gridEl, payload);

                return;
            }

            // Full payload signature (columns + rows + meta) so this is idempotent:
            // it only re-renders when the data actually changed, even though the
            // commit hook fires for every Livewire action on the page.
            const nextSignature = JSON.stringify({ columns: payload.columns, rows: payload.rows, meta: payload.meta });

            if (nextSignature === this._lastPayloadSignature) {
                return;
            }

            // If there are unsaved edits, don't clobber the grid with server data
            // (e.g. from a Livewire morph triggered by search/filter/pagination).
            if (this.hasChanges || this._suppressRefresh) {
                return;
            }

            this._lastPayloadSignature = nextSignature;

            const nextKeys = payload.columns.map((column) => `${column.key}:${column.width ?? ''}:${column.hidden}:${column.frozen}`).join('|');

            if (nextKeys !== this._lastColumnSignature) {
                this._lastColumnSignature = nextKeys;
                this.columnDefs = payload.columns;
                this.table.setColumns(this.buildColumnDefs(payload.columns));
            }

            // Preserve the user's selection by row id across the refresh:
            // replaceData() rebuilds every row object, so a selection made
            // right after a floating-bar action used to be dropped by the
            // post-action refresh (the bar then never re-appeared until the
            // row was ticked a second time).
            const selectedIds = (this.table.getSelectedRows() ?? [])
                .map((row) => row.getData().id)
                .filter((id) => id !== undefined && id !== null);

            this.table.replaceData(payload.rows);

            if (selectedIds.length > 0) {
                const reselect = this.table.getRows().filter((row) => selectedIds.includes(row.getData().id));

                if (reselect.length > 0) {
                    this.table.selectRow(reselect);
                }
            }

            this.applyRemoteSort(payload.meta);
            // Re-anchor checkbox visuals + count after data replacement.
            this.updateSelectionUI();
            this.status = 'Ready';
        },

        buildTable(gridEl, payload) {
            this.columnDefs = payload.columns;
            this._lastColumnSignature = payload.columns.map((column) => `${column.key}:${column.width ?? ''}:${column.hidden}:${column.frozen}`).join('|');
            this._lastPayloadSignature = JSON.stringify({ columns: payload.columns, rows: payload.rows, meta: payload.meta });

            this.table = new Tabulator(gridEl, {
                data: payload.rows,
                columns: this.buildColumnDefs(payload.columns),
                layout: 'fitDataFill',
                height: '100%',
                rowHeight: this.loadDensity(),
                movableColumns: true,
                placeholder: 'No records found.',
                clipboard: true,
                clipboardCopyRowRange: 'range',
                clipboardPasteAction: 'update',
                history: true,
                // Row multi-select (used for bulk actions) with a frozen
                // checkbox column (see the _select column definition).
                // Note: `rowSelection` is not a Tabulator 6 option and was
                // silently ignored; selection is driven by selectableRows*.
                selectableRows: true,
                selectableRowsRangeMode: 'click',
                // Open the cell editor on a single click (more intuitive than
                // Tabulator's default double-click).
                editTriggerEvent: 'click',
                // Keep field keys flat: our data uses flat keys like
                // "account.customer_name" rather than nested objects.
                nestedFieldSeparator: false,
                columnDefaults: {
                    headerSort: true,
                    resizable: true,
                    minWidth: 120,
                    tooltip: true,
                },
            });

            // Expose density/selection to the toolbar controls.
            if (typeof window !== 'undefined' && this.densitySelect) {
                this.densitySelect.value = this.loadDensityLabel();
            }

            this.table.on('tableBuilt', () => {
                this.applyRemoteSort(payload.meta);
                // Keyboard: make headers focusable and sortable via Enter/Space (.impeccable.md:20 — keyboard movement)
                setTimeout(() => {
                    const holder = gridEl.querySelector('.tabulator-headers');
                    if (!holder) return;
                    holder.querySelectorAll('.tabulator-col[tabulator-field]').forEach((colEl) => {
                        const field = colEl.getAttribute('tabulator-field');
                        if (!field || field === '_select') return;
                        colEl.setAttribute('tabindex', '0');
                        colEl.setAttribute('role', 'button');
                        colEl.setAttribute('aria-label', `Sort by ${colEl.textContent.trim()}`);
                        colEl.addEventListener('keydown', (ev) => {
                            if (ev.key === 'Enter' || ev.key === ' ') {
                                ev.preventDefault();
                                this.callWire('sortBy', field);
                            }
                        });
                    });
                }, 80);
            });
            this.table.on('cellEdited', (cell) => this.handleCellEdited(cell));
            this.table.on('rowAdded', (row) => this.handleRowAdded(row));
            this.table.on('columnMoved', () => this.persistLayout());
            this.table.on('columnResized', () => this.persistLayout());
            this.table.on('columnVisibilityChanged', () => this.persistLayout());
            this.table.on('rowSelectionChanged', () => this.updateSelectionUI());
            this.table.on('dataProcessed', () => this.applySearchHighlight());
        },

        // --- Density control ------------------------------------------------
        get densityKey() {
            return `admin-grid-density:${this.tableKey ?? 'default'}`;
        },
        loadDensityLabel() {
            const stored = localStorage.getItem(this.densityKey);
            return ['condensed', 'standard', 'comfortable'].includes(stored) ? stored : 'standard';
        },
        loadDensity() {
            return ({ condensed: 36, standard: 48, comfortable: 58 })[this.loadDensityLabel()];
        },
        setDensity(label) {
            const rowHeights = { condensed: 36, standard: 48, comfortable: 58 };
            if (!(label in rowHeights)) {
                return;
            }
            localStorage.setItem(this.densityKey, label);
            this.table.setRowHeight(rowHeights[label]);
            this.table.redraw();
        },

        // --- Row-selection checkbox formatters -----------------------------
        //
        // Tabulator's built-in `rowSelection` formatter misbehaves when
        // selectableRowsRangeMode is "click": its click listener runs
        // handleComplexRowClick (deselect everything, then re-select the
        // clicked row) BEFORE the change event fires, and the change handler
        // then swallows its toggle behind the "blocked" flag. Net effect: a
        // plain click on the checkbox could select a row but never unselect
        // it. These custom formatters let the checkbox own its toggle; the
        // rest of the row keeps Tabulator's click / ctrl+click / shift+click
        // selection behaviour.
        rowSelectFormatter(cell) {
            const row = cell.getRow();
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.setAttribute('aria-label', 'Select row');
            checkbox.checked = !!row.isSelected();

            // Stop the click from bubbling to the row's click handler, which
            // would immediately re-run the "select this row" logic.
            checkbox.addEventListener('mousedown', (e) => e.stopPropagation());
            checkbox.addEventListener('click', (e) => e.stopPropagation());
            checkbox.addEventListener('change', () => row.toggleSelect());

            // Register with Tabulator's selectRow module so the box is kept
            // in sync when the selection changes elsewhere (header select-all,
            // ctrl/shift+click on the row, bulk delete, data refresh).
            const table = typeof cell.getTable === 'function' ? cell.getTable() : null;
            if (table && table.modules && table.modules.selectRow) {
                table.modules.selectRow.registerRowSelectCheckbox(row, checkbox);
            }

            return checkbox;
        },

        headerSelectFormatter(cell) {
            const table = typeof cell.getTable === 'function' ? cell.getTable() : null;
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.setAttribute('aria-label', 'Select all rows');

            const sync = () => {
                const total = (table && table.getRows ? table.getRows() : []).length;
                const selected = (table && table.getSelectedRows ? table.getSelectedRows() : []).length;
                checkbox.checked = total > 0 && selected === total;
                checkbox.indeterminate = selected > 0 && selected < total;
            };
            sync();

            checkbox.addEventListener('mousedown', (e) => e.stopPropagation());
            checkbox.addEventListener('click', (e) => e.stopPropagation());
            // Standard select-all semantics: a fully checked box clears, while
            // an unchecked or partial (indeterminate) box selects every row.
            checkbox.addEventListener('change', () => {
                if (!table) {
                    return;
                }

                const total = (table.getRows ? table.getRows() : []).length;
                const selected = (table.getSelectedRows ? table.getSelectedRows() : []).length;

                if (total > 0 && selected === total) {
                    table.deselectRow();
                } else {
                    table.selectRow();
                }
            });

            // Tabulator re-checks/re-indeterminates this element on every
            // selection change, including after data refreshes.
            if (table && table.modules && table.modules.selectRow) {
                table.modules.selectRow.registerHeaderSelectCheckbox(checkbox);
            }

            return checkbox;
        },

        // --- Multi-select / selection actions ------------------------------
        updateSelectionUI() {
            this.syncSelectionCheckboxes();
        },

        /**
         * Force every row checkbox (and the header select-all) to reflect the
         * live selection. The Tabulator module normally keeps the boxes in
         * sync via registerRowSelectCheckbox, but grid re-renders (Livewire
         * morphs, column changes, data replacement) can recreate elements
         * without re-firing selection events, leaving boxes that LOOK
         * unchecked while the row is selected. This makes the visual state
         * authoritative after every selection change and data refresh.
         */
        syncSelectionCheckboxes() {
            if (!this.table) {
                this.selectedCount = 0;

                return;
            }

            const rows = this.table.getRows();
            // The selection module is authoritative; row objects can be stale
            // after replaceData(), so match by id instead of trusting
            // row.isSelected() on a detached component.
            const selectedIds = new Set(
                (this.table.getSelectedRows() ?? []).map((row) => row.getData().id),
            );

            rows.forEach((row) => {
                const isSelected = selectedIds.has(row.getData().id);
                const checkbox = row._row?.modules?.select?.checkboxEl;

                if (checkbox) {
                    checkbox.checked = isSelected;
                }
            });

            const selected = selectedIds.size;

            const header = this.table.modules?.selectRow?.headerCheckboxElement;

            if (header) {
                header.checked = rows.length > 0 && selected === rows.length;
                header.indeterminate = selected > 0 && selected < rows.length;
            }

            this.selectedCount = selected;
        },
        getSelectedIds() {
            return (this.table?.getSelectedRows() ?? [])
                .map((row) => row.getData().id)
                .filter((id) => id !== undefined && id !== null);
        },

        /** Duplicate / archive / restore the given rows via Livewire. */
        runRowAction(method, rows, successStatus) {
            const ids = rows.map((row) => row.getData().id).filter((id) => id >= 0);

            if (ids.length === 0) {
                return;
            }

            this.callWire(method, ids).then(() => {
                // Optimistic local removal: archived/restored rows leave the
                // current listing immediately; the next server refresh (the
                // morph hook) reconciles the rest.
                rows.forEach((row) => {
                    if (method !== 'duplicateSelected') {
                        this.table?.deleteRow(row);
                    }
                });
                this.clearSelection();
                this.status = successStatus;
            });
        },

        duplicateSelected() {
            const rows = this.table?.getSelectedRows() ?? [];

            if (rows.length === 0) {
                return;
            }

            this.runRowAction('duplicateSelected', rows, 'Duplicated');
        },

        archiveSelected() {
            const rows = this.table?.getSelectedRows() ?? [];

            if (rows.length === 0) {
                return;
            }

            this.runRowAction('archiveSelected', rows, 'Archived');
        },

        restoreSelected() {
            const rows = this.table?.getSelectedRows() ?? [];

            if (rows.length === 0) {
                return;
            }

            this.runRowAction('restoreSelected', rows, 'Restored');
        },

        exportSelectedRows() {
            const ids = this.getSelectedIds().filter((id) => id >= 0);

            if (ids.length === 0) {
                return;
            }

            this.callWire('exportSelectedRows', ids);
        },

        deleteSelected() {
            const rows = this.table?.getSelectedRows() ?? [];

            if (rows.length === 0) {
                return;
            }

            const realIds = this.getSelectedIds().filter((id) => id >= 0);
            const unsaved = rows.length - realIds.length;
            const target = realIds.length || rows.length;
            const extra = unsaved > 0 ? ' (new unsaved rows are discarded)' : '';

            if (!window.confirm(`Delete ${target} selected record${target === 1 ? '' : 's'}${extra}? This cannot be undone.`)) {
                return;
            }

            const finish = () => {
                rows.forEach((row) => this.table?.deleteRow(row));
                this.clearSelection();
                this.status = 'Deleted';
            };

            if (realIds.length === 0) {
                finish();

                return;
            }

            this.callWire('deleteSelected', realIds).then(finish);
        },

        // Single-row actions from the ellipsis context menu.
        runSingleRowAction(method, row, successStatus) {
            const rowId = row.getData().id;

            if (rowId === undefined || rowId === null) {
                return;
            }

            this.callWire(method, [rowId]).then(() => {
                if (method !== 'duplicateSelected') {
                    this.table?.deleteRow(row);
                }
                this.status = successStatus;
            });
        },

        deselectRow() {
            this.table?.deselectRow();
        },
        clearSelection() {
            this.table?.deselectRow();
            this.updateSelectionUI();
        },

        // --- Client-side search highlight (server already filters; just mark matches) ---
        setSearchTerm(term) {
            this._searchTerm = (term ?? '');
            this.applySearchHighlight();
        },
        applySearchHighlight() {
            const term = (this._searchTerm ?? '').trim();
            const table = this.table;
            if (!table) {
                return;
            }
            table.getRows().forEach((row) => {
                row.getCells().forEach((cell) => {
                    const el = cell.getElement?.();
                    // Only wrap plain text cells we rendered ourselves; skip
                    // custom formatters (status pills, editors).
                    if (!el || this.isFormattedCell(cell)) {
                        return;
                    }
                    if (!el.dataset.searchMarked) {
                        el.dataset.searchMarked = '1';
                    } else if (el.querySelector('mark.grid-search-hit')) {
                        return;
                    }
                    if (!term || !el.textContent.toLowerCase().includes(term.toLowerCase())) {
                        return;
                    }
                    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
                    const textNodes = [];
                    while (walker.nextNode()) {
                        const node = walker.currentNode;
                        if (node.parentElement && node.parentElement.closest('mark')) {
                            continue;
                        }
                        textNodes.push(node);
                    }
                    textNodes.forEach((node) => {
                        const text = node.nodeValue;
                        const idx = text.toLowerCase().indexOf(term.toLowerCase());
                        if (idx === -1) {
                            return;
                        }
                        const mark = document.createElement('mark');
                        mark.className = 'grid-search-hit';
                        mark.textContent = text.slice(idx, idx + term.length);
                        const rest = document.createTextNode(text.slice(idx + term.length));
                        const before = document.createTextNode(text.slice(0, idx));
                        node.replaceWith(before, mark, rest);
                    });
                });
            });
        },
        isFormattedCell(cell) {
            const field = cell.getColumn()?.getField();

            if (field === '_select') {
                return true;
            }

            if (cell.getValue() === '') {
                return true;
            }

            const type = this.columnDefs?.find((c) => c.key === field)?.type;

            return ['select', 'status', 'dropdown', 'checkbox'].includes(type);
        },

        buildColumnDefs(columns) {
            // Frozen multi-select column: ticking checkboxes drives the row
            // selection that powers the floating action bar.
            const defs = [{
                // Custom checkbox formatters (see rowSelectFormatter above):
                // the built-in 'rowSelection' formatter cannot unselect rows
                // with a plain click while selectableRowsRangeMode is 'click'.
                title: '',
                field: '_select',
                formatter: (cell) => this.rowSelectFormatter(cell),
                titleFormatter: (cell) => this.headerSelectFormatter(cell),
                headerSort: false,
                hozAlign: 'center',
                width: 44,
                minWidth: 44,
                maxWidth: 44,
                resizable: false,
                frozen: true,
                cssClass: 'admin-select-col',
            }];

            const mapped = columns.map((column) => {
                const def = {
                    title: column.label,
                    field: column.key,
                    width: column.width || undefined,
                    frozen: !!column.frozen,
                    visible: !column.hidden,
                    sorter: () => 0,
                    // No sorter arrow and no header-click sort: sorting lives
                    // in the overflow (⋮) menu.
                    headerSort: false,
                    // Overflow (⋮) menu: the discoverable, touch-friendly twin
                    // of the right-click header menu. The button stops
                    // propagation so opening the menu never sorts the column.
                    titleFormatter: (cell) => this.headerTitleWithMenu(cell),
                    headerContextMenu: (_event, col) => [
                        {
                            label: col.isVisible() ? 'Hide column' : 'Show column',
                            action: (_e, c) => {
                                c.toggle();
                                this.persistLayout();
                            },
                        },
                        {
                            label: col.getDefinition().frozen ? 'Unfreeze column' : 'Freeze column',
                            action: (_e, c) => {
                                c.updateDefinition({ frozen: !c.getDefinition().frozen });
                                this.persistLayout();
                            },
                        },
                    ],
                    ...buildEditor(column, (method, ...args) => this.callWire(method, ...args), () => this.columnDefs.find((c) => c.key === column.key) || column, (v) => { this._suppressRefresh = v; }),
                };

                if (column.type === 'status') {
                    def.formatter = (cell) => {
                        const value = cell.getValue();

                        if (!value) {
                            return '<span class="grid-empty-value">Unassigned</span>';
                        }

                        const color = statusColor(column, value);

                        return `<span class="grid-status-pill" style="background:color-mix(in oklab, ${color} 15%, transparent); color:${color};">${escapeHtml(value)}</span>`;
                    };
                } else if (column.type === 'checkbox') {
                    def.formatter = 'tickCross';
                    def.hozAlign = 'center';
                } else if (column.type === 'number' || column.type === 'formula') {
                    def.hozAlign = 'right';
                    def.formatter = (cell) => escapeHtml(cell.getValue() ?? '');
                } else {
                    def.formatter = (cell) => escapeHtml(cell.getValue() ?? '');
                }

                return def;
            });

            mapped.forEach((def) => defs.push(def));

            return defs;
        },

        /** Header title + overflow (⋮) button. Returns a live DOM node. */
        headerTitleWithMenu(cell) {
            const col = cell.getColumn();
            const title = col.getDefinition().title || '';

            const wrapper = document.createElement('span');
            wrapper.className = 'grid-col-title';

            const label = document.createElement('span');
            label.className = 'grid-col-title-label';
            label.textContent = title;
            wrapper.appendChild(label);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'grid-col-menu-btn no-print';
            btn.title = `Column actions for ${title}`;
            btn.setAttribute('aria-label', `Column actions for ${title}`);
            btn.setAttribute('aria-haspopup', 'menu');
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM10 14a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/></svg>';
            btn.addEventListener('click', (event) => {
                event.stopPropagation();
                event.preventDefault();
                this.openColumnMenu(col, btn);
            });
            btn.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.stopPropagation();
                    event.preventDefault();
                    this.openColumnMenu(col, btn);
                }
            });
            wrapper.appendChild(btn);

            return wrapper;
        },

        closeColumnMenu() {
            if (this._colMenu) {
                this._colMenu.remove();
                this._colMenu = null;
            }

            if (this._colMenuCleanup) {
                this._colMenuCleanup();
                this._colMenuCleanup = null;
            }
        },

        /** Build + position the overflow menu for one header column. */
        openColumnMenu(column, anchor) {
            this.closeColumnMenu();

            const field = column.getField();

            if (field === '_select') {
                return;
            }

            const info = this.columnDefs.find((c) => c.key === field) || {};
            const canEdit = !!this.editable;
            const isCustom = !!info.custom;
            const label = column.getDefinition().title || field;
            const typeLabel = info.type ? info.type.replace(/_/g, ' ') : '';
            const structureLocked = !canEdit || !isCustom;

            const menu = document.createElement('div');
            menu.className = 'grid-col-menu no-print';
            menu.setAttribute('role', 'menu');
            menu.setAttribute('aria-label', `Column actions for ${label}`);

            const header = document.createElement('p');
            header.className = 'grid-col-menu-head';
            const name = document.createElement('span');
            name.className = 'grid-col-menu-name';
            name.textContent = label;
            header.appendChild(name);

            if (typeLabel) {
                const badge = document.createElement('span');
                badge.className = 'grid-col-menu-badge';
                badge.textContent = `${typeLabel}${isCustom ? ' · custom' : ' · built-in'}`;
                header.appendChild(badge);
            }

            menu.appendChild(header);

            const addItem = (text, run, { danger = false, disabled = false } = {}) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'grid-col-menu-item';
                item.setAttribute('role', 'menuitem');
                item.textContent = text;

                if (danger) {
                    item.classList.add('grid-col-menu-danger');
                }

                if (disabled) {
                    item.disabled = true;
                    item.classList.add('grid-col-menu-disabled');
                    item.title = 'Needs edit access';
                } else {
                    item.addEventListener('click', () => {
                        this.closeColumnMenu();
                        run();
                    });
                }

                menu.appendChild(item);
            };

            const addDivider = () => {
                const divider = document.createElement('div');
                divider.className = 'grid-col-menu-divider';
                divider.setAttribute('aria-hidden', 'true');
                menu.appendChild(divider);
            };

            const openManageModal = () => {
                window.dispatchEvent(new CustomEvent('open-modal', { detail: { name: 'manage-columns' } }));

                window.setTimeout(() => {
                    document.querySelector('[wire\\:model="newColumnName"], [wire\\:model="renamingColumnName"]')?.focus();
                }, 200);
            };

            // Sort group replaces the sorter arrow: explicit direction, with
            // the active direction checked.
            const sort = this._sortMeta || {};
            const sortedAsc = sort.sortField === field && sort.sortDirection === 'asc';
            const sortedDesc = sort.sortField === field && sort.sortDirection === 'desc';

            addItem(`${sortedAsc ? '✓ ' : ''}Sort ascending`, () => {
                if (!sortedAsc) {
                    this.callWire('setSort', field, 'asc').catch(() => undefined);
                }
            });
            addItem(`${sortedDesc ? '✓ ' : ''}Sort descending`, () => {
                if (!sortedDesc) {
                    this.callWire('setSort', field, 'desc').catch(() => undefined);
                }
            });
            addItem('Clear sort', () => {
                this.callWire('clearSort').catch(() => undefined);
            }, { disabled: sort.sortField !== field });

            addDivider();

            // Insert group: needs a follow-up in Manage columns, so prefill
            // the slot first, then open the modal once the server roundtrip
            // (and its morph) has landed.
            addItem('Insert column left', () => {
                this.callWire('prefillAddColumn', field, 'left').then(openManageModal).catch(() => undefined);
            }, { disabled: structureLocked });
            addItem('Insert column right', () => {
                this.callWire('prefillAddColumn', field, 'right').then(openManageModal).catch(() => undefined);
            }, { disabled: structureLocked });

            addDivider();

            addItem(column.isVisible() ? 'Hide column' : 'Show column', () => {
                column.toggle();
                this.persistLayout();
            });
            addItem(column.getDefinition().frozen ? 'Unfreeze column' : 'Freeze column', () => {
                column.updateDefinition({ frozen: !column.getDefinition().frozen });
                this.persistLayout();
            });
            addItem('Move to start', () => {
                this.callWire('moveColumnToEdge', field, 'start').catch(() => undefined);
            });
            addItem('Move to end', () => {
                this.callWire('moveColumnToEdge', field, 'end').catch(() => undefined);
            });
            addItem('Copy column (this page)', () => this.copyColumnValues(column));

            if (isCustom) {
                addDivider();

                addItem('Rename column', () => {
                    this.callWire('startRenamingColumn', info.customId, label).then(openManageModal).catch(() => undefined);
                }, { disabled: !canEdit });
                addItem('Duplicate column', () => {
                    this.callWire('duplicateCustomColumn', info.customId).catch(() => undefined);
                }, { disabled: !canEdit });
                addItem('Clear all values', () => {
                    if (window.confirm(`Clear every value in "${label}"? The column itself stays.`)) {
                        this.callWire('clearCustomColumnValues', info.customId).catch(() => undefined);
                    }
                }, { disabled: !canEdit });
                addItem('Delete column', () => {
                    if (window.confirm(`Delete "${label}" and all of its values? This cannot be undone.`)) {
                        this.callWire('deleteCustomColumn', info.customId).catch(() => undefined);
                    }
                }, { disabled: !canEdit, danger: true });
            }

            document.body.appendChild(menu);
            this._colMenu = menu;

            const rect = anchor.getBoundingClientRect();
            const menuRect = menu.getBoundingClientRect();
            const margin = 8;

            menu.style.top = `${Math.min(rect.bottom + 4, Math.max(margin, window.innerHeight - menuRect.height - margin))}px`;
            menu.style.left = `${Math.min(rect.left, Math.max(margin, window.innerWidth - menuRect.width - margin))}px`;

            const onPointerDown = (event) => {
                if (!menu.contains(event.target) && event.target !== anchor && !anchor.contains(event.target)) {
                    this.closeColumnMenu();
                }
            };
            const onKeyDown = (event) => {
                if (event.key === 'Escape') {
                    this.closeColumnMenu();
                    anchor.focus();
                }
            };
            const onScroll = () => this.closeColumnMenu();

            document.addEventListener('pointerdown', onPointerDown, true);
            document.addEventListener('keydown', onKeyDown, true);
            window.addEventListener('scroll', onScroll, true);

            this._colMenuCleanup = () => {
                document.removeEventListener('pointerdown', onPointerDown, true);
                document.removeEventListener('keydown', onKeyDown, true);
                window.removeEventListener('scroll', onScroll, true);
            };

            menu.querySelector('.grid-col-menu-item:not(:disabled)')?.focus();
        },

        /** Copy one column's current-page values as TSV. */
        copyColumnValues(column) {
            if (!this.table) {
                return;
            }

            const field = column.getField();
            const text = this.table.getRows('visible')
                .map((row) => {
                    const value = row.getData()?.[field];
                    return value === null || value === undefined ? '' : String(value).replace(/\t/g, ' ').replace(/\r?\n/g, ' ');
                })
                .join('\n');

            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(text).catch(() => undefined);
            }
        },

        applyRemoteSort(meta) {
            if (!this.table || !meta?.sortField) {
                return;
            }

            this.table.setSort([{ column: meta.sortField, dir: meta.sortDirection === 'desc' ? 'desc' : 'asc' }]);
        },

        handleCellEdited(cell) {
            const field = cell.getField();
            const column = this.columnDefs.find((definition) => definition.key === field);

            if (!column) {
                return;
            }

            const row = cell.getRow();
            const rowId = row.getData().id;
            const value = cell.getValue();

            // Track the change locally; nothing is sent to the server until the
            // user clicks "Save changes".
            this.pendingChanges.push({ rowId, field, value, custom: !!column.custom, customId: column.customId ?? null });
            this.hasChanges = true;
            this.status = 'Unsaved changes';
        },

        addRow() {
            if (!this.table) {
                return;
            }

            // A temporary negative id marks the row as "new" until it is saved.
            const tempId = -Date.now();
            this.newRowId = tempId;
            const blank = { id: tempId };
            this.columnDefs.forEach((column) => {
                if (column.key !== 'id') {
                    blank[column.key] = null;
                }
            });

            this.table.addRow(blank, true);
            this.hasChanges = true;
            this.status = 'New item added — fill in fields, then Save changes';
        },

        handleRowAdded(row) {
            // Intentionally left minimal: the Edit module isn't loaded in this
            // build, so cell.focus() is unavailable. The user can click into a
            // cell to start editing.
        },

        async saveChanges() {
            if (!this.table || !this.hasChanges) {
                return;
            }

            this.status = 'Saving...';

            try {
                // Build a single batch of changes. New rows (negative ids) become
                // "create" entries; edited cells become "update"/"custom" entries.
                const newRows = this.table.getRows().filter((row) => row.getData().id < 0);
                const newRowIds = new Set(newRows.map((row) => row.getData().id));

                const changes = [];

                for (const row of newRows) {
                    const data = row.getData();
                    const payload = { ...data };
                    delete payload.id;
                    changes.push({ type: 'create', tempId: String(data.id), data: payload });
                }

                for (const change of this.pendingChanges) {
                    if (newRowIds.has(change.rowId)) {
                        // The edit belongs to a new row; it's already included in
                        // the create payload, so skip the separate update.
                        continue;
                    }

                    changes.push(
                        change.custom
                            ? { type: 'custom', id: change.rowId, customId: change.customId, value: change.value }
                            : { type: 'update', id: change.rowId, field: change.field, value: change.value },
                    );
                }

                // Send everything in ONE server call to avoid Livewire 3.8.5's
                // request-batching bug. Pass the changes as a JSON string so
                // Alpine's reactive proxies (which carry a __v_raw symbol) can't
                // leak into the Livewire payload.
                await this.callWire('saveGridChanges', JSON.stringify(changes));

                // After a successful save, refresh the grid from the server so
                // the new rows get their real ids and deleted rows are gone.
                this.pendingChanges = [];
                this.hasChanges = false;
                this.newRowId = null;
                this.status = 'Saved';
                this.mountOrRefresh();
            } catch (error) {
                this.status = 'Save failed';
                console.error('Save failed', error);
            }
        },

        saveLayout() {
            if (!this.table) {
                return;
            }

            const layout = this.table.getColumns().map((column, index) => ({
                key: column.getField(),
                position: index,
                width: column.getWidth(),
                hidden: !column.isVisible(),
                frozen: !!column.getDefinition().frozen,
            }));

            this.callWire('saveColumnLayout', layout);
        },
    }));

    // Import wizard file uploader: streams the workbook to the server in
    // small PUT chunks, so PHP's multipart upload limits (upload_max_filesize
    // / post_max_size) never apply, on any machine with stock php.ini.
    window.Alpine.data('importUploader', ({ url }) => ({
        uploading: false,
        progress: 0,
        uploadError: '',

        async uploadImportFile(file) {
            this.uploadError = '';

            if (!file) {
                return;
            }

            const extension = (file.name.split('.').pop() || '').toLowerCase();
            if (!['xlsx', 'xls', 'csv', 'txt'].includes(extension)) {
                this.uploadError = 'Unsupported file type (.' + extension + '). Use .xlsx, .xls or .csv.';
                return;
            }

            this.uploading = true;
            this.progress = 0;

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const bytes = new Uint8Array(16);
                crypto.getRandomValues(bytes);
                const uploadId = Array.from(bytes).map((byte) => byte.toString(16).padStart(2, '0')).join('');
                const chunkSize = 2 * 1024 * 1024;
                let offset = 0;

                while (offset < file.size) {
                    const response = await fetch(url, {
                        method: 'PUT',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-File-Name': encodeURIComponent(file.name),
                            'X-Upload-Id': uploadId,
                            'X-File-Offset': String(offset),
                            'Content-Type': 'application/octet-stream',
                        },
                        body: file.slice(offset, offset + chunkSize),
                    });

                    if (!response.ok) {
                        let detail = await response.text();
                        // PUT handler returns JSON {message: "..."} — surface that, not raw JSON.
                        try {
                            const parsed = JSON.parse(detail);
                            if (parsed?.message) detail = parsed.message;
                        } catch {
                            // ModSecurity / WAF returns an HTML page for 403 — slice would
                            // show "<!DOCTYPE..." which is useless in the UI.
                            if (detail.trimStart().startsWith('<')) {
                                detail = `Upload blocked by web firewall (HTTP ${response.status}). Ask the host to allow PUT ${url} or disable ModSecurity for that path.`;
                            }
                        }
                        detail = detail.slice(0, 260);
                        throw new Error(detail || 'Upload failed at byte ' + offset + '.');
                    }

                    offset = Math.min(offset + chunkSize, file.size);
                    this.progress = Math.round((offset / file.size) * 100);
                }

                try {
                    await this.$wire.analyzeStreamedImport(uploadId, file.name);
                } catch (wireError) {
                    // Livewire 3 rejects the promise with a js error that often
                    // contains only "500: Internal Server Error" — try to pull a
                    // more useful message from the server's validation errors or
                    // console. The server now logs breadcrumbs and surfaces the
                    // failure via addError('importFile', ...), but if the 500 was
                    // not caught server-side we show a diagnostic hint instead of
                    // a blank "upload failed".
                    const raw = wireError?.message || String(wireError || '');
                    const has500 = raw.includes('500') || raw.toLowerCase().includes('internal server error');
                    if (has500) {
                        this.uploadError = 'The server failed to analyze the workbook (HTTP 500). Check storage/logs/laravel.log for analyzeStreamedImport.enter — breadcrumbs were added in the deployed code to make this diagnosable. Common causes: memory_limit too low (set to 512M in MultiPHP INI Editor), or the assembled file is missing (disk full / ModSecurity chunk blocked).';
                    } else {
                        this.uploadError = raw || 'The workbook analysis failed.';
                    }
                    // Also surface any Livewire validation errors that the
                    // server set via addError('importFile', ...) — they appear in
                    // $wire.__livewire.errors in some Livewire versions.
                    const livewireErrors = this.$wire?.__livewire?.errors || this.$wire?.$errors;
                    const fileError = livewireErrors?.importFile?.[0] || livewireErrors?.['importFile']?.[0];
                    if (fileError) {
                        this.uploadError = fileError;
                    }
                }
            } catch (error) {
                // Network/PUT-level failure (fetch threw before reaching Livewire).
                let msg = error?.message || 'The upload failed.';
                // Prefer the JSON message from the PUT handler (e.g. ModSecurity block).
                if (error?.cause?.message) msg = error.cause.message;
                this.uploadError = msg;
            } finally {
                this.uploading = false;
            }
        },
    }));
});
