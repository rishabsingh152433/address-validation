<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MasterAddress;
use App\Services\AddressValidationService;

class NormalizedFromMasterSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1; // same tenant
        /** @var AddressValidationService $svc */
        $svc = app(AddressValidationService::class);

        // aap chaaho to where tenant_id bhi laga do
        $masters = MasterAddress::where('tenant_id', $tenantId)->get();

        foreach ($masters as $m) {
            // IMPORTANT: raw ko exactly formatted_address pass karo
            // taki master LIKE fallback guaranteed hit ho
            $svc->validate($m->formatted_address, $tenantId, null);
        }
    }
}
