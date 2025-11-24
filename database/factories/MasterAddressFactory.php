<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MasterAddress>
 */
class MasterAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'tenant_id' => Tenant::factory(), // auto create tenant
            'raw_address' => $this->faker->address,
            'normalized_id' => null,
            'latitude' => $this->faker->latitude,
            'longitude' => $this->faker->longitude,
            'validation_status' => $this->faker->randomElement(['pending', 'valid', 'invalid']),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
