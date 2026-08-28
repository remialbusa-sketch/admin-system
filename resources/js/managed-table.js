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
        wire: null,
        columnDefs: [],
        pendingChanges: [],
        pendingDeletes: [],
        hasChanges: false,
        newRowId: null,
        densitySelect: null,
        _suppressRefresh: false,

        init(wire) {
            this.wire = wire;
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

        // Call a Livewire component method. Uses Livewire.first() to get a clean
        // (non-reactive) reference to the component, avoiding the __v_raw proxy
        // bug in Livewire 3.8.5 when calling methods from Alpine's reactive scope.
        callWire(method, ...args) {
            const component = Livewire.first();
            if (component && typeof component[method] === 'function') {
                return component[method](...args);
            }
            return this.wire.call(method, ...args);
        },

        destroy() {
            // Livewire.hook has no unregister API; mountOrRefresh() is idempotent
            // and readPayload() returns null once this component is detached, so
            // the callback is inert after this component is gone.
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

            this.table.replaceData(payload.rows);
            this.applyRemoteSort(payload.meta);
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
                // Row multi-select (used for bulk actions) with a checkbox column.
                rowSelection: true,
                selectableRows: true,
                selectableRowsHeader: true,
                selectableRowsRangeMode: 'click',
                // Let Tabulator use its default row-selection checkbox formatter.
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

            this.table.on('tableBuilt', () => this.applyRemoteSort(payload.meta));
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

        // --- Multi-select / bulk actions ---------------------------------
        updateSelectionUI() {
            const btn = this.$root?.querySelector?.('[data-bulk-delete]');
            if (!btn) {
                return;
            }
            const n = this.selectedCount();
            btn.classList.toggle('admin-bulk-visible', n > 0);
            btn.querySelector('[data-bulk-delete-count]').textContent = n;
        },
        selectedCount() {
            return this.table ? this.table.getSelectedRows().length : 0;
        },
        deleteSelected() {
            const rows = this.table?.getSelectedRows() ?? [];
            const ids = rows.map((r) => r.getData().id).filter((id) => id !== undefined && id !== null);
            if (ids.length === 0) {
                return;
            }
            const n = ids.length;
            if (!window.confirm(`Delete ${n} selected record${n === 1 ? '' : 's'}? This cannot be undone.`)) {
                return;
            }
            this.callWire('deleteSelected', ids).then(() => {
                this.table?.deselectRow();
                this.status = 'Deleted';
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
            return cell.getColumn()?.getField() === '_delete' || cell.getValue() === '' || !!this.columnDefs?.find((c) => c.key === cell.getColumn()?.getField())?.type &&
                ['select', 'status', 'dropdown', 'checkbox'].includes(this.columnDefs.find((c) => c.key === cell.getColumn()?.getField())?.type);
        },

        buildColumnDefs(columns) {
            const defs = [{
                title: '▢',
                field: '_select',
                formatter: 'rowSelection',
                titleFormatter: 'rowSelection',
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
                    headerClick: (_event, col) => this.callWire('sortBy', col.getField()),
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

            // Append a delete action column for editors.
            if (this.editable) {
                defs.push({
                    title: '',
                    field: '_delete',
                    width: 60,
                    minWidth: 60,
                    resizable: false,
                    headerSort: false,
                    frozen: true,
                    formatter: (cell) => {
                        const row = cell.getRow();
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'grid-delete-btn';
                        btn.title = 'Delete record';
                        btn.setAttribute('aria-label', 'Delete record');
                        btn.innerHTML = '✕';
                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (window.confirm('Delete this record? This cannot be undone.')) {
                                this.deleteRow(row);
                            }
                        });
                        return btn;
                    },
                });
            }

            return defs;
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

        deleteRow(row) {
            if (!this.table || !row) {
                return;
            }

            const rowId = row.getData().id;

            // If it's a brand-new (unsaved) row, just remove it from the grid.
            if (rowId < 0) {
                this.table.deleteRow(row);
                this.hasChanges = this.pendingChanges.length > 0 || this.table.getRows().some((r) => r.getData().id < 0);
                if (!this.hasChanges) {
                    this.status = 'Ready';
                }
                return;
            }

            // For an existing row, mark it for deletion and remove it from the
            // grid. It will be deleted server-side when "Save changes" is clicked.
            this.pendingDeletes.push(rowId);
            this.pendingChanges = this.pendingChanges.filter((change) => change.rowId !== rowId);
            this.table.deleteRow(row);
            this.hasChanges = true;
            this.status = 'Unsaved changes';
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

                // Add pending deletes.
                for (const id of this.pendingDeletes) {
                    changes.push({ type: 'delete', id });
                }

                // Send everything in ONE server call to avoid Livewire 3.8.5's
                // request-batching bug. Pass the changes as a JSON string so
                // Alpine's reactive proxies (which carry a __v_raw symbol) can't
                // leak into the Livewire payload.
                await this.callWire('saveGridChanges', JSON.stringify(changes));

                // After a successful save, refresh the grid from the server so
                // the new rows get their real ids and deleted rows are gone.
                this.pendingChanges = [];
                this.pendingDeletes = [];
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
});
