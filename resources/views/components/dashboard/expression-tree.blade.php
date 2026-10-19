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

{{-- Friendly & Powerful Expression Builder:
    Offers two seamless modes:
      1. Guided Builder: 1-click templates (Percentage, Single Metric, Sum, Difference, Ratio, Round)
         where users simply pick the metrics with live value previews — zero wire-connecting friction.
      2. Canvas Graph: Interactive visual node canvas for custom multi-step calculations with
         auto-arrange, port labels, and clear output indicators.
    Both modes share the exact same underlying node graph and math expression string. --}}
<div x-data="expressionTree({ fieldKey: @js($fieldKey), graphKey: @js($graphKey), tree: @js($tree), graph: @js($graph), metricLabels: @js($metricLabels), metricValues: @js($metricValues) })"
     wire:key="expr-tree-{{ $widgetId }}-{{ $fieldKey }}-{{ $openCount }}"
     wire:ignore
     class="rounded-xl border border-base-300 bg-base-200/50 p-3.5 shadow-xs"
     @keydown.escape.window="linking = null; linkMouse = null">

    {{-- Belt-and-braces sync: hidden inputs for Livewire --}}
    <input type="hidden" wire:model="{{ $fieldKey }}" x-effect="syncHidden($el, expressionValid ? expression : null)" aria-hidden="true" tabindex="-1">
    <input type="hidden" wire:model="{{ $graphKey }}" x-effect="syncHidden($el, graphJson())" aria-hidden="true" tabindex="-1">

    {{-- Advanced fallback: formula uses syntax canvas cannot draw --}}
    <template x-if="advanced">
        <div class="space-y-3 rounded-lg border border-warning/30 bg-warning/5 p-3">
            <div class="flex items-start gap-2">
                <span class="text-base text-warning">ⓘ</span>
                <div>
                    <h5 class="text-xs font-bold text-base-content">Advanced Custom Syntax</h5>
                    <p class="text-[11px] leading-4 text-base-content/70">
                        This formula was written using advanced syntax. You can leave it unchanged or reset to the visual builder.
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <input type="text" value="{{ $expression }}" readonly class="admin-control w-full flex-1 font-mono text-xs" aria-label="Current formula">
                <button type="button" x-on:click="startOver()" class="admin-secondary-button shrink-0 !border-warning/40 !text-warning-content">Reset to Builder</button>
            </div>
        </div>
    </template>

    <template x-if="!advanced">
        <div class="space-y-3">
            {{-- Top Toolbar & Mode Switcher --}}
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 pb-2.5">
                <div class="inline-flex rounded-lg border border-base-300 bg-base-100 p-0.5 shadow-xs">
                    <button type="button"
                            x-on:click="viewMode = 'guided'"
                            class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-semibold transition"
                            :class="viewMode === 'guided' ? 'bg-primary text-primary-content shadow-xs' : 'text-base-content/70 hover:text-base-content'">
                        <span>⚡</span>
                        <span>Guided Builder</span>
                    </button>
                    <button type="button"
                            x-on:click="viewMode = 'canvas'"
                            class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-semibold transition"
                            :class="viewMode === 'canvas' ? 'bg-primary text-primary-content shadow-xs' : 'text-base-content/70 hover:text-base-content'">
                        <span>🧩</span>
                        <span>Visual Blocks</span>
                        <span x-show="nodes.length > 0" class="badge badge-xs" :class="viewMode === 'canvas' ? 'badge-neutral' : 'badge-ghost'" x-text="nodes.length"></span>
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <template x-if="viewMode === 'canvas'">
                        <button type="button"
                                x-on:click="autoArrange()"
                                class="admin-secondary-button !px-2.5 !py-1 !text-xs font-medium"
                                title="Neatly align blocks left-to-right">
                            <span>🧹 Auto-Align</span>
                        </button>
                    </template>
                    <button type="button"
                            x-on:click="startOver()"
                            class="admin-secondary-button !px-2.5 !py-1 !text-xs font-medium text-base-content/60 hover:text-error"
                            title="Clear all blocks and start over">
                        <span>↺ Clear</span>
                    </button>
                </div>
            </div>

            {{-- VIEW 1: GUIDED BUILDER (Zero-friction template workflow) --}}
            <div x-show="viewMode === 'guided'" class="space-y-3">
                {{-- Template Picker Pills --}}
                <div>
                    <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-[0.14em] text-base-content/50">Choose Calculation Type</label>
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button"
                                x-on:click="applyTemplate('percentage')"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                :class="activeTemplate === 'percentage' ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary' : 'border-base-300 bg-base-100 text-base-content/70 hover:border-base-content/30'">
                            📊 Percentage <span class="text-[10px] opacity-60">(A ÷ B × 100)</span>
                        </button>
                        <button type="button"
                                x-on:click="applyTemplate('single')"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                :class="activeTemplate === 'single' ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary' : 'border-base-300 bg-base-100 text-base-content/70 hover:border-base-content/30'">
                            🎯 Single Metric <span class="text-[10px] opacity-60">(Direct)</span>
                        </button>
                        <button type="button"
                                x-on:click="applyTemplate('sum')"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                :class="activeTemplate === 'sum' ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary' : 'border-base-300 bg-base-100 text-base-content/70 hover:border-base-content/30'">
                            ➕ Sum <span class="text-[10px] opacity-60">(A + B)</span>
                        </button>
                        <button type="button"
                                x-on:click="applyTemplate('difference')"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                :class="activeTemplate === 'difference' ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary' : 'border-base-300 bg-base-100 text-base-content/70 hover:border-base-content/30'">
                            ➖ Difference <span class="text-[10px] opacity-60">(A − B)</span>
                        </button>
                        <button type="button"
                                x-on:click="applyTemplate('ratio')"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                :class="activeTemplate === 'ratio' ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary' : 'border-base-300 bg-base-100 text-base-content/70 hover:border-base-content/30'">
                            ➗ Ratio <span class="text-[10px] opacity-60">(A ÷ B)</span>
                        </button>
                        <button type="button"
                                x-on:click="applyTemplate('round')"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                :class="activeTemplate === 'round' ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary' : 'border-base-300 bg-base-100 text-base-content/70 hover:border-base-content/30'">
                            🔢 Round <span class="text-[10px] opacity-60">(Decimals)</span>
                        </button>
                        <template x-if="activeTemplate === 'custom'">
                            <span class="rounded-lg border border-accent bg-accent/10 px-3 py-1.5 text-xs font-semibold text-accent">
                                ⚙️ Custom Calculation
                            </span>
                        </template>
                    </div>
                </div>

                {{-- Interactive Template Inputs Card --}}
                <div class="rounded-xl border border-base-300 bg-base-100 p-4 shadow-xs">
                    {{-- Percentage Template Form --}}
                    <template x-if="activeTemplate === 'percentage'">
                        <div class="space-y-3">
                            <p class="text-xs text-base-content/60">Calculates the completion or share percentage of a part relative to a whole:</p>
                            <div class="grid grid-cols-1 items-center gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-5">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Part (Numerator)</label>
                                    <select x-model="guided.metricA" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Part Metric">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricA === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="text-center font-bold text-base-content/40 sm:col-span-2">
                                    <span class="inline-block rounded-full bg-base-200 px-2.5 py-1 text-xs">÷ divided by</span>
                                </div>
                                <div class="sm:col-span-5">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Total (Denominator)</label>
                                    <select x-model="guided.metricB" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Total Metric">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricB === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Single Metric Form --}}
                    <template x-if="activeTemplate === 'single'">
                        <div class="space-y-3">
                            <p class="text-xs text-base-content/60">Directly display an operational metric from this table without modifications:</p>
                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-base-content/70">Select Metric</label>
                                <select x-model="guided.metricA" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Metric">
                                    <template x-for="m in metricList" :key="m.key">
                                        <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricA === m.key"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </template>

                    {{-- Sum Form --}}
                    <template x-if="activeTemplate === 'sum'">
                        <div class="space-y-3">
                            <p class="text-xs text-base-content/60">Add two operational metrics together:</p>
                            <div class="grid grid-cols-1 items-center gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-5">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">First Metric</label>
                                    <select x-model="guided.metricA" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="First Metric">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricA === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="text-center font-bold text-base-content/40 sm:col-span-2">
                                    <span class="inline-block rounded-full bg-base-200 px-2.5 py-1 text-xs">+ plus</span>
                                </div>
                                <div class="sm:col-span-5">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Second Metric</label>
                                    <select x-model="guided.metricB" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Second Metric">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricB === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Difference Form --}}
                    <template x-if="activeTemplate === 'difference'">
                        <div class="space-y-3">
                            <p class="text-xs text-base-content/60">Measure the gap or difference between two metrics:</p>
                            <div class="grid grid-cols-1 items-center gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-5">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Base Metric</label>
                                    <select x-model="guided.metricA" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Base Metric">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricA === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="text-center font-bold text-base-content/40 sm:col-span-2">
                                    <span class="inline-block rounded-full bg-base-200 px-2.5 py-1 text-xs">− minus</span>
                                </div>
                                <div class="sm:col-span-5">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Deducted Metric</label>
                                    <select x-model="guided.metricB" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Deducted Metric">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricB === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Ratio Form --}}
                    <template x-if="activeTemplate === 'ratio'">
                        <div class="space-y-3">
                            <p class="text-xs text-base-content/60">Calculate the proportion between two numbers:</p>
                            <div class="grid grid-cols-1 items-center gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-5">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Numerator</label>
                                    <select x-model="guided.metricA" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Numerator">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricA === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="text-center font-bold text-base-content/40 sm:col-span-2">
                                    <span class="inline-block rounded-full bg-base-200 px-2.5 py-1 text-xs">÷ divided by</span>
                                </div>
                                <div class="sm:col-span-5">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Denominator</label>
                                    <select x-model="guided.metricB" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Denominator">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricB === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Round Form --}}
                    <template x-if="activeTemplate === 'round'">
                        <div class="space-y-3">
                            <p class="text-xs text-base-content/60">Round a metric to a specified number of decimal places:</p>
                            <div class="grid grid-cols-1 items-center gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-8">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Metric to Round</label>
                                    <select x-model="guided.metricA" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Metric to round">
                                        <template x-for="m in metricList" :key="m.key">
                                            <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="guided.metricA === m.key"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="sm:col-span-4">
                                    <label class="mb-1 block text-[11px] font-bold text-base-content/70">Decimal Places</label>
                                    <select x-model.number="guided.digits" x-on:change="onGuidedChange()" class="admin-control w-full text-xs font-medium" aria-label="Decimals">
                                        <option value="0">0 (Whole number)</option>
                                        <option value="1">1 (e.g. 12.3)</option>
                                        <option value="2">2 (e.g. 12.34)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Custom Notice --}}
                    <template x-if="activeTemplate === 'custom'">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h6 class="text-xs font-bold text-base-content">Multi-Step Custom Graph Active</h6>
                                <p class="text-[11px] text-base-content/60">This calculation uses visual blocks. You can fine-tune it in the Visual Blocks view or reset to a template above.</p>
                            </div>
                            <button type="button" x-on:click="viewMode = 'canvas'" class="admin-secondary-button shrink-0 !text-xs">
                                Edit Blocks in Canvas →
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            {{-- VIEW 2: VISUAL BLOCKS CANVAS (Node graph view) --}}
            <div x-show="viewMode === 'canvas'" class="space-y-2.5">
                {{-- Palette Toolbar --}}
                <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-base-300 bg-base-100 px-3 py-2">
                    <span class="mr-1 text-[10px] font-bold uppercase tracking-[0.14em] text-base-content/40">Add Block</span>
                    <button type="button" x-on:click="addBlock('metric')" class="admin-secondary-button !px-2.5 !py-1 !text-xs font-medium">+ Metric</button>
                    <button type="button" x-on:click="addBlock('number')" class="admin-secondary-button !px-2.5 !py-1 !text-xs font-medium">+ Number</button>
                    <button type="button" x-on:click="addBlock('op')" class="admin-secondary-button !px-2.5 !py-1 !text-xs font-medium">+ Operator</button>
                    <button type="button" x-on:click="addBlock('fn')" class="admin-secondary-button !px-2.5 !py-1 !text-xs font-medium">+ Function</button>
                    <span class="ml-auto text-[11px] text-base-content/45 hidden sm:inline">
                        Click a right dot, then a left dot to connect
                    </span>
                </div>

                {{-- Interactive Canvas --}}
                <div x-ref="canvas"
                     class="tree-canvas relative h-60 overflow-auto rounded-xl border border-base-300 bg-base-100 sm:h-64 shadow-inner"
                     style="background-image: radial-gradient(var(--color-base-300) 1px, transparent 1px); background-size: 16px 16px;"
                     @pointermove="onMove($event)"
                     @pointerup="onUp()"
                     @pointerleave="onUp()">

                    <svg class="pointer-events-none absolute left-0 top-0" :width="canvasSize.width" :height="canvasSize.height" aria-hidden="true">
                        <path :d="linksD" fill="none" stroke="var(--color-primary)" stroke-width="2.5" opacity=".55" />
                        <path x-show="!!pendingLink" :d="pendingLink || ''" fill="none" stroke="var(--color-primary)" stroke-width="2.5" stroke-dasharray="5 4" opacity=".85" />
                    </svg>

                    <template x-for="node in nodes" :key="node.id">
                        <div class="tree-node absolute select-none rounded-xl border-2 shadow-sm transition-shadow"
                             :class="[
                                 node.kind === 'metric' ? 'border-success/60 bg-success/10' : '',
                                 node.kind === 'number' ? 'border-info/60 bg-info/10' : '',
                                 node.kind === 'op' ? 'border-primary/60 bg-primary/10' : '',
                                 node.kind === 'fn' ? 'border-warning/60 bg-warning/10' : '',
                                 selected === node.id ? 'ring-2 ring-primary ring-offset-2 ring-offset-base-100 shadow-md' : '',
                             ]"
                             :style="`left: ${node.x}px; top: ${node.y}px; width: 160px; height: 60px`"
                             @pointerdown="startDrag($event, node)">

                            {{-- Root / Output Badge --}}
                            <template x-if="node.id === rootId">
                                <span class="absolute -top-2.5 left-2 rounded-full bg-primary px-2 py-0.5 text-[9px] font-black tracking-wider text-primary-content shadow-xs">
                                    ★ RESULT OUTPUT
                                </span>
                            </template>

                            <p class="pt-2 text-center text-[9px] font-bold uppercase tracking-[0.14em] text-base-content/50"
                               x-text="node.kind === 'metric' ? 'Metric' : (node.kind === 'number' ? 'Number' : (node.kind === 'op' ? 'Operator' : 'Function'))"></p>
                            <p class="truncate px-2 text-center text-xs font-bold text-base-content"
                               x-text="node.kind === 'metric' ? (metricLabels[node.value] ?? node.value) : (node.kind === 'op' ? opGlyph(node.value) : (node.kind === 'fn' ? node.value + '( )' : node.value))"></p>

                            {{-- Output port (right dot) --}}
                            <button type="button"
                                    class="absolute -right-2.5 top-1/2 h-5 w-5 -translate-y-1/2 cursor-pointer rounded-full border-2 border-base-300 bg-base-100 transition hover:scale-125 hover:border-primary shadow-xs"
                                    :class="linking?.from === node.id ? '!border-primary bg-primary/70 ring-2 ring-primary/40' : ''"
                                    title="Click to start connection to another block"
                                    aria-label="Output — start connection"
                                    @pointerdown.stop
                                    @click.stop="toggleLink(node)"></button>

                            {{-- Input ports (left dots) --}}
                            <template x-for="(input, index) in node.inputs" :key="index">
                                <button type="button"
                                        class="absolute -left-2.5 h-5 w-5 cursor-pointer rounded-full border-2 bg-base-100 transition hover:scale-125 shadow-xs"
                                        :class="input ? 'border-primary bg-primary/50' : (linking ? 'border-warning bg-warning/30 animate-pulse' : 'border-base-300')"
                                        :style="`top: calc(50% + ${(index - (node.inputs.length - 1) / 2) * 24}px - 10px)`"
                                        :title="getPortLabel(node, index) + (input ? ' (Click to disconnect)' : ' (Click to connect)')"
                                        aria-label="Input — complete connection"
                                        @pointerdown.stop
                                        @click.stop="handleInput(node, index)"></button>
                            </template>

                            {{-- Remove node button --}}
                            <button type="button"
                                    class="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full border border-base-300 bg-base-100 text-[10px] font-black text-base-content/60 transition hover:border-error hover:bg-error hover:text-error-content shadow-xs"
                                    title="Remove block"
                                    aria-label="Remove block"
                                    @pointerdown.stop
                                    @click.stop="removeNode(node.id)">✕</button>
                        </div>
                    </template>

                    <template x-if="nodes.length === 0">
                        <div class="pointer-events-none absolute left-1/2 top-1/2 w-72 -translate-x-1/2 -translate-y-1/2 text-center text-xs text-base-content/40">
                            <p class="font-semibold text-base-content/60">Canvas is empty</p>
                            <p class="mt-1">Add blocks from the bar above, or switch to <strong>Guided Builder</strong> for instant 1-click templates.</p>
                        </div>
                    </template>
                </div>

                {{-- Selected Block Editor Strip --}}
                <template x-if="selectedNode">
                    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-base-300 bg-base-100 px-3 py-2 shadow-xs">
                        <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-base-content/50">Edit Selected Block:</span>
                        <template x-if="selectedNode.kind === 'metric'">
                            <select x-model="selectedNode.value" @change="commit()" class="admin-control min-w-0 flex-1 text-xs" aria-label="Metric">
                                <template x-for="m in metricList" :key="m.key">
                                    <option :value="m.key" x-text="`${m.label} (${m.formatted})`" :selected="selectedNode.value === m.key"></option>
                                </template>
                            </select>
                        </template>
                        <template x-if="selectedNode.kind === 'number'">
                            <input type="text" x-model="selectedNode.value" @change="commit()" class="admin-control w-28 text-xs font-mono" placeholder="e.g. 100" aria-label="Number">
                        </template>
                        <template x-if="selectedNode.kind === 'op'">
                            <select x-model="selectedNode.value" @change="commit()" class="admin-control text-xs" aria-label="Operator">
                                <template x-for="op in ops" :key="op">
                                    <option :value="op" x-text="opGlyph(op)" :selected="selectedNode.value === op"></option>
                                </template>
                            </select>
                        </template>
                        <template x-if="selectedNode.kind === 'fn'">
                            <select x-model="selectedNode.value" @change="onFnChange()" class="admin-control text-xs" aria-label="Function">
                                <template x-for="fn in fns" :key="fn">
                                    <option :value="fn" x-text="fn + '( )'" :selected="selectedNode.value === fn"></option>
                                </template>
                            </select>
                        </template>
                        <button type="button" x-on:click="removeNode(selectedNode.id)" class="admin-secondary-button shrink-0 !border-error/40 !text-error !text-xs">Delete block</button>
                    </div>
                </template>

                <template x-if="linking">
                    <p class="rounded-md bg-warning/10 px-3 py-1.5 text-xs font-semibold text-warning-content animate-pulse" aria-live="polite">
                        Connecting wire... click a left dot on a target block, or press Esc to cancel.
                    </p>
                </template>
            </div>

            {{-- LIVE RESULT & STATUS RIBBON (Always visible in both modes) --}}
            <div class="rounded-xl border border-base-300 bg-base-100 p-3 shadow-xs">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-base-content/45">Formula:</span>
                        <code class="rounded bg-base-200 px-2 py-0.5 font-mono text-xs text-base-content/80" x-text="expression || 'None yet'"></code>
                    </div>

                    <div class="flex items-center gap-2">
                        <template x-if="valid">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-0.5 text-xs font-bold text-success">
                                <span>✓</span>
                                <span x-text="previewText"></span>
                            </span>
                        </template>
                        <template x-if="!valid">
                            <span class="inline-flex items-center gap-1 rounded-full bg-warning/10 px-2.5 py-0.5 text-[11px] font-semibold text-warning-content">
                                <span>⚠</span> Incomplete formula
                            </span>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
