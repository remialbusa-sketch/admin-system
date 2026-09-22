<?php

namespace App\Livewire\Concerns;

trait HasTableFilters
{
    /**
     * Count of all active filters (search, status, per-column, branch, archived flag, drill-downs).
     * Used for the "Filters (n)" badge so advanced controls stay discoverable without crowding the bar.
     */
    public function activeFilterCount(): int
    {
        $count = 0;

        if (trim($this->search ?? '') !== '') {
            $count++;
        }

        // statusFilter sentinel varies per table; Dashboard uses All statuses, ManagedTable base null sentinel
        $status = $this->statusFilter ?? null;
        if ($status !== null && $status !== '' && $status !== 'All statuses') {
            $count++;
        }

        if (! empty($this->columnFilters) && is_array($this->columnFilters)) {
            $count += count(array_filter($this->columnFilters, fn ($v) => $v !== null && $v !== ''));
        }

        // Branch exists only on InstalledProductsTable, so check dynamically
        if (property_exists($this, 'branchFilter') && isset($this->branchFilter) && $this->branchFilter !== 'All branches' && trim((string) $this->branchFilter) !== '') {
            $count++;
        }

        if (! empty($this->showArchived)) {
            $count++;
        }

        // Drill-down chips are already URL-bound URL props — count them as active too
        if (method_exists($this, 'drillDownFilters')) {
            $drills = $this->drillDownFilters();
            if (is_array($drills)) {
                $count += count(array_filter($drills, fn ($v) => $v !== null && $v !== ''));
            }
        }

        return $count;
    }

    public function hasActiveFilters(): bool
    {
        return $this->activeFilterCount() > 0;
    }

    /**
     * Unified chips for the "Active filters:" row — merges search, column filters,
     * branch, and drill-downs so the operator always sees why rows disappeared.
     * Each chip carries its own clear action (×).
     *
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public function activeFilterChips(): array
    {
        $chips = [];

        if (trim($this->search ?? '') !== '') {
            $chips[] = ['key' => 'search', 'label' => 'Search', 'value' => trim($this->search)];
        }

        $status = $this->statusFilter ?? null;
        if ($status !== null && $status !== '' && $status !== 'All statuses') {
            $chips[] = ['key' => 'statusFilter', 'label' => 'Status', 'value' => (string) $status];
        }

        if (! empty($this->columnFilters) && is_array($this->columnFilters)) {
            $columns = method_exists($this, 'allColumns') ? $this->allColumns() : [];
            $labelMap = collect($columns)->keyBy('key')->map(fn ($c) => $c['label'] ?? $c['key'])->all();
            foreach ($this->columnFilters as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $label = $labelMap[$field] ?? $field;
                $chips[] = ['key' => 'column:'.$field, 'label' => $label, 'value' => (string) $value];
            }
        }

        if (property_exists($this, 'branchFilter') && isset($this->branchFilter) && $this->branchFilter !== 'All branches' && trim((string) $this->branchFilter) !== '') {
            $chips[] = ['key' => 'branchFilter', 'label' => 'Branch', 'value' => (string) $this->branchFilter];
        }

        if (! empty($this->showArchived)) {
            $chips[] = ['key' => 'showArchived', 'label' => 'View', 'value' => 'Archived'];
        }

        if (method_exists($this, 'drillDownFilters')) {
            foreach ($this->drillDownFilters() as $label => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                // Avoid duplicating a chip already counted above (status/region etc may overlap)
                $already = collect($chips)->contains(fn ($c) => strtolower($c['label']) === strtolower($label) && $c['value'] === (string) $value);
                if ($already) {
                    continue;
                }
                $chips[] = ['key' => 'drill:'.$label, 'label' => $label, 'value' => str_replace('_', ' ', (string) $value)];
            }
        }

        return $chips;
    }

    /**
     * Clear every filter in one action — used by the "Clear all" chip row and by Esc.
     */
    public function clearAllFilters(): void
    {
        $this->search = '';

        // Keep the sentinel compatible with each table's statusFilter semantics
        $isPersonnel = method_exists($this, 'tableKey') && $this->tableKey() === 'personnel';
        $hasBranchProp = property_exists($this, 'branchFilter');

        if ($isPersonnel) {
            $this->statusFilter = 'All branches';
        } elseif ($hasBranchProp) {
            $this->branchFilter = 'All branches';
            // Preserve status sentinel handling: clearDrillDown already resets to "All statuses" for Installed
            if (method_exists($this, 'clearDrillDown')) {
                $drills = $this->drillDownFilters();
                if (! empty($drills)) {
                    $this->clearDrillDown();
                    // clearDrillDown did statusFilter reset for Installed
                } elseif ($this->statusFilter !== null && $this->statusFilter !== 'All statuses') {
                    $this->statusFilter = 'All statuses';
                }
            } elseif ($this->statusFilter !== null && $this->statusFilter !== 'All statuses') {
                $this->statusFilter = 'All statuses';
            }
        } else {
            $this->statusFilter = null;
            if (method_exists($this, 'clearDrillDown')) {
                $drills = $this->drillDownFilters();
                if (! empty($drills)) {
                    $this->clearDrillDown();
                }
            }
        }

        $this->columnFilters = [];
        $this->showArchived = false;

        $this->resetPage();
    }

    /**
     * Remove a single chip by its key. Called from the × on each active-filter chip.
     */
    public function removeFilterChip(string $chipKey): void
    {
        $isPersonnel = method_exists($this, 'tableKey') && $this->tableKey() === 'personnel';

        if ($chipKey === 'search') {
            $this->search = '';
        } elseif ($chipKey === 'statusFilter') {
            $this->statusFilter = $isPersonnel ? 'All branches' : (property_exists($this, 'branchFilter') ? 'All statuses' : null);
        } elseif ($chipKey === 'branchFilter') {
            $this->branchFilter = 'All branches';
        } elseif ($chipKey === 'showArchived') {
            $this->showArchived = false;
        } elseif (str_starts_with($chipKey, 'column:')) {
            $field = substr($chipKey, 7);
            unset($this->columnFilters[$field]);
        } elseif (str_starts_with($chipKey, 'drill:')) {
            // Drill keys map to URL props — clear the matching property if it exists
            $label = substr($chipKey, 6);
            // Map common drill labels to their Livewire public props
            $propMap = [
                'Brand' => 'brand',
                'Machine type' => 'machineType',
                'Region' => 'region',
                'Customer' => 'customer',
                'Warranty' => 'warranty',
                'PMS' => 'pms',
                'Contract' => 'contract',
                'Installed month' => 'installed',
                'Device status' => 'statusFilter',
                'Branch' => $isPersonnel ? 'statusFilter' : 'branch',
                'Assigned' => 'assigned',
                'Completed' => 'completed',
            ];
            $prop = $propMap[$label] ?? null;
            if ($prop && property_exists($this, $prop)) {
                $this->{$prop} = null;
                if ($prop === 'statusFilter') {
                    $this->{$prop} = $isPersonnel ? 'All branches' : (property_exists($this, 'branchFilter') ? 'All statuses' : null);
                }
            } else {
                // Fallback: clear the whole drill set
                if (method_exists($this, 'clearDrillDown')) {
                    $this->clearDrillDown();
                    return;
                }
            }
        }

        $this->resetPage();
    }
}
