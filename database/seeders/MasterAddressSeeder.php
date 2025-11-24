<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\MasterAddress;

class MasterAddressSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $tenant = Tenant::firstOrCreate(
            ['id' => 1],
            ['name' => 'Default Tenant', 'slug' => 'default']
        );
    }
}
