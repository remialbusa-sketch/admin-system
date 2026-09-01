<?php

namespace App\Livewire;

use App\Models\TechnicalReport;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class TechnicalReportTable extends ManagedTable
{
    /**
     * Technical Service Analysis drill-down filters (URL-bound):
     *   ?brand= / ?tsp= / ?customer= / ?assigned=1|0
     *   ?completed=any|YYYY-MM-DD / ?completed_from=&completed_to=
     *   ?status= (base class → service_status)
     */
    #[Url]
    public ?string $brand = null;

    #[Url]
    public ?string $tsp = null;

    #[Url]
    public ?string $customer = null;

    #[Url]
    public ?string $assigned = null;

    #[Url]
    public ?string $completed = null;

    #[Url(as: 'completed_from')]
    public ?string $completedFrom = null;

    #[Url(as: 'completed_to')]
    public ?string $completedTo = null;

    public function tableKey(): string
    {
        return 'technical-reports';
    }

    protected function rules(): array
    {
        return [
            'ticket_status' => ['nullable', 'string', 'max:100'],
            'service_status' => ['nullable', 'string', 'max:100'],
            'tsp_name' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'machine_type' => ['nullable', 'string', 'max:100'],
            'job_done' => ['nullable', 'string', 'max:2000'],
            'parts_replaced' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function query(): Builder
    {
        return TechnicalReport::query()
            ->when($this->search !== '', function ($query): void {
                $like = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('reference_number', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('service_request_number', 'like', $like)
                        ->orWhere('tsp_name', 'like', $like);
                });
            })
            ->when($this->statusFilter !== null && $this->statusFilter !== 'All statuses', fn ($query) => $query->where('service_status', $this->statusFilter))
            // Technical Service Analysis drill-down filters (URL-bound).
            ->when($this->brand !== null && $this->brand !== '', fn ($query) => $query->where('brand', 'like', '%'.$this->brand.'%'))
            ->when($this->tsp !== null && $this->tsp !== '', fn ($query) => $query->where('tsp_name', $this->tsp))
            ->when($this->customer !== null && $this->customer !== '', fn ($query) => $query->where('customer_name', 'like', '%'.$this->customer.'%'))
            ->when($this->assigned === '1', fn ($query) => $query->whereNotNull('tsp_name')->where('tsp_name', '<>', ''))
            ->when($this->assigned === '0', fn ($query) => $query->where(fn ($q) => $q->whereNull('tsp_name')->orWhere('tsp_name', '')))
            ->when($this->completed === 'any', fn ($query) => $query->whereNotNull('service_completed_at'))
            ->when($this->completed !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->completed) === 1, fn ($query) => $query->whereDate('service_completed_at', $this->completed))
            ->when($this->completedFrom !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->completedFrom) === 1, fn ($query) => $query->whereDate('service_completed_at', '>=', $this->completedFrom))
            ->when($this->completedTo !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->completedTo) === 1, fn ($query) => $query->whereDate('service_completed_at', '<=', $this->completedTo));
    }

    /**
     * Drill-down filters currently applied via URL, shown as chips above the
     * grid so the operator can see (and clear) why the table is narrowed.
     * The TSP chip resolves the real display name — the filter value itself
     * is the raw workbook ID.
     *
     * @return array<string, string>
     */
    public function drillDownFilters(): array
    {
        return array_filter([
            'Service status' => ($this->statusFilter !== null && $this->statusFilter !== 'All statuses') ? $this->statusFilter : null,
            'Brand' => $this->brand,
            'TSP' => $this->tsp !== null && $this->tsp !== '' ? $this->tspDisplayLabel($this->tsp) : null,
            'Customer' => $this->customer,
            'TSP assignment' => $this->assigned === '1' ? 'assigned' : ($this->assigned === '0' ? 'unassigned' : null),
            'Completed' => $this->completed === 'any' ? 'any date' : $this->completed,
            'Completed from' => $this->completedFrom,
            'Completed to' => $this->completedTo,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** Display name for a raw tsp_name (workbook ID), with ID fallback. */
    private function tspDisplayLabel(string $tspName): string
    {
        return (string) (TechnicalReport::query()
            ->where('tsp_name', $tspName)
            ->max('tsp_display_name') ?: $tspName);
    }

    public function clearDrillDown(): void
    {
        $this->statusFilter = null;
        $this->brand = null;
        $this->tsp = null;
        $this->customer = null;
        $this->assigned = null;
        $this->completed = null;
        $this->completedFrom = null;
        $this->completedTo = null;
        $this->resetPage();
    }

    public function model(): string
    {
        return TechnicalReport::class;
    }

    public function columns(): array
    {
        return [
            ['key' => 'reference_number', 'label' => 'Reference #', 'type' => 'text'],
            ['key' => 'service_request_number', 'label' => 'Service Request #', 'type' => 'text'],
            ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text'],
            ['key' => 'ticket_status', 'label' => 'Ticket Status', 'type' => 'text'],
            ['key' => 'service_status', 'label' => 'Service Status', 'type' => 'select', 'options' => $this->statusOptions()],
            ['key' => 'tsp_name', 'label' => 'TSP ID', 'type' => 'text'],
            ['key' => 'tsp_display_name', 'label' => 'TSP Name', 'type' => 'text', 'editable' => false],
            ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
            ['key' => 'machine_type', 'label' => 'Machine Type', 'type' => 'text'],
            ['key' => 'job_done', 'label' => 'Job Done', 'type' => 'text'],
            ['key' => 'parts_replaced', 'label' => 'Parts Replaced', 'type' => 'text'],
            ['key' => 'service_completed_at', 'label' => 'Service Completed', 'type' => 'date'],
        ];
    }

    protected function statusOptions(): array
    {
        return TechnicalReport::query()->whereNotNull('service_status')->distinct()->orderBy('service_status')->pluck('service_status')->toArray();
    }

    protected function title(): string
    {
        return 'Technical Reports';
    }

    protected function description(): string
    {
        return 'Technical service reports imported from the Executive Dashboard workbook.';
    }
}
