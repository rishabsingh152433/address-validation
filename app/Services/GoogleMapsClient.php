<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleMapsClient
{
    private string $apiKey;
    private int $timeout;

    // ✅ HARDCODE: only Chile
    private string $countryCode = 'CL';

    public function __construct(private string $key = '')
    {
        $this->apiKey  = (string) config('google_maps.api_key');
        $this->timeout = (int) config('google_maps.timeout', 10);
    }

    // ✅ Final check: allow only CL results
    private function isCL(array $components): bool
    {
        foreach ($components as $c) {
            if (in_array('country', $c['types'] ?? [], true)) {
                return strtoupper((string) ($c['short_name'] ?? '')) === $this->countryCode;
            }
        }
        return false;
    }

    /**
     * ✅ We will NOT expose candidates if:
     * - not CL
     * - type includes "country" (e.g., "Chile")
     * - partial_match = true
     */
    private function shouldExposeCandidate(array $mapped): bool
    {
        if (!$this->isCL($mapped['components'] ?? [])) return false;

        if (in_array('country', $mapped['types'] ?? [], true)) return false;

        if (!empty($mapped['partial_match'])) return false;

        return true;
    }

    public function resolve(string $rawAddress, ?string $placeId = null, int $maxCandidates = 5): array
    {
        \Log::info('GoogleMapsClient resolve started', ['raw_address' => $rawAddress, 'place_id' => $placeId]);

        try {
            if ($this->apiKey === '') return ['success' => false, 'error' => 'missing_api_key'];

            $rawAddress = trim($rawAddress);
            if ($rawAddress === '') return ['success' => false, 'error' => 'empty_address'];

            // 0) If place_id provided => final answer directly (CL only + expose rules)
            if (!empty($placeId)) {
                $details = $this->placeDetails($placeId);
                $mapped  = $this->mapPlaceDetails($details);

                if (!$mapped) return ['success' => false, 'error' => 'place_details_not_found'];

                if ($this->shouldExposeCandidate($mapped)) {
                    return [
                        'success' => true,
                        'status' => 'valid',
                        'data' => $mapped,
                        'candidates' => [$mapped],
                    ];
                }

                // ✅ placeId exists but not allowed to show (not CL / or country-level / partial)
                return [
                    'success' => true,
                    'status' => 'needs_confirmation',
                    'candidates' => [],
                ];
            }

            // 1) Collect place_ids from Autocomplete(types=address)
            $placeIds = [];
            $auto = $this->autocompleteAddress($rawAddress);
            if (($auto['status'] ?? '') === 'OK') {
                foreach (($auto['predictions'] ?? []) as $p) {
                    if (!empty($p['place_id'])) $placeIds[] = $p['place_id'];
                }
            }

            // 2) Collect place_ids from FindPlaceFromText (backup)
            $find = $this->findPlaceFromText($rawAddress);
            if (($find['status'] ?? '') === 'OK') {
                foreach (($find['candidates'] ?? []) as $c) {
                    if (!empty($c['place_id'])) $placeIds[] = $c['place_id'];
                }
            }

            // unique + clamp
            $placeIds = array_values(array_unique(array_filter($placeIds)));
            $placeIds = array_slice($placeIds, 0, $maxCandidates);

            // 3) Place Details for each candidate (CL only + hide bad candidates)
            $candidates = [];
            foreach ($placeIds as $pid) {
                $details = $this->placeDetails($pid);
                $mapped  = $this->mapPlaceDetails($details);

                if ($mapped && $this->shouldExposeCandidate($mapped)) {
                    $candidates[] = $mapped;
                }
            }

            // ✅ If we have candidates => normal behavior
            if (count($candidates) === 1) {
                return [
                    'success' => true,
                    'status' => 'valid',
                    'data' => $candidates[0],
                    'candidates' => $candidates,
                ];
            }
            if (count($candidates) > 1) {
                return [
                    'success' => true,
                    'status' => 'needs_confirmation',
                    'candidates' => $candidates,
                ];
            }

            // 4) Last resort: Geocode (CL only + hide bad candidates)
            $geo = $this->geocode($rawAddress);
            if (($geo['status'] ?? '') === 'OK' && !empty($geo['results'])) {
                $mapped = [];
                foreach (array_slice($geo['results'], 0, $maxCandidates) as $r) {
                    $m = $this->mapGeocodeResult($r);
                    if ($m && $this->shouldExposeCandidate($m)) $mapped[] = $m;
                }

                if (count($mapped) === 1) {
                    return [
                        'success' => true,
                        'status' => 'valid',
                        'data' => $mapped[0],
                        'candidates' => $mapped,
                    ];
                }
                if (count($mapped) > 1) {
                    return [
                        'success' => true,
                        'status' => 'needs_confirmation',
                        'candidates' => $mapped,
                    ];
                }
            }

            // ✅ IMPORTANT: No candidate details should be returned
            return [
                'success' => true,
                'status' => 'needs_confirmation',
                'candidates' => [],
            ];

        } catch (\Throwable $e) {
            \Log::error('GoogleMapsClient resolve error', ['e' => $e->getMessage()]);
            return ['success' => false, 'error' => 'exception'];
        }
    }

    // Backward compatibility
    public function lookup(string $address): array
    {
        $res = $this->resolve($address, null, (int) config('address_validation.max_candidates', 5));

        if (!empty($res['success']) && ($res['status'] ?? '') === 'valid') {
            $d = $res['data'] ?? [];
            return [
                'success' => true,
                'formatted_address' => $d['formatted_address'] ?? null,
                'latitude' => $d['latitude'] ?? null,
                'longitude' => $d['longitude'] ?? null,
                'place_id' => $d['place_id'] ?? null,
                'components' => $d['components'] ?? [],
                'types' => $d['types'] ?? [],
            ];
        }

        return ['success' => false] + $res;
    }

    // -------------------- Google calls --------------------

    private function autocompleteAddress(string $input): array
    {
        return Http::timeout($this->timeout)->retry(2, 200)->get(
            'https://maps.googleapis.com/maps/api/place/autocomplete/json',
            [
                'input' => $input,
                'types' => 'address',
                'components' => 'country:cl', // ✅ CL only
                'key' => $this->apiKey,
            ]
        )->json() ?? ['status' => 'ERROR', 'predictions' => []];
    }

    private function findPlaceFromText(string $input): array
    {
        return Http::timeout($this->timeout)->retry(2, 200)->get(
            'https://maps.googleapis.com/maps/api/place/findplacefromtext/json',
            [
                'input' => $input,
                'inputtype' => 'textquery',
                'fields' => 'place_id',
                // ✅ Bias to Chile (Santiago center)
                'locationbias' => 'circle:800000@-33.4489,-70.6693',
                'key' => $this->apiKey,
            ]
        )->json() ?? ['status' => 'ERROR', 'candidates' => []];
    }

    private function placeDetails(string $placeId): array
    {
        return Http::timeout($this->timeout)->retry(2, 200)->get(
            'https://maps.googleapis.com/maps/api/place/details/json',
            [
                'place_id' => $placeId,
                'fields' => 'place_id,formatted_address,geometry,address_component,types',
                'key' => $this->apiKey,
            ]
        )->json() ?? ['status' => 'ERROR'];
    }

    private function geocode(string $address): array
    {
        return Http::timeout($this->timeout)->retry(2, 200)->get(
            'https://maps.googleapis.com/maps/api/geocode/json',
            [
                'address' => $address,
                'components' => 'country:cl', // ✅ CL only
                'key' => $this->apiKey,
            ]
        )->json() ?? ['status' => 'ERROR', 'results' => []];
    }

    // -------------------- Mappers --------------------

    private function mapPlaceDetails(array $details): ?array
    {
        if (($details['status'] ?? '') !== 'OK') return null;
        $r = $details['result'] ?? null;
        if (!$r) return null;

        return [
            'place_id' => $r['place_id'] ?? null,
            'formatted_address' => $r['formatted_address'] ?? null,
            'latitude' => data_get($r, 'geometry.location.lat'),
            'longitude' => data_get($r, 'geometry.location.lng'),
            'components' => $r['address_components'] ?? [],
            'types' => $r['types'] ?? [],
            'location_type' => null,
            'partial_match' => null,
        ];
    }

    private function mapGeocodeResult(array $r): ?array
    {
        return [
            'place_id' => $r['place_id'] ?? null,
            'formatted_address' => $r['formatted_address'] ?? null,
            'latitude' => data_get($r, 'geometry.location.lat'),
            'longitude' => data_get($r, 'geometry.location.lng'),
            'components' => $r['address_components'] ?? [],
            'types' => $r['types'] ?? [],
            'location_type' => data_get($r, 'geometry.location_type'),
            'partial_match' => (bool) ($r['partial_match'] ?? false),
        ];
    }
}
