<?php

namespace App\Livewire;

use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Builder;

class ServiceRequestTable extends ManagedTable
{
    public function tableKey(): string
    {
        return 'service-requests';
    }

    protected function rules(): array
    {
        return [
            'ticket_status' => ['nullable', 'string', 'max:100'],
            'group_status' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'tsp_assignment' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'service_type' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'concerns' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function query(): Builder
    {
        return ServiceRequest::query()
            ->when($this->search !== '', function ($query): void {
                $like = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('customer_name', 'like', $like)
                        ->orWhere('service_request_code', 'like', $like)
                        ->orWhere('serial_number', 'like', $like)
                        ->orWhere('brand', 'like', $like);
                });
            })
            ->when($this->statusFilter !== null && $this->statusFilter !== 'All statuses', fn ($query) => $query->where('ticket_status', $this->statusFilter));
    }

    public function model(): string
    {
        return ServiceRequest::class;
    }

    public function columns(): array
    {
        return [
            ['key' => 'service_request_code', 'label' => 'Service Request #', 'type' => 'text'],
            ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text'],
            ['key' => 'ticket_status', 'label' => 'Ticket Status', 'type' => 'select', 'options' => $this->statusOptions()],
            ['key' => 'group_status', 'label' => 'Group Status', 'type' => 'text'],
            ['key' => 'branch', 'label' => 'Branch', 'type' => 'text'],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text'],
            ['key' => 'tsp_assignment', 'label' => 'TSP Assignment', 'type' => 'text'],
            ['key' => 'service_type', 'label' => 'Service Type', 'type' => 'text'],
            ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
            ['key' => 'serial_number', 'label' => 'Serial Number', 'type' => 'text'],
            ['key' => 'concerns', 'label' => 'Concerns', 'type' => 'text'],
            ['key' => 'date_needed', 'label' => 'Date Needed', 'type' => 'date'],
        ];
    }

    protected function statusOptions(): array
    {
        return ServiceRequest::query()->whereNotNull('ticket_status')->distinct()->orderBy('ticket_status')->pluck('ticket_status')->toArray();
    }

    protected function title(): string
    {
        return 'Service Requests';
    }

    protected function description(): string
    {
        return 'Current service requests imported from the Executive Dashboard workbook.';
    }
}
