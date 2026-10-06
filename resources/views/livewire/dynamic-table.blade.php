<div class="space-y-5">
    {{-- monday.com connect panel (shared partial) — always shown for dynamic
         tables, matching the original per-table sync panel. --}}
    @include('livewire.partials.monday-sync')

    @include('livewire.partials.managed-table-grid')
</div>
