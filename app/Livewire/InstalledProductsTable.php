<?php

namespace App\Livewire;

use App\Models\Installation;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class InstalledProductsTable extends ManagedTable
{
    public string $branchFilter = 'All branches';

    /**
     * Dashboard drill-down filters. Each is bound to a URL query parameter so
     * the executive widgets deep-link straight to the source rows:
     *   ?brand= / ?machine_type= / ?region= / ?customer= / ?warranty=
     *   ?pms=missing / ?contract=1 / ?installed=YYYY-MM / ?status= (base class)
     */
    #[Url]
    public ?string $brand = null;

    #[Url(as: 'machine_type')]
    public ?string $machineType = null;

    #[Url]
    public ?string $region = null;

    #[Url]
    public ?string $customer = null;

    #[Url]
    public ?string $warranty = null;

    #[Url]
    public ?string $pms = null;

    #[Url]
    public ?string $installed = null;

    #[Url]
    public ?string $contract = null;

    public function tableKey(): string
    {
        return 'installed-products';
    }

    public function mount(): void
    {
        // Deep-linked ?status=… is hydrated before mount — keep it; default
        // to the sentinel only when no drill-down is present.
        if ($this->statusFilter === null) {
            $this->statusFilter = 'All statuses';
        }
    }

    public function updatedBranchFilter(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'account.customer_name' => ['nullable', 'string', 'max:255'],
            'account.customer_address' => ['nullable', 'string', 'max:2000'],
            'account.branch' => ['nullable', 'string', 'max:100'],
            'device_description' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'bu_no' => ['nullable', 'string', 'max:100'],
            'equipment_type' => ['nullable', 'string', 'max:100'],
            'installation_date' => ['nullable', 'date'],
            'uninstallation_date' => ['nullable', 'date'],
            'device_ownership' => ['nullable', 'string', 'max:100'],
            'device_status' => ['nullable', 'string', 'max:100'],
            'deal_type' => ['nullable', 'string', 'max:100'],
            'charge_to' => ['nullable', 'string', 'max:100'],
            'warranty_status' => ['nullable', Rule::in(['Yes', 'No'])],
            'warranty_period_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'pms_frequency' => ['nullable', Rule::in(['Monthly', 'Bi-monthly', 'Quarterly', 'Semi-annual', 'Annual'])],
            'tsp_in_charge' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function query(): Builder
    {
        return Installation::query()->with('account')
            ->when($this->search !== '', function ($query): void {
                $like = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('device_description', 'like', $like)
                        ->orWhere('brand', 'like', $like)
                        ->orWhere('serial_number', 'like', $like)
                        ->orWhereHas('account', fn ($query) => $query->where('customer_name', 'like', $like));
                });
            })
            ->when($this->statusFilter !== 'All statuses' && $this->statusFilter !== null, fn ($query) => $query->where('device_status', $this->statusFilter))
            ->when($this->branchFilter !== 'All branches', fn ($query) => $query->whereHas('account', fn ($query) => $query->where('branch', $this->branchFilter)))
            // Dashboard drill-down filters (URL-bound).
            ->when($this->brand !== null && $this->brand !== '', fn ($query) => $query->where('brand', 'like', '%'.$this->brand.'%'))
            ->when($this->machineType !== null && $this->machineType !== '', fn ($query) => $query->where('machine_type', $this->machineType))
            ->when($this->region !== null && $this->region !== '', fn ($query) => $query->whereHas('account', fn ($query) => $query->where('region', $this->region)))
            ->when($this->customer !== null && $this->customer !== '', fn ($query) => $query->whereHas('account', fn ($query) => $query->where('customer_name', 'like', '%'.$this->customer.'%')))
            ->when($this->warranty === 'covered', fn ($query) => $query->whereRaw("lower(trim(warranty_status)) = 'yes'"))
            ->when($this->warranty === 'expiring_90d', fn ($query) => $query->whereNotNull('warranty_end_date')
                ->whereDate('warranty_end_date', '>=', now()->toDateString())
                ->whereDate('warranty_end_date', '<=', now()->addDays(90)->toDateString()))
            ->when($this->warranty === 'expired', fn ($query) => $query->whereNotNull('warranty_end_date')
                ->whereDate('warranty_end_date', '<', now()->toDateString()))
            ->when($this->pms === 'missing', fn ($query) => $query->whereNull('pms_frequency'))
            ->when($this->contract !== null && $this->contract !== '', fn ($query) => $query->whereRaw("lower(trim(service_contract_status)) in ('yes', 'renewal')"))
            ->when($this->installed !== null && preg_match('/^\d{4}-\d{2}$/', $this->installed) === 1, function ($query): void {
                [$year, $month] = explode('-', $this->installed);
                $query->whereYear('installation_date', (int) $year)->whereMonth('installation_date', (int) $month);
            });
    }

    /**
     * Drill-down filters currently applied via URL, shown as chips above the
     * grid so the operator can see (and clear) why the table is narrowed.
     *
     * @return array<string, string>
     */
    public function drillDownFilters(): array
    {
        return array_filter([
            'Device status' => ($this->statusFilter !== null && $this->statusFilter !== 'All statuses') ? $this->statusFilter : null,
            'Brand' => $this->brand,
            'Machine type' => $this->machineType,
            'Region' => $this->region,
            'Customer' => $this->customer,
            'Warranty' => $this->warranty,
            'PMS' => $this->pms,
            'Contract' => $this->contract,
            'Installed month' => $this->installed,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function clearDrillDown(): void
    {
        $this->statusFilter = 'All statuses';
        $this->brand = null;
        $this->machineType = null;
        $this->region = null;
        $this->customer = null;
        $this->warranty = null;
        $this->pms = null;
        $this->contract = null;
        $this->installed = null;
        $this->resetPage();
    }

    public function model(): string
    {
        return Installation::class;
    }

    public function columns(): array
    {
        return [
            ['key' => 'account.customer_name', 'label' => 'Customer Name', 'type' => 'text'],
            ['key' => 'account.customer_address', 'label' => 'Customer Address', 'type' => 'text'],
            ['key' => 'account.branch', 'label' => 'Branch', 'type' => 'select', 'options' => ['North Luzon', 'NCR', 'South Luzon', 'Visayas', 'Mindanao']],
            ['key' => 'device_description', 'label' => 'Device Description', 'type' => 'text'],
            ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
            ['key' => 'serial_number', 'label' => 'Serial Number', 'type' => 'text'],
            ['key' => 'bu_no', 'label' => 'BU No.', 'type' => 'text'],
            ['key' => 'equipment_type', 'label' => 'Equipment Type', 'type' => 'select', 'options' => ['Stand Alone', 'Laptower set', 'Project based', 'UN-2000', 'UN-3000', 'UN-9000', 'XR-1500', 'XR-2000', 'XR-3000', 'XR-9000', 'XN-1500', 'XN-2000', 'XN-3000', 'XN-9000']],
            ['key' => 'installation_date', 'label' => 'Installation Date', 'type' => 'date'],
            ['key' => 'uninstallation_date', 'label' => 'Uninstallation Date', 'type' => 'date'],
            ['key' => 'device_ownership', 'label' => 'Device Ownership', 'type' => 'select', 'options' => ['BACTEFAST', 'CUSTOMER', 'DEALER', 'MGX', 'PRINCIPAL', 'TRACE', 'ZDI']],
            ['key' => 'device_status', 'label' => 'Device Status', 'type' => 'select', 'options' => ['Active', 'Inactive', 'Dysfunctional', 'Pulledout']],
            ['key' => 'deal_type', 'label' => 'Deal Type', 'type' => 'select', 'options' => ['Purchased', 'RTU', 'RTO', 'Demo', 'Service unit']],
            ['key' => 'charge_to', 'label' => 'Charge To', 'type' => 'select', 'options' => ['IVD', 'EMEDS1', 'EMEDS2', 'TRACE', 'BACTEFAST', 'CUSTOMER']],
            ['key' => 'warranty_status', 'label' => 'Warranty Status', 'type' => 'select', 'options' => ['Yes', 'No']],
            ['key' => 'warranty_period_years', 'label' => 'Warranty Period', 'type' => 'number'],
            ['key' => 'pms_frequency', 'label' => 'PMS Frequency', 'type' => 'select', 'options' => ['Monthly', 'Bi-monthly', 'Quarterly', 'Semi-annual', 'Annual']],
            ['key' => 'tsp_in_charge', 'label' => 'TSP In-charge', 'type' => 'select', 'options' => ['Adonis Ybanez', 'Ador Panimdim', 'Anfernee Digamon', 'Brixxton Basuel', 'Christopher Auditor', 'Daniel Igano', 'Dexter Lim', 'Elthon Jay Navares', 'Fidel Carino', 'Gary Walter Vivas', 'Gerald Ricafranca', 'Gio Sam Aguinaldo', 'Harvyn Honorica', 'Jefferson Yaranon', 'Jhon Carlo Faustino', 'Joey Nichols Tumaroy', 'John Erick Hernandez', 'Joshua Bautista', 'Joven Padon', 'Kerwin Pardillo', 'Keryl Pardillo', 'Lance Nichol Canaveral', 'Leander Fajardo', 'Mark Niel Amper', 'Mart Russel Santos', 'Neil Darwin San Juan', 'Nolybert Nacpil', 'Paolo Enriquez', 'Paul Anthony Genovate', 'Ricardo Navarro, Jr.', 'Roberto De Pio, Jr.', 'Roel Bagasbas', 'Rogel De Lara', 'Ruffy Pineda', 'Ruselio Franco Dagondon, Jr.', 'Sherwin Montellin', 'Soteri Zamora', 'Voltaire Vallarta', 'Warren Suba']],
        ];
    }

    protected function statusOptions(): array
    {
        return Installation::query()->whereNotNull('device_status')->distinct()->orderBy('device_status')->pluck('device_status')->toArray();
    }

    protected function title(): string
    {
        return 'Product Database';
    }

    protected function description(): string
    {
        return 'Review approved product-database fields imported from the PDB workbook. Raw workbook fields are preserved but hidden.';
    }
}
