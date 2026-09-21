<?php

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet;
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Service Requests');
$sheet->fromArray([
    ['Service Request No', 'Customer', 'Ticket Status', 'Brand'],
    ['SR-E2E-001', 'E2E Hospital', 'Open', 'FUJI CHEM'],
    ['SR-E2E-002', 'E2E Clinic', 'In-Progress', 'SYSMEX'],
], null, 'A1');

$path = __DIR__.'/fixtures/e2e-import.xlsx';
@mkdir(dirname($path), 0775, true);
(new Xlsx($spreadsheet))->save($path);

echo 'written '.$path.' ('.filesize($path).' bytes)'.PHP_EOL;
