<?php

namespace App\Livewire;

use App\Models\HistoricalTsmsReport;
use App\Models\TechnicalPersonnel;
use App\Models\ImportBatch;
use App\Models\Installation;
use App\Models\RecordEditLog;
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TablesList extends Component
{
    public function render(): View
    {
        $tables = [
            [
                'route' => 'installed-products',
                'label' => 'Product Database',
                'description' => 'Product database from PDB workbook',
                'icon' => 'o-cube',
                'count' => Installation::query()->count(),
                'source' => 'MCBTSi PRODUCT DATABASE.xlsx',
            ],
            [
                'route' => 'service-requests',
                'label' => 'Service Requests',
                'description' => 'Current requests from Executive Dashboard',
                'icon' => 'o-inbox-stack',
                'count' => ServiceRequest::query()->count(),
                'source' => 'MCBTSI_Executive_Dashboard_Updated.xlsx',
            ],
            [
                'route' => 'technical-reports',
                'label' => 'Technical Reports',
                'description' => 'Technical reports from Executive Dashboard',
                'icon' => 'o-document-text',
                'count' => TechnicalReport::query()->count(),
                'source' => 'MCBTSI_Executive_Dashboard_Updated.xlsx',
            ],
            [
                'route' => 'history-reports',
                'label' => 'History Reports',
                'description' => 'Historical TSMS responses',
                'icon' => 'o-archive-box',
                'count' => HistoricalTsmsReport::query()->count(),
                'source' => 'MCBTSi TSMS (Responses).xlsx',
            ],
            [
                'route' => 'personnel',
                'label' => 'Technical Personnel',
                'description' => 'Company technical personnel',
                'icon' => 'o-user-group',
                'count' => TechnicalPersonnel::query()->count(),
                'source' => 'Personnel list_.xlsx',
            ],
        ];

        $imports = ImportBatch::query()->latest()->limit(10)->get();
        $recentEdits = RecordEditLog::query()->with('user:id,name')->latest()->limit(12)->get();

        return view('livewire.tables-list', compact('tables', 'imports', 'recentEdits'))
            ->layout('layouts.dashboard')
            ->title('Tables');
    }
}
