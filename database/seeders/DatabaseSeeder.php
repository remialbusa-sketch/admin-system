<?php

namespace Database\Seeders;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $testUser = User::factory()->superadmin()->create([
            'name' => 'Admin System Test User',
            'email' => 'test@example.com',
            'password' => 'Password!123',
        ]);

        User::factory()->president()->create([
            'name' => 'Admin System President',
            'email' => 'president@example.com',
        ]);

        User::factory()->regionalManager('NCR')->create([
            'name' => 'NCR Regional Manager',
            'email' => 'regional@example.com',
        ]);

        ImportBatch::factory()->create([
            'source_system' => 'product_database',
            'source_name' => 'MCBTSi PRODUCT DATABASE.xlsx',
            'source_sheet' => 'PDB Data',
            'status' => 'completed',
            'total_rows' => 0,
            'processed_rows' => 0,
        ]);

        User::factory(3)->regionalManager()->create();
    }
}
