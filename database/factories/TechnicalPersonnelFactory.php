<?php

namespace Database\Factories;

use App\Models\TechnicalPersonnel;
use Illuminate\Database\Eloquent\Factories\Factory;

class TechnicalPersonnelFactory extends Factory
{
    protected $model = TechnicalPersonnel::class;

    public function definition(): array
    {
        return [
            'source_system' => 'personnel_list',
            'source_record_id' => 'personnel-'.$this->faker->unique()->uuid,
            'name' => $this->faker->name,
            'position' => $this->faker->randomElement(['Field Service Engineer', 'Service Coordinator', 'IT Specialist']),
            'branch' => $this->faker->randomElement(['NCR', 'Cebu', 'Davao']),
            'region' => 'NCR',
        ];
    }

    public function superadmin(): static
    {
        return $this->state(fn () => ['role' => \App\Enums\UserRole::Superadmin]);
    }
}
