<?php

namespace App\Services;

use App\Models\MasterAddress;
use App\Models\NormalizedAddress;
use App\Models\AddressValidationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AddressValidationService
{
    public function __construct(
        private AddressNormalizationService $normalizer,
        private GoogleMapsClient $mapsClient
    ) {}

    public function validate(string $raw, ?int $tenantId = null, ?string $placeId = null): array
    {
        Log::info('Validate called', ['raw_address' => $raw, 'tenant_id' => $tenantId, 'place_id' => $placeId]);

        try {
            // Normalize
            $normalized = $this->normalizer->normalizeForMatch($raw);
            $normalized['original'] = $raw;
            $normKey = $this->buildDeterministicKey($normalized);

            // Canonical key + hash
            $canonicalKey  = $this->canonicalKey($raw);
            $canonicalHash = $this->canonicalHash($canonicalKey);

            // 0) canonical cache
            $normRow = NormalizedAddress::where('canonical_key_hash', $canonicalHash)->first();
            if ($normRow) {
                $this->log($tenantId, $raw, $normRow->master_address_id, $normRow->id, 'normalized_match');
                $this->bumpTrustFromMatch($normRow, $normRow->masterAddress);

                return [
                    'status' => 'valid',
                    'source' => 'canonical',
                    'data' => [
                        'formatted_address' => $normRow->validated_address ?? $normRow->masterAddress?->formatted_address,
                        'latitude' => $normRow->google_lat ?? $normRow->masterAddress?->google_lat,
                        'longitude' => $normRow->google_lng ?? $normRow->masterAddress?->google_lng,
                    ],
                ];
            }

            // 1) legacy normalized_key
            $normRow = NormalizedAddress::where('normalized_key', $normKey)->first();
            if ($normRow) {
                if (empty($normRow->canonical_key_hash)) {
                    $normRow->canonical_key = $canonicalKey;
                    $normRow->canonical_key_hash = $canonicalHash;
                    $normRow->save();
                }

                $this->log($tenantId, $raw, $normRow->master_address_id, $normRow->id, 'normalized_match');
                $this->bumpTrustFromMatch($normRow, $normRow->masterAddress);

                return [
                    'status' => 'valid',
                    'source' => 'normalized',
                    'data' => [
                        'formatted_address' => $normRow->validated_address ?? $normRow->masterAddress?->formatted_address,
                        'latitude' => $normRow->google_lat ?? $normRow->masterAddress?->google_lat,
                        'longitude' => $normRow->google_lng ?? $normRow->masterAddress?->google_lng,
                    ],
                ];
            }

            // 2) master LIKE fallback
            $likeRaw = '%' . str_replace(['%','_'], ['\%','\_'], $raw) . '%';
            $master = MasterAddress::where('formatted_address', 'LIKE', $likeRaw)->first();

            if ($master) {
                $norm = $this->upsertNormalized(
                    $normalized,
                    $master,
                    $master->formatted_address,
                    $master->google_lat,
                    $master->google_lng,
                    $canonicalKey,
                    $canonicalHash
                );

                $this->log($tenantId, $raw, $master->id, $norm->id, 'master_match');
                $this->bumpTrustFromMatch($norm, $master);

                return [
                    'status' => 'valid',
                    'source' => 'master',
                    'data' => [
                        'formatted_address' => $master->formatted_address,
                        'latitude' => $master->google_lat,
                        'longitude' => $master->google_lng,
                    ],
                ];
            }

            // 3) Google resolve
            $maxCandidates = (int) config('address_validation.max_candidates', 5);
            $google = $this->mapsClient->resolve($raw, $placeId, $maxCandidates);

            if (empty($google['success'])) {
                $this->log($tenantId, $raw, null, null, 'not_found', $google ?: []);
                return ['status' => 'invalid'];
            }

            // =========================================================
            // ✅ IMPORTANT FIX: if place_id provided, CONFIRM directly
            // =========================================================
            if (!empty($placeId)) {
                // resolve() can return in data OR candidates
                $d = $google['data'] ?? null;

                if (!$d) {
                    foreach (($google['candidates'] ?? []) as $c) {
                        if (($c['place_id'] ?? null) === $placeId) {
                            $d = $c;
                            break;
                        }
                    }
                }

                if (!empty($d)) {
                    $master = MasterAddress::firstOrCreate(
                        ['formatted_address' => $d['formatted_address'] ?? $raw],
                        [
                            'google_lat' => $d['latitude'] ?? null,
                            'google_lng' => $d['longitude'] ?? null,
                            'source'     => 'api',
                            'validation_count' => 1,
                            'is_trusted' => false,
                            'concordance_level' => 0,
                        ]
                    );

                    $norm = $this->upsertNormalized(
                        $normalized,
                        $master,
                        $d['formatted_address'] ?? null,
                        $d['latitude'] ?? null,
                        $d['longitude'] ?? null,
                        $canonicalKey,
                        $canonicalHash
                    );

                    // ✅ only allowed enum value (google_match)
                    $this->log($tenantId, $raw, $master->id, $norm->id, 'google_match', [
                        'confirmed_by_place_id' => true,
                        'place_id' => $placeId,
                    ]);

                    $this->bumpTrustFromMatch($norm, $master);

                    return [
                        'status' => 'valid',
                        'source' => 'google_place_id_confirmed',
                        'data' => [
                            'formatted_address' => $d['formatted_address'] ?? null,
                            'latitude' => $d['latitude'] ?? null,
                            'longitude' => $d['longitude'] ?? null,
                            'place_id' => $d['place_id'] ?? null,
                            'components' => $d['components'] ?? [],
                            'types' => $d['types'] ?? [],
                        ],
                    ];
                }
                // if place_id not found somehow -> continue normal flow
            }

            $candidates = $google['candidates'] ?? [];

            // If Google returned single "valid", still treat it as candidate set
            if (($google['status'] ?? '') === 'valid' && !empty($google['data'])) {
                $candidates = $candidates ?: [$google['data']];
            }

            // Pick best candidate by score
            [$best, $bestScore, $gap] = $this->pickBestCandidateByScore($raw, $candidates);

            $minScore = (int) config('address_validation.auto_pick_min_score', 85);
            $minGap   = (int) config('address_validation.auto_pick_min_gap', 15);

            // If confident => store + return valid
            if ($best && $bestScore >= $minScore && $gap >= $minGap) {
                $d = $best;

                $master = MasterAddress::firstOrCreate(
                    ['formatted_address' => $d['formatted_address'] ?? $raw],
                    [
                        'google_lat' => $d['latitude'] ?? null,
                        'google_lng' => $d['longitude'] ?? null,
                        'source'     => 'api',
                        'validation_count' => 1,
                        'is_trusted' => false,
                        'concordance_level' => 0,
                    ]
                );

                $norm = $this->upsertNormalized(
                    $normalized,
                    $master,
                    $d['formatted_address'] ?? null,
                    $d['latitude'] ?? null,
                    $d['longitude'] ?? null,
                    $canonicalKey,
                    $canonicalHash
                );

                $this->log($tenantId, $raw, $master->id, $norm->id, 'google_match', [
                    'score' => $bestScore,
                    'gap' => $gap,
                    'picked' => $d,
                ]);

                $this->bumpTrustFromMatch($norm, $master);

                return [
                    'status' => 'valid',
                    'source' => 'google_places_scored',
                    'confidence' => $bestScore,
                    'data' => [
                        'formatted_address' => $d['formatted_address'] ?? null,
                        'latitude' => $d['latitude'] ?? null,
                        'longitude' => $d['longitude'] ?? null,
                        'place_id' => $d['place_id'] ?? null,
                        'components' => $d['components'] ?? [],
                        'types' => $d['types'] ?? [],
                    ],
                ];
            }

            // Not confident => needs_confirmation
            // ✅ DB enum issue avoid: log as 'manual' (allowed)
            $this->log($tenantId, $raw, null, null, 'manual', [
                'top_score' => $bestScore,
                'gap' => $gap,
                'candidates' => $candidates,
            ]);

            return [
                'status' => 'needs_confirmation',
                'source' => 'google_places',
                'message' => 'Multiple/uncertain matches. Please select the correct address.',
                'candidates' => $candidates,
            ];

        } catch (\Throwable $e) {
            Log::error('Validation error', ['msg' => $e->getMessage()]);
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    // ---------- unchanged helpers (your existing methods) ----------

    protected function upsertNormalized(
        array $normalized,
        ?MasterAddress $master,
        ?string $validatedAddress,
        ?float $lat,
        ?float $lng,
        string $canonicalKey,
        string $canonicalHash
    ): NormalizedAddress {
        $street = trim(preg_replace('/\s+/', ' ', strtolower($normalized['components']['street'] ?? '')));
        $number = trim((string) ($normalized['components']['number'] ?? ''));
        $unit   = trim((string) ($normalized['components']['unit'] ?? ''));

        $legacyKey = $this->buildKey($street, $number, $unit);

        return NormalizedAddress::updateOrCreate(
            ['canonical_key_hash' => $canonicalHash],
            [
                'canonical_key'      => $canonicalKey,
                'normalized_key'     => $legacyKey,
                'original_address'   => $normalized['original'] ?? null,
                'validated_address'  => $validatedAddress,
                'street'             => $street ?: null,
                'number'             => $number ?: null,
                'unit'               => $unit ?: null,
                'google_lat'         => $lat,
                'google_lng'         => $lng,
                'master_address_id'  => $master?->id,
            ]
        );
    }

    private function canonicalKey(string $raw): string
    {
        if (mb_strlen($raw) > 10000) $raw = mb_substr($raw, 0, 10000);

        $s = Str::of($raw)->lower();
        $s = Str::ascii((string) $s);
        $s = preg_replace('/[^\pL\pN\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));

        $tokens = explode(' ', $s);
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        sort($tokens, SORT_STRING);
        return implode(' ', $tokens);
    }

    private function canonicalHash(string $canonicalKey): string
    {
        return hash('sha256', $canonicalKey);
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
        if (!$m && $norm) $m = $norm->masterAddress;

        if ($m) {
            $m->validation_count = (int) $m->validation_count + 1;
            $threshold = (int) config('address_validation.trust_threshold', 5);
            if ($m->validation_count >= $threshold) $m->is_trusted = true;
            $m->last_validated_at = now();
            $m->save();
        }
    }

    protected function log(?int $tenantId, string $raw, ?int $masterId, ?int $normalizedId, string $type, array $payload = []): void
    {
        if ($tenantId && !\App\Models\Tenant::whereKey($tenantId)->exists()) $tenantId = null;

        AddressValidationLog::create([
            'tenant_id'             => $tenantId,
            'master_address_id'     => $masterId,
            'normalized_address_id' => $normalizedId,
            'validation_type'       => $type,
            'raw_payload'           => empty($payload) ? null : $payload,
        ]);
    }

    // =========================================================
    // Scoring (IMPROVED): hard rules to prevent wrong "valid"
    // =========================================================

    private function pickBestCandidateByScore(string $raw, array $candidates): array
    {
        $scores = [];
        foreach ($candidates as $idx => $c) {
            $scores[$idx] = $this->scoreCandidate($raw, $c);
        }

        if (empty($scores)) return [null, 0, 0];

        arsort($scores);
        $indexes = array_keys($scores);

        $bestIdx = $indexes[0];
        $bestScore = (int) $scores[$bestIdx];
        $secondScore = isset($indexes[1]) ? (int) $scores[$indexes[1]] : 0;

        return [$candidates[$bestIdx] ?? null, $bestScore, $bestScore - $secondScore];
    }

    private function scoreCandidate(string $raw, array $candidate): int
    {
        $rawTokens = $this->tokenize($raw);
        $rawNums   = $this->extractNumbers($raw);

        $formatted = (string) ($candidate['formatted_address'] ?? '');
        $fmtTokens = $this->tokenize($formatted);

        $components = $candidate['components'] ?? [];
        $streetNo = $this->componentLong($components, 'street_number');
        $route    = $this->componentLong($components, 'route');

        $types = $candidate['types'] ?? [];

        // HARD RULE #1: If raw has number and candidate has street_number, MUST MATCH
        if (!empty($rawNums) && !empty($streetNo) && (string)$rawNums[0] !== (string)$streetNo) {
            return 0;
        }

        // HARD RULE #2: Reject POI/establishment unless it looks address-like
        $isPoi = is_array($types) && (in_array('point_of_interest', $types, true) || in_array('establishment', $types, true));
        $looksAddressLike = $this->looksLikeAddress($components, $types);

        if ($isPoi && !$looksAddressLike) {
            return 0;
        }

        $score = 0;

        // Street number match gives strong confidence
        if (!empty($rawNums) && !empty($streetNo) && (string)$rawNums[0] === (string)$streetNo) {
            $score += 40;
        }

        // Route overlap
        if (!empty($route)) {
            $routeTokens = $this->tokenize($route);
            $score += (int) round($this->tokenOverlapScore($rawTokens, $routeTokens) * 30);
        } else {
            $score += (int) round($this->tokenOverlapScore($rawTokens, $fmtTokens) * 20);
        }

        // Overall overlap
        $score += (int) round($this->tokenOverlapScore($rawTokens, $fmtTokens) * 30);

        if ($looksAddressLike) $score += 10;

        return max(0, min(100, $score));
    }

    private function looksLikeAddress(array $components, $types): bool
    {
        $hasStreetNo = !empty($this->componentLong($components, 'street_number'));
        $hasRoute    = !empty($this->componentLong($components, 'route'));

        $isStreetType = is_array($types) && (
            in_array('street_address', $types, true) ||
            in_array('premise', $types, true) ||
            in_array('subpremise', $types, true)
        );

        return ($hasStreetNo && $hasRoute) || $isStreetType;
    }

    private function tokenize(string $s): array
    {
        $s = mb_strtolower($s);
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($converted !== false && $converted !== null) $s = $converted;

        $s = preg_replace('/[^\pL\pN\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));

        if ($s === '') return [];
        return array_values(array_filter(explode(' ', $s)));
    }

    private function extractNumbers(string $s): array
    {
        preg_match_all('/\d+/', $s, $m);
        return $m[0] ?? [];
    }

    private function tokenOverlapScore(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;
        $sa = array_unique($a);
        $sb = array_unique($b);
        $inter = array_intersect($sa, $sb);
        return count($inter) / max(1, count($sa));
    }

    private function componentLong(array $components, string $type): ?string
    {
        foreach ($components as $c) {
            if (in_array($type, $c['types'] ?? [], true)) {
                return (string) ($c['long_name'] ?? '');
            }
        }
        return null;
    }
}
