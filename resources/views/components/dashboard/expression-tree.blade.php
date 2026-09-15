@props([
    'fieldKey',
    'expression' => '',
    'tree' => null,
    'graph' => null,
    'graphKey' => '',
    'metricLabels' => [],
    'metricValues' => [],
    'widgetId' => '',
    'openCount' => 0,
])

{{-- Drag-drop expression tree: place blocks, connect them left-to-right,
    watch the live preview, and the graph serializes into a standard math
    string. The raw canvas (blocks, positions, connections) persists too —
    reopening shows exactly what was built, ready to edit, change or
    delete. See resources/js/expression-tree.js for the state machine.
    openCount in the wire:key forces a FRESH Alpine component on every
    settings open, and wire:ignore makes the canvas a MORPH-PROOF ISLAND:
    without it, every server re-render changed the x-data attribute and
    Livewire+Alpine destroyed/re-created the component mid-edit — wiping
    the blocks and the in-memory registry the Apply button reads. --}}
<div x-data="expressionTree({ fieldKey: @js($fieldKey), graphKey: @js($graphKey), tree: @js($tree), graph: @js($graph), metricLabels: @js($metricLabels), metricValues: @js($metricValues) })"
     wire:key="expr-tree-{{ $widgetId }}-{{ $fieldKey }}-{{ $openCount }}"
     wire:ignore
     class="rounded-md border border-base-300 bg-base-200/40 p-3"
     @keydown.escape.window="linking = null; linkMouse = null">

    {{-- Belt-and-braces sync: the canvas state ALSO travels through
        wire:model hidden inputs (the same deferred channel every other
        settings field uses), on top of the commitTreeGraph action call.
        x-effect keeps the inputs in step with the canvas. --}}
    <input type="hidden" wire:model="{{ $fieldKey }}" x-effect="syncHidden($el, expressionValid ? expression : null)" aria-hidden="true" tabindex="-1">
    <input type="hidden" wire:model="{{ $graphKey }}" x-effect="syncHidden($el, graphJson())" aria-hidden="true" tabindex="-1">

    {{-- Advanced fallback: the stored formula uses syntax the canvas
        cannot draw. Show it read-only with an explicit escape hatch. --}}
    <template x-if="advanced">
        <div class="space-y-2">
            <p class="text-[11px] leading-4 text-base-content/60">
                This formula was written in advanced syntax. Leave it unchanged, or start over with the visual builder.
            </p>
            <div class="flex items-center gap-2">
                <input type="text" value="{{ $expression }}" readonly class="admin-control w-full flex-1 font-mono text-xs" aria-label="Current formula">
                <button type="button" x-on:click="startOver()" class="admin-secondary-button shrink-0">Start over</button>
            </div>
        </div>
    </template>

    <template x-if="!advanced">
        <div>
            {{-- Palette --}}
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="mr-1 text-[10px] font-bold uppercase tracking-[0.14em] text-base-content/40">Add block</span>
                <button type="button" x-on:click="addBlock('metric')" class="admin-secondary-button !px-2.5 !py-1 !text-[11px]">+ Metric</button>
                <button type="button" x-on:click="addBlock('number')" class="admin-secondary-button !px-2.5 !py-1 !text-[11px]">+ Number</button>
                <button type="button" x-on:click="addBlock('op')" class="admin-secondary-button !px-2.5 !py-1 !text-[11px]">+ Operator</button>
                <button type="button" x-on:click="addBlock('fn')" class="admin-secondary-button !px-2.5 !py-1 !text-[11px]">+ Function</button>
            </div>

            {{-- Canvas --}}
            <div x-ref="canvas"
                 class="tree-canvas relative mt-2 h-56 overflow-auto rounded-md border border-base-300 bg-base-100 sm:h-64"
                 @pointermove="onMove($event)"
                 @pointerup="onUp()"
                 @pointerleave="onUp()">
                {{-- Two static paths only — x-for cannot run inside <svg>,
                    so the curves are joined into one `d` string. --}}
                <svg class="pointer-events-none absolute left-0 top-0" :width="canvasSize.width" :height="canvasSize.height" aria-hidden="true">
                    <path :d="linksD" fill="none" stroke="var(--color-primary)" stroke-width="2" opacity=".45" />
                    <path x-show="!!pendingLink" :d="pendingLink || ''" fill="none" stroke="var(--color-primary)" stroke-width="2" stroke-dasharray="5 4" opacity=".7" />
                </svg>

                <template x-for="node in nodes" :key="node.id">
                    <div class="tree-node absolute select-none rounded-xl border-2 shadow-sm"
                         :class="[
                             node.kind === 'metric' ? 'border-success/50 bg-success/10' : '',
                             node.kind === 'number' ? 'border-info/50 bg-info/10' : '',
                             node.kind === 'op' ? 'border-primary/50 bg-primary/10' : '',
                             node.kind === 'fn' ? 'border-warning/50 bg-warning/10' : '',
                             selected === node.id ? 'ring-2 ring-primary ring-offset-1 ring-offset-base-100' : '',
                         ]"
                         :style="`left: ${node.x}px; top: ${node.y}px; width: 150px; height: 56px`"
                         @pointerdown="startDrag($event, node)">
                        <p class="pt-1.5 text-center text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/45"
                           x-text="node.kind === 'metric' ? 'Metric' : (node.kind === 'number' ? 'Number' : (node.kind === 'op' ? 'Operator' : 'Function'))"></p>
                        <p class="truncate px-2 text-center text-sm font-bold text-base-content"
                           x-text="node.kind === 'metric' ? (metricLabels[node.value] ?? node.value) : (node.kind === 'op' ? opGlyph(node.value) : (node.kind === 'fn' ? node.value + '( )' : node.value))"></p>

                        {{-- Output port (right): click to start/restart a
                            connection, click again to cancel. --}}
                        <button type="button"
                                class="absolute -right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 cursor-pointer rounded-full border-2 border-base-300 bg-base-100 transition hover:scale-125 hover:border-primary"
                                :class="linking?.from === node.id ? '!border-primary bg-primary/60' : ''"
                                title="Click, then click an input dot to connect — click again to cancel"
                                aria-label="Output — start connection"
                                @pointerdown.stop
                                @click.stop="toggleLink(node)"></button>

                        {{-- Input slots (left): click to complete a pending
                            connection, or click a filled one to unlink. --}}
                        <template x-for="(input, index) in node.inputs" :key="index">
                            <button type="button"
                                    class="absolute -left-2.5 h-4 w-4 cursor-pointer rounded-full border-2 bg-base-100 transition hover:scale-125"
                                    :class="input ? 'border-primary bg-primary/40' : (linking ? 'border-warning animate-pulse' : 'border-base-300')"
                                    :style="`top: calc(50% + ${(index - (node.inputs.length - 1) / 2) * 24}px - 8px)`"
                                    :title="input ? 'Click to disconnect' : 'Click to connect'"
                                    aria-label="Input — complete connection"
                                    @pointerdown.stop
                                    @click.stop="handleInput(node, index)"></button>
                        </template>

                        <button type="button"
                                class="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full border border-base-300 bg-base-100 text-[10px] font-black text-base-content/60 transition hover:border-error hover:text-error"
                                title="Remove block"
                                aria-label="Remove block"
                                @pointerdown.stop
                                @click.stop="removeNode(node.id)">✕</button>
                    </div>
                </template>

                <template x-if="nodes.length === 0">
                    <p class="pointer-events-none absolute left-1/2 top-1/2 w-64 -translate-x-1/2 -translate-y-1/2 text-center text-xs leading-5 text-base-content/40">
                        Add blocks from the palette, then connect them: click a block's right dot, then an input dot. The final block is your formula's result.
                    </p>
                </template>
            </div>

            {{-- Selected block editor --}}
            <template x-if="selectedNode">
                <div class="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-base-100 px-3 py-2.5">
                    <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-base-content/40">Edit block</span>
                    <template x-if="selectedNode.kind === 'metric'">
                        <select x-model="selectedNode.value" @change="commit()" class="admin-control min-w-0 flex-1" aria-label="Metric">
                            <template x-for="(label, key) in metricLabels" :key="key">
                                <option :value="key" x-text="label" :selected="selectedNode.value === key"></option>
                            </template>
                        </select>
                    </template>
                    <template x-if="selectedNode.kind === 'number'">
                        <input type="text" x-model="selectedNode.value" @change="commit()" class="admin-control w-28" placeholder="e.g. 100" aria-label="Number">
                    </template>
                    <template x-if="selectedNode.kind === 'op'">
                        <select x-model="selectedNode.value" @change="commit()" class="admin-control" aria-label="Operator">
                            <template x-for="op in ops" :key="op">
                                <option :value="op" x-text="opGlyph(op)" :selected="selectedNode.value === op"></option>
                            </template>
                        </select>
                    </template>
                    <template x-if="selectedNode.kind === 'fn'">
                        <select x-model="selectedNode.value" @change="onFnChange()" class="admin-control" aria-label="Function">
                            <template x-for="fn in fns" :key="fn">
                                <option :value="fn" x-text="fn + '( )'" :selected="selectedNode.value === fn"></option>
                            </template>
                        </select>
                    </template>
                    <button type="button" x-on:click="removeNode(selectedNode.id)" class="admin-secondary-button shrink-0 !border-error/40 !text-error">Delete block</button>
                </div>
            </template>
            <template x-if="linking">
                <p class="mt-2 rounded-md bg-warning/10 px-3 py-2 text-[11px] font-semibold text-warning-content" aria-live="polite">
                    Picking a target… click one of the left-side dots to connect, or press Esc to cancel.
                </p>
            </template>
            <template x-if="! selectedNode && ! linking && nodes.length > 0">
                <p class="mt-2 text-[11px] text-base-content/45">Click a block to edit its value. Click a block's right-side dot, then another block's left-side dot, to connect them.</p>
            </template>

            {{-- Live summary --}}
            <p class="mt-2 rounded-md bg-base-100 px-3 py-2 text-xs leading-5 text-base-content/70" aria-live="polite">
                <template x-if="valid">
                    <span>
                        <span class="font-mono text-[11px] text-base-content/55" x-text="expression"></span>
                        <strong class="ml-2 font-bold text-primary" x-text="previewText"></strong>
                    </span>
                </template>
                <template x-if="!valid">
                    <span class="text-warning-content">Connect every block into one final result — that block is what the widget displays.</span>
                </template>
            </p>
        </div>
    </template>
</div>
