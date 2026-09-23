{{-- Shared widget-settings modal for layout-engine grids (Dashboard, TSA,
     TSP, …). Fields are driven by the factory's settings schema; the host
     component owns the $ws state slice and the apply/cancel handlers. --}}
<x-admin.modal :name="$wsModal" title="Widget settings" description="Configure this widget. Changes apply to the draft immediately and are saved when you click Done." size="lg">
    @php
        $wsSchema = $ws['settingsSchema'] ?? [];
        $wsProps = $ws['settingsProps'] ?? [];
    @endphp
    <div class="space-y-4">
        @if ($ws['settingsError'] ?? null)
            <p class="rounded-md bg-error/10 px-3 py-2 text-xs font-semibold text-error" role="alert">{{ $ws['settingsError'] }}</p>
        @endif
        @if ($ws['settingsApplied'] ?? false)
            <p class="rounded-md bg-success/10 px-3 py-2 text-xs font-semibold text-success" role="status">
                Applied to the draft ✓ — {{ $ws['settingsGraphCount'] ?? 0 }} formula canvas{{ ($ws['settingsGraphCount'] ?? 0) === 1 ? '' : 'es' }} received from the editor. Keep editing, or close and click Done to save.
            </p>
        @endif
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach ($wsSchema as $field)
                @php $fieldKey = $wsPrefix.'.'.$field['key']; @endphp
                <div class="{{ ($field['type'] ?? '') === 'expression' ? 'sm:col-span-2' : '' }}">
                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-[0.1em] text-base-content/55" for="widget-field-{{ $wsModal }}-{{ $field['key'] }}">
                        {{ $field['label'] }} @if ($field['required'] ?? false)<span class="text-error" title="Required">*</span>@endif
                    </label>
                    @if (($field['type'] ?? '') === 'select')
                        <select id="widget-field-{{ $wsModal }}-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full">
                            @foreach ($field['options'] ?? [] as $option)
                                <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                            @endforeach
                        </select>
                    @elseif (($field['type'] ?? '') === 'metric')
                        <select id="widget-field-{{ $wsModal }}-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full">
                            @if (isset($field['grouped_options']))
                                @foreach ($field['grouped_options'] as $group => $grouped)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($grouped as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            @else
                                @foreach (($field['options'] ?? array_keys($wsMetricLabels)) as $option)
                                    <option value="{{ $option }}">{{ $wsMetricLabels[$option] ?? ucfirst($option) }}</option>
                                @endforeach
                            @endif
                        </select>
                    @elseif (($field['type'] ?? '') === 'dataset')
                        <select id="widget-field-{{ $wsModal }}-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full">
                            @if (isset($field['grouped_options']))
                                @foreach ($field['grouped_options'] as $group => $grouped)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($grouped as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            @else
                                @foreach (($field['options'] ?? []) as $option)
                                    <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                                @endforeach
                            @endif
                        </select>
                    @elseif (($field['type'] ?? '') === 'boolean')
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="checkbox" id="widget-field-{{ $wsModal }}-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="toggle toggle-sm toggle-primary">
                            <span class="text-base-content/70">{{ $field['toggle_label'] ?? 'Enabled' }}</span>
                        </label>
                    @elseif (($field['type'] ?? '') === 'number')
                        <input type="number" id="widget-field-{{ $wsModal }}-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full" placeholder="{{ $field['placeholder'] ?? '' }}">
                    @elseif (($field['type'] ?? '') === 'expression' && ($field['visual'] ?? false))
                        {{-- Drag-drop expression tree (no syntax typing). --}}
                        <x-dashboard.expression-tree
                            :field-key="$fieldKey"
                            :expression="$wsProps[$field['key']] ?? ''"
                            :tree="$field['tree'] ?? null"
                            :graph="$wsProps[$field['key'].'_tree'] ?? null"
                            :graph-key="$fieldKey.'_tree'"
                            :metric-labels="$wsMetricLabels"
                            :metric-values="$wsMetricValues"
                            :widget-id="$ws['settingsWidgetId'] ?? null"
                            :open-count="$ws['settingsOpenCount'] ?? 0"
                        />
                    @elseif (($field['type'] ?? '') === 'expression')
                        <textarea id="widget-field-{{ $wsModal }}-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" rows="2" class="admin-control w-full font-mono text-xs" placeholder="{{ $field['placeholder'] ?? '' }}"></textarea>
                    @else
                        <input type="text" id="widget-field-{{ $wsModal }}-{{ $field['key'] }}" wire:model="{{ $fieldKey }}" class="admin-control w-full" placeholder="{{ $field['placeholder'] ?? '' }}">
                    @endif
                    @if ($field['help'] ?? false)
                        <p class="mt-1 text-[11px] leading-4 text-base-content/45">{{ $field['help'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($wsHint ?? false)
            @php
                $wsNeedData = collect($wsSchema)->contains(fn ($f) => in_array($f['type'] ?? '', ['metric', 'dataset'], true));
                $wsHasData = filled($wsProps['metric'] ?? null) || filled($wsProps['dataset'] ?? null) || filled(trim((string) ($wsProps['formula'] ?? '')));
            @endphp
            @if ($wsNeedData && ! $wsHasData)
                <p class="rounded-md bg-warning/10 px-3 py-2 text-xs font-semibold text-warning" role="status">{{ $wsHint }}</p>
            @endif
        @endif
        @php $buildMark = substr(md5_file(public_path('build/manifest.json')), 0, 6) @endphp
        <div class="sticky bottom-0 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-base-300 bg-base-100 px-4 py-3 sm:-mx-5 sm:px-5">
            <span class="mr-auto text-[10px] font-mono text-base-content/25">build {{ $buildMark }}</span>
            <button type="button" wire:click="{{ $wsCancel }}" x-on:click="$dispatch('close-modal', { name: '{{ $wsModal }}' })" class="admin-secondary-button">Close</button>
            <button type="button" x-on:click="$wire.{!! $wsApply !!}" class="admin-primary-button">Apply</button>
        </div>
    </div>
</x-admin.modal>
