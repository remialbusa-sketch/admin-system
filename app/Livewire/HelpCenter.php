<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class HelpCenter extends Component
{
    public string $search = '';

    public function render(): View
    {
        $faqs = [
            ['q' => 'How do I import a table?', 'a' => 'Open the target table, click Import table, and upload the Excel/CSV. The wizard previews the sheet, then you hand-map each app field to a source column before the official import runs. Your mapping is remembered for the next import.'],
            ['q' => 'Which tables are available?', 'a' => 'Product Database (PDB), Service Requests, Technical Reports, History Reports (MCBTSi TSMS), and Technical Personnel. They are the single source for dashboards and visualization.'],
            ['q' => 'Who can edit table rows?', 'a' => 'It depends on your permission level. Viewers read only; Editors can edit records in the grid; Admins can also run imports. Superadmins always have full access. A Superadmin sets these in Users.'],
            ['q' => 'What roles are available?', 'a' => 'President, VP Operations, National Manager, Regional Manager, Service Coordinator, Assistant Coordinator, and Assistant — each with a permission level (Viewer / Editor / Admin) chosen at account creation.'],
            ['q' => 'How do I navigate the grid?', 'a' => 'Drag headers to reorder, drag edges to resize, right-click a header to freeze or hide it, and use the density selector (Condensed/Standard/Comfortable) above the grid. Column layouts are saved per account.'],
            ['q' => 'How current is the data?', 'a' => 'Every number comes from the imported source tables. The freshness chip in the header (and the sidebar footer) shows when the last import completed.'],
            ['q' => 'Where can I review service analytics?', 'a' => 'Technical Service Analysis summarizes completed reports, TSP workload, and brand patterns. TSP Analytics covers personnel performance.'],
        ];

        $term = mb_strtolower(trim($this->search));
        $filtered = $term === ''
            ? $faqs
            : array_values(array_filter(
                $faqs,
                fn (array $faq): bool => str_contains(mb_strtolower($faq['q'].' '.$faq['a']), $term),
            ));

        return view('livewire.help-center', ['faqs' => $filtered, 'faqTotal' => count($faqs)])
            ->layout('layouts.dashboard')
            ->title('Help Center');
    }
}
