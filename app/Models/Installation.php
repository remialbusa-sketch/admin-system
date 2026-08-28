<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installation extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'import_batch_id',
        'source_system',
        'source_record_id',
        'source_hash',
        'source_updated_at',
        'device_description',
        'brand',
        'machine_type',
        'serial_number',
        'bu_no',
        'equipment_type',
        'installation_date',
        'uninstallation_date',
        'device_status',
        'device_ownership',
        'deal_type',
        'charge_to',
        'warranty_status',
        'warranty_period_years',
        'pms_frequency',
        'tsp_in_charge',
        'warranty_end_date',
        'service_contract_status',
        'service_contract_amount',
        'service_contract_start',
        'service_contract_end',
        'annual_bu_charge',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'installation_date' => 'date',
            'uninstallation_date' => 'date',
            'warranty_end_date' => 'date',
            'warranty_period_years' => 'integer',
            'service_contract_start' => 'date',
            'service_contract_end' => 'date',
            'service_contract_amount' => 'decimal:2',
            'annual_bu_charge' => 'decimal:2',
            'raw_data' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
