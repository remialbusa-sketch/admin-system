<?php

namespace App\Livewire;

use App\Imports\RecordsImport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

class RecordsTable extends Component
{
    use WithFileUploads;

    public string $search = '';

    public string $statusFilter = 'All statuses';

    public string $labelFilter = 'All labels';

    public string $newColumnName = '';

    public string $newColumnType = 'text';

    public string $newLabelName = '';

    public string $newLabelTone = 'info';

    public string $selectionField = '';

    public string $selectionName = '';

    public string $importedFileName = 'Sample records';

    public array $columns = [];

    public array $records = [];

    public array $labels = [];

    public array $statuses = ['New', 'In progress', 'Blocked', 'Resolved'];

    public array $priorityOptions = ['High', 'Medium', 'Low'];

    public $importFile = null;

    public ?int $selectedRecordId = null;

    public function mount(): void
    {
        $this->labels = [
            'Escalated' => 'danger',
            'Preventive' => 'info',
            'Remote' => 'neutral',
            'Priority' => 'warning',
        ];

        $this->columns = [
            ['key' => 'ticket_id', 'label' => 'Ticket ID', 'type' => 'text'],
            ['key' => 'technician', 'label' => 'Technician', 'type' => 'text'],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text'],
            ['key' => 'priority', 'label' => 'Priority', 'type' => 'status'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ['key' => 'updated', 'label' => 'Last updated', 'type' => 'date'],
        ];

        $this->records = [
            [
                'id' => 1,
                'ticket_id' => 'SR-1048',
                'technician' => 'A. Reyes',
                'region' => 'North Luzon',
                'priority' => 'High',
                'status' => 'In progress',
                'updated' => 'Today, 09:42',
                'labels' => ['Escalated', 'Remote'],
                'notes' => 'Replacement unit is queued for the next field visit.',
            ],
            [
                'id' => 2,
                'ticket_id' => 'SR-1047',
                'technician' => 'M. Santos',
                'region' => 'NCR',
                'priority' => 'Medium',
                'status' => 'New',
                'updated' => 'Today, 08:18',
                'labels' => ['Preventive'],
                'notes' => 'Initial assessment is scheduled with the site contact.',
            ],
            [
                'id' => 3,
                'ticket_id' => 'SR-1046',
                'technician' => 'J. Dela Cruz',
                'region' => 'Visayas',
                'priority' => 'Low',
                'status' => 'Resolved',
                'updated' => 'Yesterday, 16:05',
                'labels' => ['Remote'],
                'notes' => 'Issue was resolved remotely after a firmware update.',
            ],
            [
                'id' => 4,
                'ticket_id' => 'SR-1045',
                'technician' => 'C. Villanueva',
                'region' => 'Mindanao',
                'priority' => 'High',
                'status' => 'Blocked',
                'updated' => 'Yesterday, 14:27',
                'labels' => ['Priority'],
                'notes' => 'Awaiting access approval before the next diagnostic step.',
            ],
            [
                'id' => 5,
                'ticket_id' => 'SR-1044',
                'technician' => 'R. Navarro',
                'region' => 'NCR',
                'priority' => 'Medium',
                'status' => 'In progress',
                'updated' => 'Yesterday, 11:54',
                'labels' => ['Preventive', 'Remote'],
                'notes' => 'Follow-up visit is being coordinated with the client.',
            ],
        ];
    }

    public function importRecords(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $rows = $this->readImportRows();
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('importFile', 'The file could not be read. Check that it has a header row and try again.');

            return;
        }

        if ($rows === []) {
            $this->addError('importFile', 'The file does not contain any records.');

            return;
        }

        $this->records = $this->normalizeRows($rows);
        $this->importedFileName = $this->importFile->getClientOriginalName();
        $this->selectedRecordId = null;
        $this->importFile = null;

        $this->dispatch('close-drawer', name: 'import-records');
    }

