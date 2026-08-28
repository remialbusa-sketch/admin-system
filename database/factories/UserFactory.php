<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::RegionalManager->value,
            'region' => fake()->randomElement(['NCR', 'North Luzon', 'Visayas', 'Mindanao']),
        ];
    }

    public function president(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::President->value,
            'region' => null,
        ]);
    }

    public function superadmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Superadmin->value,
            'region' => null,
        ]);
    }

    public function vpOperations(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::VpOperations->value,
            'region' => null,
        ]);
    }

    public function nationalManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::NationalManager->value,
            'region' => null,
        ]);
    }

    public function regionalManager(string $region = 'NCR'): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::RegionalManager->value,
            'region' => $region,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
