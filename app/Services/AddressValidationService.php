<?php

namespace App\Services;

use App\Models\MasterAddress;
use App\Models\NormalizedAddress;
use App\Models\AddressValidationLog;
use Illuminate\Support\Facades\Log;

class AddressValidationService
{
    public function __construct(
        private AddressNormalizationService $normalizer,
        private GoogleMapsClient $mapsClient
    ) {}

    public function validate(string $raw, ?int $tenantId = null): array
    {
        Log::info('Validate called', ['raw_address' => $raw, 'tenant_id' => $tenantId]);

        try {
            // Normalize
            $normalized = $this->normalizer->normalizeForMatch($raw);
            $normalized['original'] = $raw;
            $normKey = $this->buildDeterministicKey($normalized);

            // 1) Lookup normalized_addresses
            $normRow = NormalizedAddress::where('normalized_key', $normKey)->first();
            if ($normRow) {
                $this->log($tenantId, $raw, $normRow->master_address_id, $normRow->id, 'normalized_match');
                $this->bumpTrustFromMatch($normRow, $normRow->masterAddress);
                return ['status' => 'valid', 'source' => 'normalized'];
            }

            // 2) Fallback: master_addresses (portable LIKE)
            $likeRaw = '%' . str_replace(['%','_'], ['\%','\_'], $raw) . '%';
            $master = MasterAddress::where('formatted_address', 'LIKE', $likeRaw)->first();

            if ($master) {
                $norm = $this->upsertNormalized($normalized, $master, $master->formatted_address, $master->google_lat, $master->google_lng);
                $this->log($tenantId, $raw, $master->id, $norm->id, 'master_match');
                $this->bumpTrustFromMatch($norm, $master);
                return ['status' => 'valid', 'source' => 'master'];
            }

            // 3) Google lookup
            $google = $this->mapsClient->lookup($raw);
            if (!empty($google['success'])) {
                $master = MasterAddress::firstOrCreate(
                    ['formatted_address' => $google['formatted_address'] ?? $raw],
                    [
                        'google_lat' => $google['latitude'] ?? null,
                        'google_lng' => $google['longitude'] ?? null,
                        'source'     => 'api',
                        'validation_count' => 1,
                        'is_trusted' => false,
                        'concordance_level' => 0,
                    ]
                );

                $norm = $this->upsertNormalized(
                    $normalized,
                    $master,
                    $google['formatted_address'] ?? null,
                    $google['latitude'] ?? null,
                    $google['longitude'] ?? null
                );

                $this->log($tenantId, $raw, $master->id, $norm->id, 'google_match', $google);
                return ['status' => 'valid', 'source' => 'google', 'data' => $google];
            }

            // 4) Not found
            $this->log($tenantId, $raw, null, null, 'not_found');
            return ['status' => 'invalid'];

        } catch (\Throwable $e) {
            Log::error('Validation error', ['msg' => $e->getMessage()]);
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    protected function upsertNormalized(array $normalized, ?MasterAddress $master, ?string $validatedAddress, ?float $lat, ?float $lng): NormalizedAddress
    {
        $street = trim(preg_replace('/\s+/', ' ', strtolower($normalized['components']['street'] ?? '')));
        $number = trim((string) ($normalized['components']['number'] ?? ''));
        $unit   = trim((string) ($normalized['components']['unit'] ?? ''));

        $key = $this->buildKey($street, $number, $unit);

        return NormalizedAddress::updateOrCreate(
            ['normalized_key' => $key],
            [
                'original_address'  => $normalized['original'] ?? null,
                'validated_address' => $validatedAddress,
                'street'            => $street ?: null,
                'number'            => $number ?: null,
                'unit'              => $unit ?: null,
                'google_lat'        => $lat,
                'google_lng'        => $lng,
                'master_address_id' => $master?->id,
            ]
        );
    }

    protected function buildDeterministicKey(array $normalized): string
    {
        $c = $normalized['components'] ?? [];
        $street = trim(preg_replace('/\s+/', ' ', strtolower($c['street'] ?? '')));
        $number = trim((string) ($c['number'] ?? ''));
        $unit   = trim((string) ($c['unit'] ?? ''));
        if ($street || $number || $unit) {
            return $this->buildKey($street, $number, $unit);
        }
        return strtolower(trim((string) ($normalized['normalized_key'] ?? '')));
    }

    protected function buildKey(?string $street, ?string $number, ?string $unit): string
    {
        return strtolower(trim(($street ?? '').'|'.($number ?? '').'|'.($unit ?? ''), '|'));
    }

    protected function bumpTrustFromMatch(?NormalizedAddress $norm, ?MasterAddress $master): void
    {
        $m = $master;
        if (!$m && $norm) {
            $m = $norm->masterAddress;
        }
        if ($m) {
            $m->validation_count = (int) $m->validation_count + 1;
            $threshold = (int) config('address_validation.trust_threshold', 5);
            if ($m->validation_count >= $threshold) {
                $m->is_trusted = true;
            }
            $m->last_validated_at = now();
            $m->save();
        }
    }

    protected function log(?int $tenantId, string $raw, ?int $masterId, ?int $normalizedId, string $type, array $payload = []): void
    {
        if ($tenantId && !\App\Models\Tenant::whereKey($tenantId)->exists()) {
            $tenantId = null;
        }
        AddressValidationLog::create([
            'tenant_id'             => $tenantId,
            'master_address_id'     => $masterId,
            'normalized_address_id' => $normalizedId,
            'validation_type'       => $type,
            'raw_payload'           => empty($payload) ? null : $payload,
        ]);
    }
}
