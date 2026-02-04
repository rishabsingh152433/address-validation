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

            // 0) canonical cache (exact)
            $normRow = NormalizedAddress::where('canonical_key_hash', $canonicalHash)->first();
            if ($normRow) {
                $this->log($tenantId, $raw, $normRow->master_address_id, $normRow->id, 'normalized_match', [
                    'matched_by' => 'canonical_hash_exact'
                ]);
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

            // 1) legacy normalized_key (exact)
            $normRow = NormalizedAddress::where('normalized_key', $normKey)->first();
            if ($normRow) {
                if (empty($normRow->canonical_key_hash)) {
                    $normRow->canonical_key = $canonicalKey;
                    $normRow->canonical_key_hash = $canonicalHash;
                    $normRow->save();
                }

                $this->log($tenantId, $raw, $normRow->master_address_id, $normRow->id, 'normalized_match', [
                    'matched_by' => 'normalized_key_exact'
                ]);
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

            // =========================================================
            //  NEW STEP (1.5): FUZZY DB MATCH (non-strict)
            // =========================================================
            $fuzzy = $this->fuzzyNormalizedMatch($raw, $canonicalKey);
            if ($fuzzy) {
                /** @var NormalizedAddress $row */
                [$row, $score, $gap] = $fuzzy;

                // backfill canonical fields if missing
                if (empty($row->canonical_key_hash)) {
                    $row->canonical_key = $row->canonical_key ?: $canonicalKey;
                    $row->canonical_key_hash = $this->canonicalHash($row->canonical_key);
                    $row->save();
                }

                $this->log($tenantId, $raw, $row->master_address_id, $row->id, 'normalized_match', [
                    'matched_by' => 'fuzzy_db',
                    'fuzzy' => true,
                    'score' => $score,
                    'gap' => $gap,
                ]);

                $this->bumpTrustFromMatch($row, $row->masterAddress);

                return [
                    'status' => 'valid',
                    'source' => 'canonical_fuzzy',
                    'confidence' => $score,
                    'data' => [
                        'formatted_address' => $row->validated_address ?? $row->masterAddress?->formatted_address,
                        'latitude' => $row->google_lat ?? $row->masterAddress?->google_lat,
                        'longitude' => $row->google_lng ?? $row->masterAddress?->google_lng,
                    ],
                ];
            }

            // 2) master LIKE fallback
            //$likeRaw = '%' . str_replace(['%','_'], ['\%','\_'], $raw) . '%';
            //$master = MasterAddress::where('formatted_address', 'LIKE', $likeRaw)->first();


            $likeRaw = '%' . str_replace(['%','_'], ['\%','\_'], $raw) . '%';

    //  $master = MasterAddress::query()
    // ->where('formatted_address', 'LIKE', $likeRaw)
    // ->orWhereRaw('LOWER(?) LIKE CONCAT("%", LOWER(formatted_address), "%")', [mb_strtolower($raw)])
    // ->first();

    $rawLower = mb_strtolower($raw);

$master = MasterAddress::query()
    ->where('formatted_address', 'LIKE', $likeRaw)
    ->orWhereRaw(
        'LOWER(?) LIKE \'%\' || LOWER(formatted_address) || \'%\'',
        [$rawLower]
    )
    ->first();


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

            //  place_id confirm flow (unchanged)
            if (!empty($placeId)) {
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
            }

            $candidates = $google['candidates'] ?? [];

            if (($google['status'] ?? '') === 'valid' && !empty($google['data'])) {
                $candidates = $candidates ?: [$google['data']];
            }

            [$best, $bestScore, $gap] = $this->pickBestCandidateByScore($raw, $candidates);

            $minScore = (int) config('address_validation.auto_pick_min_score', 80);
            $minGap   = (int) config('address_validation.auto_pick_min_gap', 15);

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

    // =========================================================
    //  FUZZY DB MATCH HELPERS
    // =========================================================

    private function fuzzyNormalizedMatch(string $raw, string $canonicalKey): ?array
    {
        $enabled = (bool) config('address_validation.db_fuzzy_enabled', true);
        if (!$enabled) return null;

        $rawTokens = array_values(array_filter(explode(' ', $canonicalKey)));
        $rawNums   = $this->extractNumbers($raw);
        $rawNumber = $rawNums[0] ?? null;

        // pick significant tokens (avoid very common words)
        $sigTokens = $this->pickSignificantTokens($rawTokens);

        $limit   = (int) config('address_validation.db_fuzzy_candidate_limit', 50);
        $minScore = (int) config('address_validation.db_fuzzy_min_score', 80);
        $minGap   = (int) config('address_validation.db_fuzzy_min_gap', 10);
        $minOverlap = (float) config('address_validation.db_fuzzy_min_overlap', 0.75);

        // 1) First try: if number exists, restrict by number (fast + safe)
        $cands = collect();
        if ($rawNumber !== null) {
            $q = NormalizedAddress::query()
                ->with('masterAddress')
                ->where('number', (string) $rawNumber);

            // also add token ORs to avoid wrong same-number matches
            $q->where(function ($sub) use ($sigTokens) {
                foreach ($sigTokens as $t) {
                    $sub->orWhere('canonical_key', 'LIKE', '%' . $this->escapeLike($t) . '%');
                }
            });

            $cands = $q->limit($limit)->get();
        }

        // 2) Fallback: if no candidates found, search by tokens only
        if ($cands->isEmpty()) {
            if (empty($sigTokens)) return null;

            $q = NormalizedAddress::query()
                ->with('masterAddress')
                ->whereNotNull('canonical_key')
                ->where(function ($sub) use ($sigTokens, $rawNumber) {
                    foreach ($sigTokens as $t) {
                        $sub->orWhere('canonical_key', 'LIKE', '%' . $this->escapeLike($t) . '%');
                    }
                    if ($rawNumber !== null) {
                        $sub->orWhere('canonical_key', 'LIKE', '%' . $this->escapeLike((string)$rawNumber) . '%');
                    }
                });

            $cands = $q->limit($limit)->get();
        }

        if ($cands->isEmpty()) return null;

        // score candidates
        $scores = [];
        foreach ($cands as $idx => $row) {
            $scores[$idx] = $this->scoreDbCandidate($rawTokens, $rawNumber, $row, $minOverlap);
        }

        arsort($scores);
        $indexes = array_keys($scores);

        $bestIdx = $indexes[0] ?? null;
        if ($bestIdx === null) return null;

        $bestScore = (int) ($scores[$bestIdx] ?? 0);
        $secondScore = isset($indexes[1]) ? (int) ($scores[$indexes[1]] ?? 0) : 0;
        $gap = $bestScore - $secondScore;

        if ($bestScore >= $minScore && $gap >= $minGap) {
            return [$cands[$bestIdx], $bestScore, $gap];
        }

        return null;
    }

    private function scoreDbCandidate(array $rawTokens, ?string $rawNumber, NormalizedAddress $row, float $minOverlap): int
    {
        // hard rule: number must match if both exist
        $rowNumber = $row->number !== null ? (string) $row->number : null;
        if ($rawNumber !== null && $rowNumber !== null && $rawNumber !== $rowNumber) {
            return 0;
        }

        $candText = (string) ($row->canonical_key ?? $row->validated_address ?? $row->original_address ?? '');
        $candTokens = $this->tokenize($candText);

        $overlap = $this->tokenOverlapScore($rawTokens, $candTokens);
        if ($overlap < $minOverlap) return 0;

        $score = (int) round($overlap * 100);

        // small trust boost (optional)
        $m = $row->masterAddress;
        if ($m && !empty($m->is_trusted)) $score += 5;
        if ($m && (int) ($m->validation_count ?? 0) >= (int) config('address_validation.trust_threshold', 5)) $score += 3;

        return max(0, min(100, $score));
    }

    private function pickSignificantTokens(array $tokens): array
    {
        $stop = [
            'av', 'ave', 'avenida', 'street', 'st', 'road', 'rd', 'calle', 'pje', 'pasaje', 'dept', 'depto',
            'chile', 'region', 'provincia', 'comuna'
        ];

        $filtered = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if ($t === '') continue;
            if (ctype_digit($t)) continue;
            if (mb_strlen($t) < 4) continue; // keep meaningful words only
            if (in_array($t, $stop, true)) continue;
            $filtered[] = $t;
        }

        // Prefer longest tokens first (better filtering)
        usort($filtered, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_slice(array_values(array_unique($filtered)), 0, 4);
    }

    private function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $v);
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

    // private function canonicalKey(string $raw): string
    // {
    //     if (mb_strlen($raw) > 10000) $raw = mb_substr($raw, 0, 10000);

    //     $s = Str::of($raw)->lower();
    //     $s = Str::ascii((string) $s);
    //     $s = preg_replace('/[^\pL\pN\s]/u', ' ', $s);
    //     $s = preg_replace('/\s+/', ' ', trim($s));

    //     $tokens = explode(' ', $s);
    //     $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

    //     sort($tokens, SORT_STRING);
    //     return implode(' ', $tokens);
    // }


    private function canonicalKey(string $raw): string
   {
    if (mb_strlen($raw) > 10000) $raw = mb_substr($raw, 0, 10000);

    $s = Str::of($raw)->lower();
    $s = Str::ascii((string) $s);
    $s = preg_replace('/[^\pL\pN\s]/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', trim($s));

    $tokens = array_values(array_filter(explode(' ', $s), fn($t) => $t !== ''));

    // ✅ ignore location/country filler words
    $stop = ['chile','region','provincia','comuna','metropolitana','región'];
    $tokens = array_values(array_filter($tokens, fn($t) => !in_array($t, $stop, true)));

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

        if (!empty($rawNums) && !empty($streetNo) && (string)$rawNums[0] !== (string)$streetNo) {
            return 0;
        }

        $isPoi = is_array($types) && (in_array('point_of_interest', $types, true) || in_array('establishment', $types, true));
        $looksAddressLike = $this->looksLikeAddress($components, $types);

        if ($isPoi && !$looksAddressLike) {
            return 0;
        }

        $score = 0;

        if (!empty($rawNums) && !empty($streetNo) && (string)$rawNums[0] === (string)$streetNo) {
            $score += 40;
        }

        if (!empty($route)) {
            $routeTokens = $this->tokenize($route);
            $score += (int) round($this->tokenOverlapScore($rawTokens, $routeTokens) * 30);
        } else {
            $score += (int) round($this->tokenOverlapScore($rawTokens, $fmtTokens) * 20);
        }

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
