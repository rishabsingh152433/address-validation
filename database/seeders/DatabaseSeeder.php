<?php

namespace Database\Seeders;

use App\Models\Tenant;
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
        Tenant::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Default Tenant',
                'slug' => 'default',
            ]
        );

        $this->call([
            MasterAddressSeeder::class,
            NormalizedFromMasterSeeder::class,
        ]);
    }
}