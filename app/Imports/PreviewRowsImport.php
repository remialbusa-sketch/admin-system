<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PreviewRowsImport implements ToArray, WithHeadingRow
{
    public function array(array $array): void {}
}
