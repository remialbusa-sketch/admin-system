<?php
use App\Models\TechnicalReport;
$t = TechnicalReport::whereNotNull('tsp_name')->where('tsp_name','<>','')->first();
echo "TR attributes: ".json_encode(array_keys($t->getAttributes()))."\n";
echo "sample: ".json_encode([
  'tsp_name'=>$t->tsp_name,
  'service_request_id'=>$t->service_request_id,
  'branch'=>$t->brand,
  'service_started_at'=>(string)$t->service_started_at,
  'repair_time_hours'=>$t->repair_time_hours,
  'service_status'=>$t->service_status,
])."\n";
echo "distinct tsp_name: ".TechnicalReport::whereNotNull('tsp_name')->where('tsp_name','<>','')->distinct()->count('tsp_name')."\n";
echo "tr with service_request_id: ".TechnicalReport::whereNotNull('service_request_id')->count()." / ".TechnicalReport::count()."\n";
echo "TR repair_time_hours non-null: ".TechnicalReport::whereNotNull('repair_time_hours')->count()."\n";
