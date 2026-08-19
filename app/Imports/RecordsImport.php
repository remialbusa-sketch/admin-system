<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class RecordsImport implements ToArray, WithHeadingRow
{
    /**
     * The Excel facade returns the parsed sheets from toArray().
     */
    public function array(array $array): void
    {
        // The rows are consumed from Excel::toArray() in the Livewire component.
    }
}
