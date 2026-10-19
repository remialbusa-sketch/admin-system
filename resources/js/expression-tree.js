// Drag-drop & Guided expression tree editor (Alpine): builds formulas
// either via one-click guided templates (Percentage, Ratio, Sum, Difference,
// Single Metric, Round) or by connecting visual blocks on an interactive canvas.
//
// The server (ExpressionEngine::toTree) converts an existing expression into
// positioned nodes when it is canvas-representable, or signals "advanced"
// when it is not — in which case the original formula is shown read-only
// with a "Start over" escape hatch (never silently rewritten). Editing
// serializes the graph back into a standard math string with explicit
// parentheses, pushed into the Livewire settings property exactly like a
// typed formula — the stored props/DB structure is unchanged.

const NODE_W = 160;
const NODE_H = 60;

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

        // Two friendly modes: 'guided' (default zero-friction) vs 'canvas' (power block graph)
        viewMode: 'guided',
        activeTemplate: 'single', // 'single' | 'percentage' | 'ratio' | 'sum' | 'difference' | 'round' | 'custom'
        guided: {
            metricA: '',
            metricB: '',
            digits: 1,
        },
        metricSearch: '',

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

            const keys = Object.keys(this.metricLabels);
            this.guided.metricA = keys[0] ?? '';
            this.guided.metricB = keys[1] ?? (keys[0] ?? '');

            if (this.nodes.length > 0) {
                this.detectTemplate();
                // If it's a custom multi-step graph, switch to canvas so user sees it;
                // otherwise keep guided mode for instant readability and editing.
                if (this.activeTemplate === 'custom') {
                    this.viewMode = 'canvas';
                }
            } else {
                this.activeTemplate = 'single';
                this.viewMode = 'guided';
            }

            // Apply reads graphs straight from this in-memory registry at
            // click time — they travel inside the Apply request itself, so
            // no separate sync request can be lost or arrive late. Clear
            // any stale entry from a previous editing session of this field.
            window.__treeGraphs = window.__treeGraphs || {};
            window.__treeGraphs[this.graphKey] = null;
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

        // Available metrics formatted with live values and labels
        get metricList() {
            const list = [];
            for (const [key, label] of Object.entries(this.metricLabels)) {
                const val = this.metricValues[key];
                const formatted = typeof val === 'number'
                    ? val.toLocaleString(undefined, { maximumFractionDigits: 2 })
                    : (val !== undefined && val !== null ? String(val) : '—');
                list.push({ key, label, val, formatted });
            }

            if (!this.metricSearch) {
                return list;
            }

            const q = this.metricSearch.toLowerCase().trim();
            return list.filter((m) => m.label.toLowerCase().includes(q) || m.key.toLowerCase().includes(q));
        },

        // ---- Template & Guided Mode -------------------------------------

        detectTemplate() {
            if (this.nodes.length === 0) {
                this.activeTemplate = 'single';
                return;
            }

            const root = this.byId(this.rootId);
            if (!root) {
                this.activeTemplate = 'custom';
                return;
            }

            if (this.nodes.length === 1 && root.kind === 'metric') {
                this.activeTemplate = 'single';
                this.guided.metricA = root.value;
                return;
            }

            if (this.nodes.length === 3 && root.kind === 'fn' && root.value === 'pct') {
                const nodeA = this.byId(root.inputs[0]);
                const nodeB = this.byId(root.inputs[1]);
                if (nodeA && nodeB && nodeA.kind === 'metric' && nodeB.kind === 'metric') {
                    this.activeTemplate = 'percentage';
                    this.guided.metricA = nodeA.value;
                    this.guided.metricB = nodeB.value;
                    return;
                }
            }

            if (this.nodes.length === 3 && root.kind === 'op') {
                const nodeA = this.byId(root.inputs[0]);
                const nodeB = this.byId(root.inputs[1]);
                if (nodeA && nodeB && nodeA.kind === 'metric' && nodeB.kind === 'metric') {
                    if (root.value === '/') this.activeTemplate = 'ratio';
                    else if (root.value === '+') this.activeTemplate = 'sum';
                    else if (root.value === '-') this.activeTemplate = 'difference';
                    else this.activeTemplate = 'custom';

                    this.guided.metricA = nodeA.value;
                    this.guided.metricB = nodeB.value;
                    return;
                }
            }

            if (this.nodes.length === 3 && root.kind === 'fn' && root.value === 'round') {
                const nodeA = this.byId(root.inputs[0]);
                const nodeB = this.byId(root.inputs[1]);
                if (nodeA && nodeA.kind === 'metric' && nodeB && nodeB.kind === 'number') {
                    this.activeTemplate = 'round';
                    this.guided.metricA = nodeA.value;
                    this.guided.digits = parseInt(nodeB.value, 10) || 1;
                    return;
                }
            }

            this.activeTemplate = 'custom';
        },

        applyTemplate(type) {
            this.activeTemplate = type;
            const keys = Object.keys(this.metricLabels);
            const metA = this.guided.metricA || keys[0] || 'installed';
            const metB = this.guided.metricB || keys[1] || keys[0] || 'active';
            const digits = this.guided.digits ?? 1;

            if (type === 'single') {
                this.nodes = [
                    { id: 'm1', kind: 'metric', value: metA, inputs: [], x: 60, y: 70 },
                ];
                this.selected = 'm1';
            } else if (type === 'percentage') {
                this.nodes = [
                    { id: 'm1', kind: 'metric', value: metA, inputs: [], x: 40, y: 40 },
                    { id: 'm2', kind: 'metric', value: metB, inputs: [], x: 40, y: 130 },
                    { id: 'fn1', kind: 'fn', value: 'pct', inputs: ['m1', 'm2'], x: 240, y: 85 },
                ];
                this.selected = 'fn1';
            } else if (type === 'ratio') {
                this.nodes = [
                    { id: 'm1', kind: 'metric', value: metA, inputs: [], x: 40, y: 40 },
                    { id: 'm2', kind: 'metric', value: metB, inputs: [], x: 40, y: 130 },
                    { id: 'op1', kind: 'op', value: '/', inputs: ['m1', 'm2'], x: 240, y: 85 },
                ];
                this.selected = 'op1';
            } else if (type === 'sum') {
                this.nodes = [
                    { id: 'm1', kind: 'metric', value: metA, inputs: [], x: 40, y: 40 },
                    { id: 'm2', kind: 'metric', value: metB, inputs: [], x: 40, y: 130 },
                    { id: 'op1', kind: 'op', value: '+', inputs: ['m1', 'm2'], x: 240, y: 85 },
                ];
                this.selected = 'op1';
            } else if (type === 'difference') {
                this.nodes = [
                    { id: 'm1', kind: 'metric', value: metA, inputs: [], x: 40, y: 40 },
                    { id: 'm2', kind: 'metric', value: metB, inputs: [], x: 40, y: 130 },
                    { id: 'op1', kind: 'op', value: '-', inputs: ['m1', 'm2'], x: 240, y: 85 },
                ];
                this.selected = 'op1';
            } else if (type === 'round') {
                this.nodes = [
                    { id: 'm1', kind: 'metric', value: metA, inputs: [], x: 40, y: 40 },
                    { id: 'num1', kind: 'number', value: String(digits), inputs: [], x: 40, y: 130 },
                    { id: 'fn1', kind: 'fn', value: 'round', inputs: ['m1', 'num1'], x: 240, y: 85 },
                ];
                this.selected = 'fn1';
            }

            this.commit();
        },

        onGuidedChange() {
            if (this.activeTemplate === 'custom') return;
            this.applyTemplate(this.activeTemplate);
        },

        // Automatically position blocks cleanly left-to-right
        autoArrange() {
            if (this.nodes.length === 0) return;

            const getDepth = (id, visited = new Set()) => {
                if (!id || visited.has(id)) return 0;
                visited.add(id);
                const node = this.byId(id);
                if (!node || !node.inputs || node.inputs.length === 0) return 0;
                let maxChild = 0;
                for (const input of node.inputs) {
                    if (input) {
                        maxChild = Math.max(maxChild, 1 + getDepth(input, new Set(visited)));
                    }
                }
                return maxChild;
            };

            const depths = new Map();
            for (const node of this.nodes) {
                depths.set(node.id, getDepth(node.id));
            }

            const byCol = new Map();
            for (const node of this.nodes) {
                const col = depths.get(node.id) || 0;
                if (!byCol.has(col)) byCol.set(col, []);
                byCol.get(col).push(node);
            }

            const sortedCols = Array.from(byCol.keys()).sort((a, b) => a - b);
            sortedCols.forEach((colIndex, xRank) => {
                const colNodes = byCol.get(colIndex) || [];
                colNodes.forEach((node, yRank) => {
                    node.x = 24 + xRank * 210;
                    node.y = 24 + yRank * 84;
                });
            });

            this.commit();
        },

        getPortLabel(node, index) {
            if (!node) return 'Input';
            if (node.kind === 'op') {
                if (node.value === '/') return index === 0 ? 'Numerator (Top)' : 'Denominator (Bottom)';
                if (node.value === '-') return index === 0 ? 'Left (Base)' : 'Right (Subtract)';
                if (node.value === '+') return index === 0 ? 'Left value' : 'Right value';
                if (node.value === '*') return index === 0 ? 'Factor 1' : 'Factor 2';
                if (node.value === '%') return index === 0 ? 'Value' : 'Modulo';
                if (node.value === '^') return index === 0 ? 'Base' : 'Exponent';
            }
            if (node.kind === 'fn') {
                if (node.value === 'pct') return index === 0 ? 'Part (Numerator)' : 'Total (Denominator)';
                if (node.value === 'round') return index === 0 ? 'Number to round' : 'Decimal places';
                if (node.value === 'min') return `Value #${index + 1}`;
                if (node.value === 'max') return `Value #${index + 1}`;
                if (node.value === 'abs') return 'Value';
                if (node.value === 'if') return index === 0 ? 'Condition' : (index === 1 ? 'If True' : 'If False');
                if (node.value === 'coalesce') return index === 0 ? 'Primary' : 'Fallback';
                if (node.value === 'sum') return `Value #${index + 1}`;
            }
            return `Input #${index + 1}`;
        },

        // The canvas as a JSON string for the wire:model hidden inputs
        graphJson() {
            if (this.advanced) {
                return null;
            }

            return JSON.stringify({
                nodes: JSON.parse(JSON.stringify(this.nodes)),
                root: this.rootId,
            });
        },

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
            this.activeTemplate = 'custom';
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

            this.detectTemplate();
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
            if (!canvas) return;

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
            this.drag = null;
        },

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
                if (node.inputs[index] !== null) {
                    node.inputs[index] = null;
                    this.detectTemplate();
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
            this.detectTemplate();
            this.commit();
        },

        wouldCycle(fromId, targetId) {
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
            let width = 440;
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
            this.activeTemplate = 'single';
            this.viewMode = 'guided';
            this.$wire.set(this.fieldKey, '');
            this.$wire.commitTreeGraph(this.graphKey, null);
        },

        commit() {
            if (this.advanced) {
                return;
            }

            window.__treeGraphs = window.__treeGraphs || {};
            window.__treeGraphs[this.graphKey] = JSON.parse(JSON.stringify({
                nodes: this.nodes,
                root: this.rootId,
            }));

            this.$wire.commitTreeGraph(this.graphKey, JSON.stringify(window.__treeGraphs[this.graphKey]));

            const expression = this.expression;

            if (expression !== null) {
                this.$wire.set(this.fieldKey, expression);
            }
        },

        opGlyph(value) {
            return OP_GLYPHS[value] ?? value;
        },
    }));
});