    public function addColumn(): void
    {
        $this->validate([
            'newColumnName' => 'required|string|max:50',
            'newColumnType' => 'required|in:text,status,date,number',
        ]);

        $key = $this->normalizeKey($this->newColumnName);

        if ($key === '' || in_array($key, ['id', 'labels', 'notes'], true) || collect($this->columns)->pluck('key')->contains($key)) {
            $this->addError('newColumnName', 'A column with this name already exists.');

            return;
        }

        $this->columns[] = [
            'key' => $key,
            'label' => Str::of($this->newColumnName)->trim()->title()->toString(),
            'type' => $this->newColumnType,
        ];

        foreach ($this->records as &$record) {
            $record[$key] = '';
        }
        unset($record);

        $this->reset(['newColumnName', 'newColumnType']);
        $this->newColumnType = 'text';
        $this->dispatch('close-modal', name: 'add-column');
    }

    public function openRecord(int $recordId): void
    {
        $this->selectedRecordId = $recordId;
        $this->dispatch('open-drawer', name: 'record-details');
    }

    public function updateSelectedStatus(string $status): void
    {
        $this->updateSelectedField('status', $status);
    }

    public function handleFieldSelection(string $field, string $value): void
    {
        if ($value === '__add_selection__') {
            $column = collect($this->columns)->firstWhere('key', $field);

            if (! $column || $this->fieldOptions($column) === []) {
                return;
            }

            $this->selectionField = $field;
            $this->selectionName = '';
            $this->resetValidation('selectionName');
            $this->dispatch('open-modal', name: 'add-selection');

            return;
        }

        $this->updateSelectedField($field, $value);
    }

    public function addSelectionOption(): void
    {
        $this->validate([
            'selectionName' => 'required|string|max:40',
        ]);

        $column = collect($this->columns)->firstWhere('key', $this->selectionField);

        if (! $column) {
            $this->addError('selectionName', 'Select a field before adding an option.');

            return;
        }

        $name = Str::of($this->selectionName)->trim()->toString();
        $options = $this->fieldOptions($column);

        if ($name === '' || in_array($name, $options, true)) {
            $this->addError('selectionName', 'This selection already exists.');

            return;
        }

        if ($column['key'] === 'priority') {
            $this->priorityOptions[] = $name;
        } else {
            $this->statuses[] = $name;
        }

        $this->updateSelectedField($this->selectionField, $name);
        $this->reset(['selectionField', 'selectionName']);
        $this->dispatch('close-modal', name: 'add-selection');
    }

    public function updateSelectedField(string $field, string $value): void
    {
        $column = collect($this->columns)->firstWhere('key', $field);

        if ($field === 'notes') {
            $column = ['key' => 'notes', 'type' => 'text'];
        }

        if (! $column || ! $this->selectedRecordId || in_array($field, ['id', 'labels'], true)) {
            return;
        }

        $options = $this->fieldOptions($column);

        if ($options !== [] && $value !== '' && ! in_array($value, $options, true)) {
            return;
        }

        foreach ($this->records as &$record) {
            if ($record['id'] === $this->selectedRecordId) {
                $record[$field] = $value;

                if ($field !== 'updated' && array_key_exists('updated', $record)) {
                    $record['updated'] = 'Just now';
                }
                break;
            }
        }
        unset($record);
    }

    public function addLabelToSelected(): void
    {
        $this->validate([
            'newLabelName' => 'required|string|max:30',
            'newLabelTone' => 'required|in:neutral,info,success,warning,danger',
        ]);

        $label = Str::of($this->newLabelName)->trim()->toString();

        if ($label === '') {
            return;
        }

        $this->labels[$label] = $this->newLabelTone;

        foreach ($this->records as &$record) {
            if ($record['id'] === $this->selectedRecordId && ! in_array($label, $record['labels'] ?? [], true)) {
                $record['labels'][] = $label;
                break;
            }
        }
        unset($record);

        $this->reset(['newLabelName', 'newLabelTone']);
        $this->newLabelTone = 'info';
    }

