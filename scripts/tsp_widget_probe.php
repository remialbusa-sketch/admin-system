<?php
use App\Models\TechnicalReport;
use App\Models\ServiceRequest;

echo "TR tsp_name non-null: ".TechnicalReport::whereNotNull('tsp_name')->where('tsp_name','<>','')->count()." / ".TechnicalReport::count()."\n";
echo "TR sample tsp_names: ".json_encode(TechnicalReport::whereNotNull('tsp_name')->where('tsp_name','<>','')->distinct()->limit(8)->pluck('tsp_name')->all())."\n";
echo "TR service_completed_at range: ".optional(TechnicalReport::min('service_completed_at'))->format('Y-m-d')." .. ".optional(TechnicalReport::max('service_completed_at'))->format('Y-m-d')."\n";
echo "SR tsp_assignment non-null: ".ServiceRequest::whereNotNull('tsp_assignment')->where('tsp_assignment','<>','')->count()." / ".ServiceRequest::count()."\n";
echo "SR branch distinct: ".json_encode(ServiceRequest::whereNotNull('branch')->where('branch','<>','')->distinct()->pluck('branch')->all())."\n";
echo "SR created_at range: ".optional(ServiceRequest::min('created_at'))->format('Y-m-d')." .. ".optional(ServiceRequest::max('created_at'))->format('Y-m-d')."\n";
echo "TR has created_at? ".json_encode(TechnicalReport::first()->created_at)."\n";
echo "TR report_date? ".json_encode(TechnicalReport::first()->report_date ?? 'none')."\n";
