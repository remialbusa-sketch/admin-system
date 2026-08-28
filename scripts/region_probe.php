<?php
use App\Models\ServiceRequest;
use App\Models\TechnicalPersonnel;

echo "SR branch counts:\n";
foreach (ServiceRequest::whereNotNull('branch')->where('branch','<>','')->get()->groupBy('branch') as $b => $g) {
    echo "  {$b}: {$g->count()}\n";
}
echo "Personnel region values: ".json_encode(TechnicalPersonnel::whereNotNull('region')->distinct()->pluck('region')->all())."\n";
echo "Personnel branch->region sample:\n";
foreach (TechnicalPersonnel::whereNotNull('branch')->get()->groupBy('branch') as $b => $g) {
    echo "  {$b} => ".$g->first()->region."\n";
}
