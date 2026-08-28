<?php

namespace App\Livewire;

use App\Models\TechnicalReport;
use Illuminate\Database\Eloquent\Builder;

class TechnicalReportTable extends ManagedTable
{
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
            ->when($this->statusFilter !== null && $this->statusFilter !== 'All statuses', fn ($query) => $query->where('service_status', $this->statusFilter));
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
            ['key' => 'tsp_name', 'label' => 'TSP Name', 'type' => 'text'],
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
