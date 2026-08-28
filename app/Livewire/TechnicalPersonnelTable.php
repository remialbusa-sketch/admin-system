<?php

namespace App\Livewire;

use App\Models\TechnicalPersonnel;
use Illuminate\Database\Eloquent\Builder;

class TechnicalPersonnelTable extends ManagedTable
{
    public function tableKey(): string
    {
        return 'personnel';
    }

    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function query(): Builder
    {
        return TechnicalPersonnel::query()
            ->when($this->search !== '', function ($query): void {
                $like = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('position', 'like', $like)
                        ->orWhere('branch', 'like', $like);
                });
            })
            ->when($this->statusFilter !== null && $this->statusFilter !== 'All statuses', fn ($query) => $query->where('branch', $this->statusFilter));
    }

    public function model(): string
    {
        return TechnicalPersonnel::class;
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'position', 'label' => 'Position', 'type' => 'text'],
            ['key' => 'branch', 'label' => 'Branch', 'type' => 'select', 'options' => $this->statusOptions()],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text'],
        ];
    }

    protected function statusOptions(): array
    {
        return TechnicalPersonnel::query()->whereNotNull('branch')->distinct()->orderBy('branch')->pluck('branch')->toArray();
    }

    protected function title(): string
    {
        return 'Technical Personnel';
    }

    protected function description(): string
    {
        return 'Company technical personnel imported from the Personnel list workbook.';
    }
}
