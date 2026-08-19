@php
    $faqs = [
        ['q' => 'How do I import records?', 'a' => 'Open Records table, choose Import file, then upload a CSV or Excel file with a header row. The first row becomes the table columns and each following row becomes a record.'],
        ['q' => 'Can I add fields after importing?', 'a' => 'Yes. Use Add column above the records table, choose a name and type, and the new field will be available on every current record.'],
        ['q' => 'How do I update a record status?', 'a' => 'Open a record from its ticket ID or the arrow at the end of its row. Select the new status in the detail panel.'],
        ['q' => 'How do labels work?', 'a' => 'Existing labels can be toggled from a record detail panel. You can also create a new label there and assign it to the current record.'],
        ['q' => 'Where can I review personnel performance?', 'a' => 'TSP Analytics summarizes active personnel, open records, resolution rate, response time, and regional coverage.'],
    ];
@endphp

<div class="max-w-4xl space-y-5">
    <x-admin.page-header
        eyebrow="Documentation"
        title="Help center"
        description="Short answers for the core record and personnel workflows."
    />

    <section class="admin-surface divide-y divide-base-300">
        @foreach ($faqs as $faq)
            <details class="group px-5 py-4 first:pt-5 last:pb-5 sm:px-6">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-base-content">
                    {{ $faq['q'] }}
                    <x-mary-icon name="o-chevron-down" class="h-4 w-4 shrink-0 text-base-content/45 transition group-open:rotate-180" />
                </summary>
                <p class="max-w-3xl pt-3 text-sm leading-6 text-base-content/60">{{ $faq['a'] }}</p>
            </details>
        @endforeach
    </section>
</div>
