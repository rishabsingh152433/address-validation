<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Tenant;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class LmtHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'tenant_id' => Tenant::factory(), // auto create tenant if not passed
            'imported_file' => $this->faker->lexify('lmt_data_????.csv'),
            'status' => $this->faker->randomElement(['pending', 'completed', 'failed']),
            'records_imported' => $this->faker->numberBetween(0, 5000),
            'error_count' => $this->faker->numberBetween(0, 200),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
