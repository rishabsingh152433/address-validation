<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleMapsClient
{
    public function __construct(private string $apiKey = '')
    {
        $this->apiKey = (string) config('google_maps.api_key');
    }

    public function lookup(string $address): array
    {
        \Log::info('GoogleMapsClient lookup started', ['address' => $address]);
        try {
            if ($this->apiKey === '') return ['success' => false, 'error' => 'missing_api_key'];

            $resp = Http::timeout(10)->retry(2, 200)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $address,
                    'key' => $this->apiKey,
                ]);

            $data = $resp->json();
            if (!empty($data['results'][0])) {
                $r = $data['results'][0];
                return [
                    'success' => true,
                    'formatted_address' => $r['formatted_address'] ?? null,
                    'latitude' => $r['geometry']['location']['lat'] ?? null,
                    'longitude' => $r['geometry']['location']['lng'] ?? null,
                ];
            }
            return ['success' => false];
        } catch (\Throwable $e) {
            \Log::error('Google Maps API error', ['e' => $e->getMessage()]);
            return ['success' => false, 'error' => 'exception'];
        }
    }
}
