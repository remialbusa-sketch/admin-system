<?php
use App\Models\TechnicalReport;
use App\Models\ServiceRequest;

$trCols = array_keys(TechnicalReport::first()->getAttributes());
echo "TR cols date-ish: ".json_encode(array_filter($trCols, fn($c)=> str_contains(strtolower($c),'date') || str_contains(strtolower($c),'time')))."\n";
$srCols = array_keys(ServiceRequest::first()->getAttributes());
echo "SR cols date-ish: ".json_encode(array_filter($srCols, fn($c)=> str_contains(strtolower($c),'date') || str_contains(strtolower($c),'time')))."\n";

$trRaw = TechnicalReport::whereNotNull('raw_data')->first()->raw_data;
echo "TR raw date-ish keys: ".json_encode(array_filter(array_keys($trRaw), fn($c)=> str_contains(strtolower($c),'date') || str_contains(strtolower($c),'time')))."\n";
foreach (array_filter(array_keys($trRaw), fn($c)=> str_contains(strtolower($c),'date') || str_contains(strtolower($c),'time')) as $k) {
    echo "  $k => ".(is_scalar($trRaw[$k])? $trRaw[$k] : json_encode($trRaw[$k]))."\n";
}
