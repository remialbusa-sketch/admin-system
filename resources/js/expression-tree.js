// Drag-drop expression tree editor (Alpine): builds formulas by placing
// blocks (metrics, numbers, operators, functions) on a canvas and connecting
// them left-to-right into one result. Replaces raw formula text areas for
// non-technical users.
//
// The server (ExpressionEngine::toTree) converts an existing expression into
// positioned nodes when it is canvas-representable, or signals "advanced"
// when it is not — in which case the original formula is shown read-only
// with a "Start over" escape hatch (never silently rewritten). Editing
// serializes the graph back into a standard math string with explicit
// parentheses, pushed into the Livewire settings property exactly like a
// typed formula — the stored props/DB structure is unchanged.

const NODE_W = 150;
const NODE_H = 56;

const OP_GLYPHS = { '+': '+', '-': '−', '*': '×', '/': '÷', '%': '%', '^': 'xʸ' };

const FN_SLOTS = { round: 2, min: 2, max: 2, abs: 1, if: 3, coalesce: 2, sum: 2, pct: 2 };

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

document.addEventListener('alpine:init', () => {
    window.Alpine.data('expressionTree', (config) => ({
        fieldKey: config.fieldKey,
        graphKey: config.graphKey ?? (config.fieldKey + '_tree'),
        metricLabels: config.metricLabels ?? {},
        metricValues: config.metricValues ?? {},
        // Server says null → the formula uses advanced syntax.
        advanced: config.tree === null || config.tree === undefined,
        nodes: [],
        selected: null,
        linking: null, // { from: nodeId } awaiting an input-slot click
        linkMouse: null,
        drag: null, // { id, offX, offY }

        ops: ['+', '-', '*', '/', '%', '^'],
        fns: ['round', 'min', 'max', 'abs', 'if', 'coalesce', 'sum', 'pct'],

        init() {
            // The persisted graph (exact blocks, positions, connections —
            // complete or mid-build) wins over a rebuild from the string,
            // so the canvas always reopens exactly as the user left it.
            const graph = Array.isArray(config.graph) && Array.isArray(config.graph.nodes)
                ? config.graph
                : null;
            const tree = Array.isArray(config.tree) && Array.isArray(config.tree.nodes)
                ? config.tree
                : null;

            this.advanced = graph === null && (config.tree === null || config.tree === undefined);

            const source = graph ?? tree;

            this.nodes = source ? source.nodes.map((node) => ({ ...node })) : [];

            // Apply reads graphs straight from this in-memory registry at
            // click time — they travel inside the Apply request itself, so
            // no separate sync request can be lost or arrive late. Clear
            // any stale entry from a previous editing session of this field.
            window.__treeGraphs = window.__treeGraphs || {};
            window.__treeGraphs[this.graphKey] = null;

            // No commit on init: the Livewire fields already hold the
            // stored state; the canvas writes only when the user edits.
        },

        byId(id) {
            return this.nodes.find((node) => node.id === id) ?? null;
        },

        get selectedNode() {
            return this.nodes.find((node) => node.id === this.selected) ?? null;
        },

        // The result block: the single node nothing else consumes.
        get rootId() {
            const referenced = new Set(this.nodes.flatMap((node) => node.inputs.filter(Boolean)));
            const candidates = this.nodes.filter((node) => !referenced.has(node.id));

            return candidates.length === 1 ? candidates[0].id : null;
        },

        // Serialize → math string; null when the graph is incomplete.
        serialize(id, depth = 0) {
            if (id === null || depth > 64) {
                return null;
            }

            const node = this.byId(id);

            if (!node) {
                return null;
            }

            if (node.kind === 'metric') {
                return /^[a-zA-Z_]\w*$/.test(node.value) ? node.value : null;
            }

            if (node.kind === 'number') {
                const parsed = parseFloat(node.value);

                return Number.isNaN(parsed) ? null : String(parsed);
            }

            const parts = node.inputs.map((input) => this.serialize(input, depth + 1));

            if (parts.some((part) => part === null)) {
                return null;
            }

            if (node.kind === 'op') {
                return `(${parts[0]} ${node.value} ${parts[1]})`;
            }

            if (node.kind === 'fn') {
                return `${node.value}(${parts.join(', ')})`;
            }

            return null;
        },

        get expression() {
            const root = this.rootId;

            return root === null ? null : this.serialize(root);
        },

        get valid() {
            return this.expression !== null;
        },

        // Live preview over the scope's current metric values.
        evaluate(id, depth = 0) {
            if (id === null || depth > 64) {
                return null;
            }

            const node = this.byId(id);

            if (!node) {
                return null;
            }

            if (node.kind === 'metric') {
                const value = this.metricValues[node.value];

                return typeof value === 'number' ? value : null;
            }

            if (node.kind === 'number') {
                const parsed = parseFloat(node.value);

                return Number.isNaN(parsed) ? null : parsed;
            }

            const args = node.inputs.map((input) => this.evaluate(input, depth + 1));

            if (node.kind === 'op') {
                const [left, right] = args;

                if (left === null || right === null) {
                    return null;
                }

                switch (node.value) {
                    case '+': return left + right;
                    case '-': return left - right;
                    case '*': return left * right;
                    case '/': return right === 0 ? null : left / right;
                    case '%': return right === 0 ? null : left % right;
                    case '^': return left ** right;
                }

                return null;
            }

            if (node.value === 'coalesce') {
                return args.find((arg) => arg !== null) ?? null;
            }

            if (node.value === 'if') {
                const [condition, then, otherwise] = args;

                if (condition === null) {
                    return null;
                }

                return condition ? then : otherwise;
            }

            if (args.some((arg) => arg === null)) {
                return null;
            }

            switch (node.value) {
                case 'round': {
                    const digits = args.length > 1 ? args[1] : 0;
                    const factor = 10 ** digits;

                    return Math.round(args[0] * factor) / factor;
                }
                case 'min': return Math.min(...args);
                case 'max': return Math.max(...args);
                case 'abs': return Math.abs(args[0]);
                case 'sum': return args.reduce((sum, arg) => sum + arg, 0);
                case 'pct': return args[1] === 0 ? null : Math.round((args[0] / args[1]) * 1000) / 10;
            }

            return null;
        },

        get preview() {
            if (!this.valid) {
                return null;
            }

            const value = this.evaluate(this.rootId);

            if (value === null) {
                return null;
            }

            return Math.round(value * 100) / 100;
        },

        get previewText() {
            const value = this.preview;

            if (value === null) {
                return '= no data in this scope yet';
            }

            return `= ${value.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
        },

        get expressionValid() {
            return this.expression !== null;
        },

        // The canvas as a JSON string for the wire:model hidden inputs —
        // the same deferred channel every other settings field uses.
        graphJson() {
            if (this.advanced) {
                return null;
            }

            return JSON.stringify({
                nodes: JSON.parse(JSON.stringify(this.nodes)),
                root: this.rootId,
            });
        },

        // Keep a wire:model hidden input in step with the canvas by
        // writing the value and firing the input event wire:model listens
        // to. A null value means "no change" — the last good value stays.
        syncHidden(el, value) {
            if (this.advanced || value === null) {
                return;
            }

            const encoded = String(value);

            if (el.value !== encoded) {
                el.value = encoded;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },

        // ---- building -------------------------------------------------

        freeSpot() {
            for (let column = 0; column < 8; column++) {
                for (let row = 0; row < 6; row++) {
                    const x = 14 + column * 176;
                    const y = 14 + row * 78;

                    if (! this.nodes.some((node) => Math.abs(node.x - x) < 40 && Math.abs(node.y - y) < 40)) {
                        return { x, y };
                    }
                }
            }

            return { x: 14 + this.nodes.length * 12, y: 14 + this.nodes.length * 12 };
        },

        addBlock(kind, value = null) {
            const spot = this.freeSpot();
            const id = `u${Date.now().toString(36)}${this.nodes.length}`;

            let initialValue = value;
            let inputs = [];

            if (kind === 'metric') {
                initialValue = value ?? (Object.keys(this.metricLabels)[0] ?? '');
            } else if (kind === 'number') {
                initialValue = value ?? '100';
            } else if (kind === 'op') {
                initialValue = value ?? '+';
                inputs = [null, null];
            } else if (kind === 'fn') {
                initialValue = value ?? 'pct';
                inputs = Array(FN_SLOTS[initialValue] ?? 2).fill(null);
            }

            this.nodes.push({ id, kind, value: initialValue, inputs, x: spot.x, y: spot.y });
            this.selected = id;
            this.commit();
        },

        onFnChange() {
            const node = this.selectedNode;

            if (node) {
                node.inputs = Array(FN_SLOTS[node.value] ?? 2).fill(null);
            }

            this.commit();
        },

        removeNode(id) {
            this.nodes = this.nodes
                .filter((node) => node.id !== id)
                .map((node) => ({
                    ...node,
                    inputs: node.inputs.map((input) => (input === id ? null : input)),
                }));

            if (this.selected === id) {
                this.selected = null;
            }

            if (this.linking?.from === id) {
                this.linking = null;
                this.linkMouse = null;
            }

            this.commit();
        },

        // ---- pointer interaction ---------------------------------------

        startDrag(event, node) {
            this.selected = node.id;

            if (this.linking) {
                return; // a pending link is only completed on input slots
            }

            const canvas = this.$refs.canvas;
            const rect = canvas.getBoundingClientRect();
            const contentX = event.clientX - rect.left + canvas.scrollLeft;
            const contentY = event.clientY - rect.top + canvas.scrollTop;

            this.drag = { id: node.id, offX: contentX - node.x, offY: contentY - node.y };
        },

        onMove(event) {
            const canvas = this.$refs.canvas;

            if (this.linking) {
                const rect = canvas.getBoundingClientRect();

                this.linkMouse = {
                    x: event.clientX - rect.left + canvas.scrollLeft,
                    y: event.clientY - rect.top + canvas.scrollTop,
                };
            }

            if (this.drag) {
                const node = this.byId(this.drag.id);

                if (node) {
                    const rect = canvas.getBoundingClientRect();

                    node.x = clamp(
                        event.clientX - rect.left + canvas.scrollLeft - this.drag.offX,
                        0,
                        4000,
                    );
                    node.y = clamp(
                        event.clientY - rect.top + canvas.scrollTop - this.drag.offY,
                        0,
                        4000,
                    );
                }
            }
        },

        onUp() {
            // End a node drag. A pending connection is deliberately NOT
            // cancelled here: connecting is click-based (click a right dot,
            // then a left dot), so a mouse release between the two clicks
            // must keep the link alive. Esc or re-clicking the same dot
            // cancels it.
            this.drag = null;
        },

        // Start (or toggle off) a pending connection from a block's output.
        toggleLink(node) {
            if (this.linking?.from === node.id) {
                this.linking = null;
                this.linkMouse = null;

                return;
            }

            this.linking = { from: node.id };
        },

        inputPos(node, index) {
            const slots = node.inputs.length;

            return {
                x: node.x,
                y: node.y + NODE_H / 2 + (index - (slots - 1) / 2) * 24,
            };
        },

        outputPos(node) {
            return { x: node.x + NODE_W, y: node.y + NODE_H / 2 };
        },

        handleInput(node, index) {
            if (! this.linking) {
                // No pending link: clicking a filled slot unlinks it.
                if (node.inputs[index] !== null) {
                    node.inputs[index] = null;
                    this.commit();
                }

                return;
            }

            const from = this.linking.from;

            if (from === node.id || this.wouldCycle(from, node.id)) {
                this.linking = null;
                this.linkMouse = null;

                return;
            }

            node.inputs[index] = from;
            this.linking = null;
            this.linkMouse = null;
            this.commit();
        },

        wouldCycle(fromId, targetId) {
            // Connecting from → target creates a cycle when target already
            // feeds into from somewhere downstream.
            const stack = [fromId];
            const seen = new Set();

            while (stack.length > 0) {
                const id = stack.pop();

                if (id === targetId) {
                    return true;
                }

                if (seen.has(id)) {
                    continue;
                }

                seen.add(id);

                const node = this.byId(id);

                if (node) {
                    stack.push(...node.inputs.filter(Boolean));
                }
            }

            return false;
        },

        // ---- canvas geometry --------------------------------------------

        get links() {
            const out = [];

            for (const node of this.nodes) {
                node.inputs.forEach((input, index) => {
                    const source = input ? this.byId(input) : null;

                    if (!source) {
                        return;
                    }

                    const from = this.outputPos(source);
                    const to = this.inputPos(node, index);

                    out.push({
                        key: `${node.id}-${index}`,
                        d: `M ${from.x} ${from.y} C ${from.x + 52} ${from.y}, ${to.x - 52} ${to.y}, ${to.x} ${to.y}`,
                    });
                });
            }

            return out;
        },

        // All connection curves joined into ONE path string. Alpine's
        // x-for cannot render inside <svg> (the <template> element is not
        // an HTMLTemplateElement there), so the SVG holds two static
        // <path> elements and we rebuild their `d` on every change.
        get linksD() {
            return this.links.map((link) => link.d).join(' ');
        },

        get pendingLink() {
            if (! this.linking || ! this.linkMouse) {
                return null;
            }

            const source = this.byId(this.linking.from);

            if (! source) {
                return null;
            }

            const from = this.outputPos(source);
            const to = this.linkMouse;

            return `M ${from.x} ${from.y} C ${from.x + 52} ${from.y}, ${to.x - 52} ${to.y}, ${to.x} ${to.y}`;
        },

        get canvasSize() {
            let width = 420;
            let height = 240;

            for (const node of this.nodes) {
                width = Math.max(width, node.x + NODE_W + 40);
                height = Math.max(height, node.y + NODE_H + 40);
            }

            return { width, height };
        },

        // ---- lifecycle ---------------------------------------------------

        startOver() {
            this.advanced = false;
            this.nodes = [];
            this.selected = null;
            // Explicit clear — the only case where an "empty" graph may
            // touch the fields, because the user asked for it.
            this.$wire.set(this.fieldKey, '');
            this.$wire.commitTreeGraph(this.graphKey, null);
        },

        commit() {
            if (this.advanced) {
                return; // never clobber an advanced formula from the canvas
            }

            // THE source of truth for Apply: the in-memory registry holds
            // the exact canvas — blocks, positions, connections, valid or
            // mid-build — and Apply reads it synchronously at click time.
            window.__treeGraphs = window.__treeGraphs || {};
            window.__treeGraphs[this.graphKey] = JSON.parse(JSON.stringify({
                nodes: this.nodes,
                root: this.rootId,
            }));

            // Best-effort live channels so the server also sees the graph
            // between clicks (Apply itself never depends on these). Sent
            // as an explicit action call with a JSON string param.
            this.$wire.commitTreeGraph(this.graphKey, JSON.stringify(window.__treeGraphs[this.graphKey]));

            const expression = this.expression;

            // The expression string only updates when the graph is
            // complete; mid-build, the last valid formula stays in place.
            if (expression !== null) {
                this.$wire.set(this.fieldKey, expression);
            }
        },

        opGlyph(value) {
            return OP_GLYPHS[value] ?? value;
        },
    }));
});
