<?php

namespace Database\Seeders;

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
            [
                'name' => 'Default Tenant',
                'slug' => 'default',
            ]
        );

        $addresses = [
            [
                "address" => "Avenida Providencia 1256, Providencia, Santiago, Región Metropolitana de Santiago, Chile",
                "lat" => -33.426280,
                "lng" => -70.615650
            ],
            [
                "address" => "Calle Los Pinos 789, Las Condes, Santiago, Región Metropolitana de Santiago, Chile",
                "lat" => -33.414520,
                "lng" => -70.568910
            ],
            [
                "address" => "Pasaje Los Aromos 2245, Maipú, Santiago, Región Metropolitana de Santiago, Chile",
                "lat" => -33.509700,
                "lng" => -70.762300
            ],
            [
                "address" => "Camino El Alba 3421, La Reina, Santiago, Región Metropolitana de Santiago, Chile",
                "lat" => -33.452150,
                "lng" => -70.539800
            ],
            [
                "address" => "San Martín 845, Valparaíso, Región de Valparaíso, Chile",
                "lat" => -33.045840,
                "lng" => -71.619680
            ],
            [
                "address" => "Avenida Errázuriz 1021, Viña del Mar, Región de Valparaíso, Chile",
                "lat" => -33.023500,
                "lng" => -71.552400
            ],
            [
                "address" => "Calle Lord Cochrane 318, Concepción, Región del Biobío, Chile",
                "lat" => -36.826990,
                "lng" => -73.049770
            ],
            [
                "address" => "Avenida Brasil 1450, Antofagasta, Región de Antofagasta, Chile",
                "lat" => -23.652560,
                "lng" => -70.398950
            ],
            [
                "address" => "Calle Lautaro 425, Temuco, Región de La Araucanía, Chile",
                "lat" => -38.739720,
                "lng" => -72.598420
            ],
            [
                "address" => "Avenida Alemania 3021, Temuco, Región de La Araucanía, Chile",
                "lat" => -38.736800,
                "lng" => -72.588600
            ],
            [
                "address" => "Calle Prat 512, Iquique, Región de Tarapacá, Chile",
                "lat" => -20.214650,
                "lng" => -70.151690
            ],
            [
                "address" => "Avenida Costanera 987, Puerto Montt, Región de Los Lagos, Chile",
                "lat" => -41.469300,
                "lng" => -72.941000
            ],
            [
                "address" => "Calle Ignacio Serrano 231, Rancagua, Región del Libertador General Bernardo O'Higgins, Chile",
                "lat" => -34.170130,
                "lng" => -70.744930
            ],
            [
                "address" => "Avenida O'Higgins 1055, Talca, Región del Maule, Chile",
                "lat" => -35.426450,
                "lng" => -71.655420
            ],
            [
                "address" => "Calle Baquedano 642, Punta Arenas, Región de Magallanes y de la Antártica Chilena, Chile",
                "lat" => -53.163830,
                "lng" => -70.917070
            ],
            [
                "address" => "Avenida Independencia 1320, Quilpué, Región de Valparaíso, Chile",
                "lat" => -33.044210,
                "lng" => -71.449950
            ],
            [
                "address" => "Calle Las Heras 720, Curicó, Región del Maule, Chile",
                "lat" => -34.984480,
                "lng" => -71.238010
            ],
            [
                "address" => "Calle Colón 380, Arica, Región de Arica y Parinacota, Chile",
                "lat" => -18.478260,
                "lng" => -70.312600
            ],
            [
                "address" => "Avenida España 2999, Coquimbo, Región de Coquimbo, Chile",
                "lat" => -29.953100,
                "lng" => -71.338300
            ],
            [
                "address" => "Calle Caupolicán 1505, Chillán, Región de Ñuble, Chile",
                "lat" => -36.606100,
                "lng" => -72.103400
            ],
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