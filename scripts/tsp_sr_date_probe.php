<?php
use App\Models\ServiceRequest;
use App\Models\TechnicalReport;

$srRaw = ServiceRequest::whereNotNull('raw_data')->first()->raw_data;
echo "SR raw date-ish keys: ".json_encode(array_filter(array_keys($srRaw), fn($c)=> str_contains(strtolower($c),'date') || str_contains(strtolower($c),'time')))."\n";
foreach (array_filter(array_keys($srRaw), fn($c)=> str_contains(strtolower($c),'date') || str_contains(strtolower($c),'time')) as $k) {
    echo "  $k => ".(is_scalar($srRaw[$k])? $srRaw[$k] : json_encode($srRaw[$k]))."\n";
}
echo "TR service_start_date_time cast type: ".gettype(TechnicalReport::first()->service_started_at)." val: ".json_encode(TechnicalReport::first()->service_started_at)."\n";
echo "TR count service_started_at non-null: ".TechnicalReport::whereNotNull('service_started_at')->count()."\n";
echo "TR count service_completed_at non-null: ".TechnicalReport::whereNotNull('service_completed_at')->count()."\n";
