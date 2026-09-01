MCBTSi OPERATIONS DIGEST — {{ $scope }}
Week of {{ now()->format('M j, Y') }}

SERVICE OPERATIONS
- Service requests: {{ number_format($ops['total_requests']) }} total · {{ number_format($ops['open_requests']) }} open
- Completion rate: {{ $ops['completion_rate'] }}%

INSTALLED BASE (PRODUCT DATABASE)
- Installed products: {{ number_format($products['installed']) }} · {{ number_format($products['active']) }} active
- Warranty covered: {{ number_format($products['warranty_covered']) }}
- Warranties expiring within 90 days: {{ number_format($products['warranty_expiring_90d']) }}

REGIONS (open service requests)
@foreach ($regions as $region)
- {{ $region['region'] }}: {{ number_format($region['open']) }} open
@endforeach

Data as of {{ $freshness }}. All figures computed from the imported source tables:
{{ url('/dashboard') }}
