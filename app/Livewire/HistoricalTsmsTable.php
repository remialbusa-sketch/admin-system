<?php

namespace App\Livewire;

use App\Models\HistoricalTsmsReport;
use Illuminate\Database\Eloquent\Builder;

class HistoricalTsmsTable extends ManagedTable
{
    public function tableKey(): string
    {
        return 'history-reports';
    }

    protected function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:100'],
            'tsp_name' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'job_done' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function query(): Builder
    {
        return HistoricalTsmsReport::query()
            ->when($this->search !== '', function ($query): void {
                $like = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('account_name', 'like', $like)
                        ->orWhere('csr_number', 'like', $like)
                        ->orWhere('tsr_number', 'like', $like)
                        ->orWhere('tsp_name', 'like', $like);
                });
            })
            ->when($this->statusFilter !== null && $this->statusFilter !== 'All statuses', fn ($query) => $query->where('status', $this->statusFilter));
    }

    public function model(): string
    {
        return HistoricalTsmsReport::class;
    }

    public function columns(): array
    {
        return [
            ['key' => 'csr_number', 'label' => 'CSR #', 'type' => 'text'],
            ['key' => 'account_name', 'label' => 'Account Name', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $this->statusOptions()],
            ['key' => 'tsp_name', 'label' => 'TSP Name', 'type' => 'text'],
            ['key' => 'branch', 'label' => 'Branch', 'type' => 'text'],
            ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
            ['key' => 'serial_number', 'label' => 'Serial Number', 'type' => 'text'],
            ['key' => 'problem_or_complaint', 'label' => 'Problem / Complaint', 'type' => 'text'],
            ['key' => 'job_done', 'label' => 'Job Done', 'type' => 'text'],
            ['key' => 'service_at', 'label' => 'Service Time', 'type' => 'date'],
        ];
    }

    protected function statusOptions(): array
    {
        return HistoricalTsmsReport::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status')->toArray();
    }

    protected function title(): string
    {
        return 'History Reports';
    }

    protected function description(): string
    {
        return 'Historical service records from the MCBTSi TSMS (Responses) workbook.';
    }
}
