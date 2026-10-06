<div class="space-y-5">
    {{-- monday.com connect panel: visible to import-capable viewers, or to
         anyone when a board is already connected (documented data source). --}}
    @if ($canImport || ! empty($monday['mondayBoardId']) || ! empty($monday['mondayEnabled']))
        @include('livewire.partials.monday-sync')
    @endif
    @include('livewire.partials.managed-table-grid')
</div>
