<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MasterAddress;
use App\Services\AddressValidationService;

class NormalizedFromMasterSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1;

        /** @var AddressValidationService $svc */
        $svc = app(AddressValidationService::class);

        $masters = MasterAddress::where('tenant_id', $tenantId)->get();

        foreach ($masters as $m) {
            $svc->validate($m->formatted_address, $tenantId, null);
        }
    }
}