    public function toggleLabel(string $label): void
    {
        foreach ($this->records as &$record) {
            if ($record['id'] !== $this->selectedRecordId) {
                continue;
            }

            $record['labels'] ??= [];

            if (in_array($label, $record['labels'], true)) {
                $record['labels'] = array_values(array_diff($record['labels'], [$label]));
            } else {
                $record['labels'][] = $label;
            }
            break;
        }
        unset($record);
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'labelFilter']);
        $this->statusFilter = 'All statuses';
        $this->labelFilter = 'All labels';
    }

    public function render(): View
    {
        $records = collect($this->records)
            ->filter(function (array $record): bool {
                $matchesSearch = $this->search === '' || str_contains(
                    strtolower(json_encode($record, JSON_THROW_ON_ERROR)),
                    strtolower($this->search),
                );

                $matchesStatus = $this->statusFilter === 'All statuses' || ($record['status'] ?? '') === $this->statusFilter;
                $matchesLabel = $this->labelFilter === 'All labels' || in_array($this->labelFilter, $record['labels'] ?? [], true);

                return $matchesSearch && $matchesStatus && $matchesLabel;
            })
            ->values()
            ->all();

        $selectedRecord = collect($this->records)->firstWhere('id', $this->selectedRecordId);

        return view('livewire.records-table', [
            'filteredRecords' => $records,
            'selectedRecord' => $selectedRecord,
            'fieldOptions' => collect($this->columns)
                ->mapWithKeys(fn (array $column): array => [$column['key'] => $this->fieldOptions($column)])
                ->all(),
        ])
            ->layout('layouts.dashboard')
            ->title('Records table');
    }

    /**
     * Parse CSV directly and use Laravel Excel for spreadsheet formats.
     */
    private function readImportRows(): array
    {
        $extension = strtolower($this->importFile->getClientOriginalExtension());

        if (in_array($extension, ['csv', 'txt'], true)) {
            $handle = fopen($this->importFile->getRealPath(), 'rb');
            $headers = $handle ? fgetcsv($handle) : false;
            $rows = [];

            if ($handle && is_array($headers)) {
                while (($row = fgetcsv($handle)) !== false) {
                    if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                        continue;
                    }

                    $rows[] = array_combine(
                        array_map(fn ($header) => $this->normalizeKey((string) $header), $headers),
                        array_slice(array_pad($row, count($headers), ''), 0, count($headers)),
                    );
                }
                fclose($handle);
            }

            return $rows;
        }

        return Excel::toArray(new RecordsImport, $this->importFile)[0] ?? [];
    }

    private function normalizeRows(array $rows): array
    {
        $sourceKeys = collect($rows)
            ->flatMap(fn (array $row) => array_keys($row))
            ->map(fn ($key) => $this->normalizeKey((string) $key))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($sourceKeys === []) {
            return [];
        }

        $this->columns = collect($sourceKeys)
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => Str::of($key)->replace('_', ' ')->title()->toString(),
                'type' => in_array($key, ['status', 'priority'], true) ? 'status' : 'text',
            ])
            ->all();

        return collect($rows)
            ->values()
            ->map(function (array $row, int $index): array {
                $normalized = [];

                foreach ($this->columns as $column) {
                    $normalized[$column['key']] = $row[$column['key']] ?? $row[$column['label']] ?? '';
                }

                $normalized['id'] = $index + 1;
                $normalized['labels'] = [];
                $normalized['notes'] = 'Imported record. Add notes from the detail panel.';

                return $normalized;
            })
            ->all();
    }

    private function normalizeKey(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/u', '$1_$2', trim($value)) ?? trim($value);
        $value = preg_replace('/[^a-zA-Z0-9]+/u', '_', $value) ?? $value;

        return strtolower(trim($value, '_'));
    }

    private function fieldOptions(array $column): array
    {
        return match ($column['key']) {
            'priority' => $this->priorityOptions,
            default => $column['type'] === 'status' ? $this->statuses : [],
        };
    }
}
