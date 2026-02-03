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
            ["address" => "Avenida Providencia 1256, Providencia, Santiago", "lat" => -33.426280, "lng" => -70.615650],
            ["address" => "Calle Los Pinos 789, Las Condes, Santiago", "lat" => -33.414520, "lng" => -70.568910],
            ["address" => "Pasaje Los Aromos 2245, Maipú, Santiago", "lat" => -33.509700, "lng" => -70.762300],
            ["address" => "Camino El Alba 3421, La Reina, Santiago", "lat" => -33.452150, "lng" => -70.539800],
            ["address" => "San Martín 845, Valparaíso", "lat" => -33.045840, "lng" => -71.619680],
            ["address" => "Avenida Errázuriz 1021, Viña del Mar", "lat" => -33.023500, "lng" => -71.552400],
            ["address" => "Calle Lord Cochrane 318, Concepción", "lat" => -36.826990, "lng" => -73.049770],
            ["address" => "Avenida Brasil 1450, Antofagasta", "lat" => -23.652560, "lng" => -70.398950],
            ["address" => "Calle Lautaro 425, Temuco", "lat" => -38.739720, "lng" => -72.598420],
            ["address" => "Avenida Alemania 3021, Temuco", "lat" => -38.736800, "lng" => -72.588600],
            ["address" => "Calle Prat 512, Iquique", "lat" => -20.214650, "lng" => -70.151690],
            ["address" => "Avenida Costanera 987, Puerto Montt", "lat" => -41.469300, "lng" => -72.941000],
            ["address" => "Calle Ignacio Serrano 231, Rancagua", "lat" => -34.170130, "lng" => -70.744930],
            ["address" => "Avenida O'Higgins 1055, Talca", "lat" => -35.426450, "lng" => -71.655420],
            ["address" => "Calle Baquedano 642, Punta Arenas", "lat" => -53.163830, "lng" => -70.917070],
            ["address" => "Avenida Independencia 1320, Quilpué", "lat" => -33.044210, "lng" => -71.449950],
            ["address" => "Calle Las Heras 720, Curicó", "lat" => -34.984480, "lng" => -71.238010],
            ["address" => "Calle Colón 380, Arica", "lat" => -18.478260, "lng" => -70.312600],
            ["address" => "Avenida España 2999, Coquimbo", "lat" => -29.953100, "lng" => -71.338300],
            ["address" => "Calle Caupolicán 1505, Chillán", "lat" => -36.606100, "lng" => -72.103400]

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





