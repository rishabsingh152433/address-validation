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

        //     MasterAddress::firstOrCreate(
        //     ['formatted_address' => 'Avenida Pedro de Valdivia 1215, Providencia'],
        //     [
        //         'google_lat' => -33.4265,
        //         'google_lng' => -70.6040,
        //         'source' => 'manual',
        //         'tenant_id' => $tenant->id,
        //         'validation_count' => 0,
        //         'is_trusted' => true,
        //         'concordance_level' => 0,
        //         'created_from_history' => true,
        //     ]
        // );

        $addresses = [
        ["address" => "7076 Addison Springs Suite 963 Lake Abraham, SC 46356-4533", "lat" => -33.387268, "lng" => -70.562438],
        ["address" => "932 Boyer Turnpike New Hiramland, ME 62038-4200", "lat" => -33.385428, "lng" => -70.565354],
        ["address" => "46448 Ludie Crossroad Kuvalisfurt, WI 77628", "lat" => -33.39887, "lng" => -70.576041],
        ["address" => "279 Yundt Ports Apt. 046 New Naomi, OH 53849-9310", "lat" => -33.404407, "lng" => -70.584729],
        ["address" => "393 Sonya Pike Apt. 418 Berenicechester, NV 41459-5662", "lat" => -33.384512, "lng" => -70.566304],
        ["address" => "65601 Kelvin Via Apt. 150 South Mekhibury, NE 59252-0463", "lat" => -33.376139, "lng" => -70.57704],
        ["address" => "17609 Mollie Ferry Apt. 805 Hazelhaven, MD 30096", "lat" => -33.380107, "lng" => -70.560973],
        ["address" => "191 Caden Springs Suite 353 Luellaborough, OK 96845", "lat" => -33.402959, "lng" => -70.567611],
        ["address" => "53206 Bosco Crescent Apt. 593 Maggioside, NJ 08327-2683", "lat" => -33.408487, "lng" => -70.583318],
        ["address" => "120 Schimmel Glen Port Neva, SD 09660", "lat" => -33.3817, "lng" => -70.543936],
        ["address" => "76650 Upton Landing Apt. 841 North Zachariah, WA 67131-0660", "lat" => -33.380048, "lng" => -70.571529],
        ["address" => "3412 Abel Isle East Chloeview, ID 84112", "lat" => -33.391187, "lng" => -70.552558],
        ["address" => "517 O'Kon Common Hermistonton, GA 36464-8443", "lat" => -33.386089, "lng" => -70.567876],
        ["address" => "8240 Cordelia Extension Leorafurt, IA 27303", "lat" => -33.394734, "lng" => -70.577174],
        ["address" => "1566 Kaycee Squares Apt. 279 East Emily, MI 51921", "lat" => -33.38627, "lng" => -70.560335],
        ["address" => "401 Konopelski Glens Apt. 481 Rebecaland, DC 72458-3696", "lat" => -33.408904, "lng" => -70.549581],
        ["address" => "94832 Neil Park Suite 580 Tobinville, MA 19446-5813", "lat" => -33.375083, "lng" => -70.573887],
        ["address" => "53559 Billy Squares Apt. 237 South Jamaalfurt, KS 76536-5303", "lat" => -33.39506, "lng" => -70.589956],
        ["address" => "680 Toy Overpass South Kenny, DC 40609-6481", "lat" => -33.394561, "lng" => -70.583615],
        ["address" => "58530 Domenic Spurs West Trudiefurt, OR 07705", "lat" => -33.383557, "lng" => -70.560157],
    ];

    foreach ($addresses as $addr) {
        MasterAddress::firstOrCreate(
            ['formatted_address' => $addr['address']],
            [
                'google_lat' => $addr['lat'],
                'google_lng' => $addr['lng'],
                'source' => 'manual',
                'tenant_id' => $tenant->id,
                'validation_count' => 0,
                'is_trusted' => true,
                'concordance_level' => 0,
                'created_from_history' => true,
            ]
        );
    }

    }
}





