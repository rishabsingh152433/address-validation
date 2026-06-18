<?php

namespace App\Services;

use App\Models\AddressConfirmationCount;
use App\Models\MasterAddress;
use App\Models\NormalizedAddress;
use App\Models\AddressValidationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AddressValidationService
{
    public function __construct(
        private AddressNormalizationService $normalizer,
        private GoogleMapsClient $mapsClient,
        private ChileGeoRegistry $geoRegistry
    ) {}

    public function validate(string $raw, ?int $tenantId = null, ?string $placeId = null): array
    {
        $tenantId = $this->sanitizeTenantId($tenantId);
        $rawGeo = $this->geoRegistry->extractFromText($raw);
        $canUseDbCandidates = $this->geoRegistry->hasGeo($rawGeo);

        Log::info('Validate called', [
            'raw_address' => $raw,
            'tenant_id' => $tenantId,
            'place_id' => $placeId,
            'raw_geo' => $rawGeo,
            'db_geo_gate' => $canUseDbCandidates ? 'open' : 'google_only',
        ]);

        try {
            $normalized = $this->normalizer->normalizeForMatch($raw);
            $normalized['original'] = $raw;
            $normKey = $this->buildDeterministicKey($normalized);

            $canonicalKey = $this->canonicalKey($raw);
            $canonicalHash = $this->canonicalHash($canonicalKey);

            if ($canUseDbCandidates) {
                $normRow = NormalizedAddress::where('canonical_key_hash', $canonicalHash)->first();
                if ($normRow) {
                    if ($this->shouldUseNormalizedCandidate($rawGeo, $normRow)) {
                        if (!empty($normRow->master_address_id)) {
                            $this->log($tenantId, $raw, $normRow->master_address_id, $normRow->id, 'normalized_match', [
                                'matched_by' => 'canonical_hash_exact',
                                'raw_geo' => $rawGeo,
                                'candidate_geo' => $this->extractGeoFromNormalized($normRow),
                            ]);
                            $this->bumpTrustFromMatch($normRow, $normRow->masterAddress);

                            return $this->normalizedValidResponse('canonical', $normRow);
                        }

                        if (empty($placeId) && empty($normRow->master_address_id)) {
                            return $this->oldGooglePlacesFlowForPendingNormalized(
                                tenantId: $tenantId,
                                raw: $raw,
                                normalized: $normalized,
                                canonicalKey: $canonicalKey,
                                canonicalHash: $canonicalHash,
                                rawGeo: $rawGeo,
                                pendingRow: $normRow,
                                matchedBy: 'canonical_hash_pending_master'
                            );
                        }
                    } else {
                        $this->logGeoRejectedCandidate($tenantId, $raw, $rawGeo, 'canonical_hash_exact_rejected', $normRow, $normRow->masterAddress);
                    }
                }

                $normRow = NormalizedAddress::where('normalized_key', $normKey)->first();
                if ($normRow) {
                    if (empty($normRow->canonical_key_hash)) {
                        $normRow->canonical_key = $canonicalKey;
                        $normRow->canonical_key_hash = $canonicalHash;
                        $normRow->save();
                    }

                    if ($this->shouldUseNormalizedCandidate($rawGeo, $normRow)) {
                        if (!empty($normRow->master_address_id)) {
                            $this->log($tenantId, $raw, $normRow->master_address_id, $normRow->id, 'normalized_match', [
                                'matched_by' => 'normalized_key_exact',
                                'raw_geo' => $rawGeo,
                                'candidate_geo' => $this->extractGeoFromNormalized($normRow),
                            ]);
                            $this->bumpTrustFromMatch($normRow, $normRow->masterAddress);

                            return $this->normalizedValidResponse('normalized', $normRow);
                        }

                        if (empty($placeId) && empty($normRow->master_address_id)) {
                            return $this->oldGooglePlacesFlowForPendingNormalized(
                                tenantId: $tenantId,
                                raw: $raw,
                                normalized: $normalized,
                                canonicalKey: $canonicalKey,
                                canonicalHash: $canonicalHash,
                                rawGeo: $rawGeo,
                                pendingRow: $normRow,
                                matchedBy: 'normalized_key_pending_master'
                            );
                        }
                    } else {
                        $this->logGeoRejectedCandidate($tenantId, $raw, $rawGeo, 'normalized_key_exact_rejected', $normRow, $normRow->masterAddress);
                    }
                }

                $fuzzy = $this->fuzzyNormalizedMatch($raw, $canonicalKey);
                if ($fuzzy) {
                    [$row, $score, $gap] = $fuzzy;

                    if (empty($row->canonical_key_hash)) {
                        $row->canonical_key = $row->canonical_key ?: $canonicalKey;
                        $row->canonical_key_hash = $this->canonicalHash($row->canonical_key);
                        $row->save();
                    }

                    if ($this->shouldUseNormalizedCandidate($rawGeo, $row)) {
                        $this->log($tenantId, $raw, $row->master_address_id, $row->id, 'normalized_match', [
                            'matched_by' => 'fuzzy_db',
                            'fuzzy' => true,
                            'score' => $score,
                            'gap' => $gap,
                            'raw_geo' => $rawGeo,
                            'candidate_geo' => $this->extractGeoFromNormalized($row),
                        ]);

                        if (!empty($row->master_address_id)) {
                            $this->bumpTrustFromMatch($row, $row->masterAddress);

                            return $this->normalizedValidResponse('canonical_fuzzy', $row, $score);
                        }

                        if (empty($placeId) && empty($row->master_address_id)) {
                            return $this->oldGooglePlacesFlowForPendingNormalized(
                                tenantId: $tenantId,
                                raw: $raw,
                                normalized: $normalized,
                                canonicalKey: $canonicalKey,
                                canonicalHash: $canonicalHash,
                                rawGeo: $rawGeo,
                                pendingRow: $row,
                                matchedBy: 'fuzzy_pending_master'
                            );
                        }
                    } else {
                        $this->logGeoRejectedCandidate($tenantId, $raw, $rawGeo, 'fuzzy_db_rejected', $row, $row->masterAddress, [
                            'fuzzy' => true,
                            'score' => $score,
                            'gap' => $gap,
                        ]);
                    }
                }

                $likeRaw = '%' . str_replace(['%','_'], ['\%','\_'], $raw) . '%';
                $rawLower = mb_strtolower($raw);
                $threshold = $this->promotionThreshold();

                $master = MasterAddress::query()
                    ->where(function ($q) use ($likeRaw, $rawLower) {
                        $q->where('formatted_address', 'LIKE', $likeRaw)
                            ->orWhereRaw(
                                "LOWER(?) LIKE '%' || LOWER(formatted_address) || '%'",
                                [$rawLower]
                            );
                    })
                    ->where(function ($q) use ($threshold) {
                        $q->where('is_trusted', true)
                            ->orWhere('validation_count', '>=', $threshold);
                    })
                    ->first();

                if ($master) {
                    if ($this->shouldUseMasterCandidate($rawGeo, $master)) {
                        $norm = $this->upsertNormalized(
                            $normalized,
                            $master,
                            $master->formatted_address,
                            $master->google_lat,
                            $master->google_lng,
                            $canonicalKey,
                            $canonicalHash
                        );

                        $this->log($tenantId, $raw, $master->id, $norm->id, 'master_match', [
                            'matched_by' => 'master_like',
                            'raw_geo' => $rawGeo,
                            'candidate_geo' => $this->extractGeoFromMaster($master),
                        ]);
                        $this->bumpTrustFromMatch($norm, $master);

                        return [
                            'status' => 'valid',
                            'source' => 'master',
                            'data' => [
                                'normalize_id' => $norm->id,
                                'master_address_id' => $master->id,
                                'formatted_address' => $master->formatted_address,
                                'latitude' => $master->google_lat,
                                'longitude' => $master->google_lng,
                            ],
                        ];
                    }

                    $this->logGeoRejectedCandidate($tenantId, $raw, $rawGeo, 'master_like_rejected', null, $master);
                }
            } else {
                $this->log($tenantId, $raw, null, null, 'manual', [
                    'geo_gate' => 'raw_geo_missing_or_conflicted',
                    'raw_geo' => $rawGeo,
                    'action' => 'skip_db_and_call_google',
                ]);
            }

            if (!empty($placeId) && $canUseDbCandidates) {
                $cached = NormalizedAddress::where('place_id', $placeId)->first();
                if ($cached && !empty($cached->validated_address)) {
                    if ($this->shouldUseNormalizedCandidate($rawGeo, $cached)) {
                        $cachedCandidate = [
                            'place_id' => $placeId,
                            'formatted_address' => $cached->validated_address,
                            'latitude' => $cached->google_lat,
                            'longitude' => $cached->google_lng,
                            'components' => [],
                            'types' => [],
                        ];

                        [$count, $threshold, $master, $norm] = $this->confirmPlaceIdAndMaybePromote(
                            tenantId: $tenantId,
                            raw: $raw,
                            normalized: $normalized,
                            placeId: $placeId,
                            candidate: $cachedCandidate,
                            canonicalKey: $canonicalKey,
                            canonicalHash: $canonicalHash
                        );

                        $this->log($tenantId, $raw, $master?->id, $norm->id, 'google_match', [
                            'confirmed_by_place_id' => true,
                            'place_id' => $placeId,
                            'confirmed_count' => $count,
                            'required_confirmations' => $threshold,
                            'master_promoted' => (bool) $master,
                            'from_cache' => true,
                            'raw_geo' => $rawGeo,
                            'candidate_geo' => $this->extractGeoFromNormalized($cached),
                        ]);

                        return [
                            'status' => 'valid',
                            'source' => $master ? 'place_id_confirmed_cached_master_promoted' : 'place_id_confirmed_cached_pending_master',
                            'confirmed_count' => $count,
                            'required_confirmations' => $threshold,
                            'master_ready' => (bool) $master,
                            'data' => [
                                'normalize_id' => $norm->id,
                                'master_address_id' => $master?->id,
                                'formatted_address' => $cachedCandidate['formatted_address'],
                                'latitude' => $cachedCandidate['latitude'],
                                'longitude' => $cachedCandidate['longitude'],
                                'place_id' => $placeId,
                            ],
                        ];
                    }

                    $this->logGeoRejectedCandidate($tenantId, $raw, $rawGeo, 'cached_place_id_rejected', $cached, $cached->masterAddress, [
                        'place_id' => $placeId,
                    ]);
                }
            }

            $maxCandidates = (int) config('address_validation.max_candidates', 5);
            $google = $this->mapsClient->resolve($raw, $placeId, $maxCandidates);

            if (empty($google['success'])) {
                $this->log($tenantId, $raw, null, null, 'not_found', $google ?: []);
                return ['status' => 'invalid'];
            }

            if (!empty($placeId)) {
                $d = $google['data'] ?? null;

                if (!$d) {
                    foreach (($google['candidates'] ?? []) as $candidate) {
                        if (($candidate['place_id'] ?? null) === $placeId) {
                            $d = $candidate;
                            break;
                        }
                    }
                }

                if (!empty($d)) {
                    [$count, $threshold, $master, $norm] = $this->confirmPlaceIdAndMaybePromote(
                        tenantId: $tenantId,
                        raw: $raw,
                        normalized: $normalized,
                        placeId: $placeId,
                        candidate: $d,
                        canonicalKey: $canonicalKey,
                        canonicalHash: $canonicalHash
                    );

                    $this->log($tenantId, $raw, $master?->id, $norm->id, 'google_match', [
                        'confirmed_by_place_id' => true,
                        'place_id' => $placeId,
                        'confirmed_count' => $count,
                        'required_confirmations' => $threshold,
                        'master_promoted' => (bool) $master,
                        'raw_geo' => $rawGeo,
                    ]);

                    return [
                        'status' => 'valid',
                        'source' => $master ? 'google_place_id_confirmed_master_promoted' : 'google_place_id_confirmed_pending_master',
                        'confirmed_count' => $count,
                        'required_confirmations' => $threshold,
                        'master_ready' => (bool) $master,
                        'data' => [
                            'normalize_id' => $norm->id,
                            'master_address_id' => $master?->id,
                            'formatted_address' => $d['formatted_address'] ?? null,
                            'latitude' => $d['latitude'] ?? null,
                            'longitude' => $d['longitude'] ?? null,
                            'place_id' => $d['place_id'] ?? $placeId,
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
            $minGap = (int) config('address_validation.auto_pick_min_gap', 15);

            if ($best && $bestScore >= $minScore && $gap >= $minGap) {
                $d = $best;

                $norm = $this->upsertNormalized(
                    $normalized,
                    null,
                    $d['formatted_address'] ?? null,
                    $d['latitude'] ?? null,
                    $d['longitude'] ?? null,
                    $canonicalKey,
                    $canonicalHash,
                    $d['place_id'] ?? null
                );

                $this->log($tenantId, $raw, null, $norm->id, 'manual', [
                    'top_score' => $bestScore,
                    'gap' => $gap,
                    'suggested_place_id' => $d['place_id'] ?? null,
                    'raw_geo' => $rawGeo,
                    'candidate_geo' => $this->geoRegistry->extractFromComponents($d['components'] ?? [], $d['formatted_address'] ?? null),
                ]);
            }

            $this->log($tenantId, $raw, null, null, 'manual', [
                'top_score' => $bestScore,
                'gap' => $gap,
                'candidates' => $candidates,
                'raw_geo' => $rawGeo,
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
    //  NEW: 5 confirmations gate before promoting into master
    // =========================================================

    private function promotionThreshold(): int
    {
        return (int) config('address_validation.trust_threshold', 5);
    }

    private function confirmedCountForPlaceId(string $placeId): int
    {
        return (int) (AddressConfirmationCount::where('place_id', $placeId)->value('confirmation_count') ?? 0);
    }

    /**
     * When a normalized DB row exists but it is not linked to master_addresses yet,
     * do NOT return cached_pending_master_promotion.
     * Instead run the same old Google Places candidate flow by passing placeId = null.
     * This keeps the frontend behavior: needs_confirmation + up to max_candidates Google candidates.
     */
    private function oldGooglePlacesFlowForPendingNormalized(
        ?int $tenantId,
        string $raw,
        array $normalized,
        string $canonicalKey,
        string $canonicalHash,
        array $rawGeo,
        ?NormalizedAddress $pendingRow = null,
        string $matchedBy = 'pending_normalized_without_master'
    ): array {
        $maxCandidates = (int) config('address_validation.max_candidates', 5);

        // IMPORTANT: placeId must be null here.
        // If cached place_id is passed, Google Place Details returns only one result.
        // Null keeps the old flow: Autocomplete -> Find Place -> Details -> Geocode fallback.
        $google = $this->mapsClient->resolve($raw, null, $maxCandidates);

        if (empty($google['success'])) {
            $this->log($tenantId, $raw, null, $pendingRow?->id, 'not_found', [
                'matched_by' => $matchedBy . '_google_places_failed',
                'cached_place_id' => $pendingRow?->place_id,
                'raw_geo' => $rawGeo,
                'google' => $google ?: [],
            ]);

            return ['status' => 'invalid'];
        }

        $candidates = $google['candidates'] ?? [];
        if (($google['status'] ?? '') === 'valid' && !empty($google['data'])) {
            $candidates = $candidates ?: [$google['data']];
        }

        [$best, $bestScore, $gap] = $this->pickBestCandidateByScore($raw, $candidates);
        $minScore = (int) config('address_validation.auto_pick_min_score', 80);
        $minGap = (int) config('address_validation.auto_pick_min_gap', 15);

        if ($best && $bestScore >= $minScore && $gap >= $minGap) {
            $norm = $this->upsertNormalized(
                $normalized,
                null,
                $best['formatted_address'] ?? null,
                $best['latitude'] ?? null,
                $best['longitude'] ?? null,
                $canonicalKey,
                $canonicalHash,
                $best['place_id'] ?? null
            );

            $this->log($tenantId, $raw, null, $norm->id, 'manual', [
                'matched_by' => $matchedBy . '_google_places_best_cached',
                'previous_normalized_id' => $pendingRow?->id,
                'cached_place_id' => $pendingRow?->place_id,
                'top_score' => $bestScore,
                'gap' => $gap,
                'suggested_place_id' => $best['place_id'] ?? null,
                'raw_geo' => $rawGeo,
                'candidate_geo' => $this->geoRegistry->extractFromComponents(
                    $best['components'] ?? [],
                    $best['formatted_address'] ?? null
                ),
            ]);
        } else {
            $this->log($tenantId, $raw, null, $pendingRow?->id, 'manual', [
                'matched_by' => $matchedBy . '_google_places',
                'cached_place_id' => $pendingRow?->place_id,
                'top_score' => $bestScore,
                'gap' => $gap,
                'candidates_count' => count($candidates),
                'raw_geo' => $rawGeo,
            ]);
        }

        return [
            'status' => 'needs_confirmation',
            'source' => 'google_places',
            'message' => 'Multiple/uncertain matches. Please select the correct address.',
            'candidates' => $candidates,
        ];
    }

    /**
     * If a NormalizedAddress has place_id but no master_address_id,
     * we must NOT auto-validate. We return needs_confirmation (cached) without hitting Google.
     */
    private function pendingMasterPromotionResponse(?int $tenantId, NormalizedAddress $row): array
    {
        $threshold = $this->promotionThreshold();
        $count = !empty($row->place_id) ? $this->confirmedCountForPlaceId($row->place_id) : 0;

        return [
            'status' => 'needs_confirmation',
            'source' => 'cached_pending_master_promotion',
            'message' => 'Confirm this address to promote it into master table.',
            'confirmed_count' => $count,
            'required_confirmations' => $threshold,
            'suggested_place_id' => $row->place_id,
            'candidates' => array_values(array_filter([
                [
                    'formatted_address' => $row->validated_address,
                    'latitude' => $row->google_lat,
                    'longitude' => $row->google_lng,
                    'place_id' => $row->place_id,
                ],
            ])),
        ];
    }

    /**
     * Increment confirmations for place_id and promote to master only when count >= threshold.
     * Returns: [confirmed_count, threshold, master|null, normalizedRow]
     */
    private function confirmPlaceIdAndMaybePromote(
        ?int $tenantId,
        string $raw,
        array $normalized,
        string $placeId,
        array $candidate,
        string $canonicalKey,
        string $canonicalHash
    ): array {
        $threshold = $this->promotionThreshold();

        return DB::transaction(function () use ($tenantId, $raw, $normalized, $placeId, $candidate, $canonicalKey, $canonicalHash, $threshold) {
            // If already linked to master via any cached row, treat as already promoted.
            $existingMasterId = NormalizedAddress::where('place_id', $placeId)
                ->whereNotNull('master_address_id')
                ->value('master_address_id');
            $master = $existingMasterId ? MasterAddress::find($existingMasterId) : null;

            // Counter (lock to prevent race conditions)
            $counter = AddressConfirmationCount::where('place_id', $placeId)->lockForUpdate()->first();
            if (!$counter) {
                // If two requests create at the same time, the unique(place_id) can throw.
                // In that case, re-fetch and continue.
                try {
                    $counter = AddressConfirmationCount::create([
                        'place_id' => $placeId,
                        'confirmation_count' => 0,
                        'first_confirmed_at' => now(),
                        'last_confirmed_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    $counter = AddressConfirmationCount::where('place_id', $placeId)->lockForUpdate()->first();
                    if (!$counter) {
                        throw $e;
                    }
                }
            }

            $counter->confirmation_count = (int) $counter->confirmation_count + 1;
            $counter->first_confirmed_at = $counter->first_confirmed_at ?: now();
            $counter->last_confirmed_at = now();
            $counter->save();

            // Promote to master only when eligible (and not already promoted)
            if (!$master && (int) $counter->confirmation_count >= $threshold) {
                $formatted = $candidate['formatted_address'] ?? $raw;

                $master = MasterAddress::firstOrCreate(
                    ['formatted_address' => $formatted],
                    [
                        'google_lat' => $candidate['latitude'] ?? null,
                        'google_lng' => $candidate['longitude'] ?? null,
                        'source' => 'api',
                        'validation_count' => $threshold,
                        'is_trusted' => true,
                        'concordance_level' => 0,
                        'last_validated_at' => now(),
                        'tenant_id' => $tenantId,
                    ]
                );

                // Ensure trusted even if it already existed
                $master->validation_count = max((int) $master->validation_count, $threshold);
                $master->is_trusted = true;
                $master->last_validated_at = now();
                $master->tenant_id = $master->tenant_id ?? $tenantId;
                if (empty($master->google_lat)) $master->google_lat = $candidate['latitude'] ?? null;
                if (empty($master->google_lng)) $master->google_lng = $candidate['longitude'] ?? null;
                $master->save();

                // Link all cached normalized rows for this place_id to the master
                NormalizedAddress::where('place_id', $placeId)
                    ->update(['master_address_id' => $master->id]);
            }

            // Upsert the canonical row for this raw address
            $norm = $this->upsertNormalized(
                $normalized,
                $master,
                $candidate['formatted_address'] ?? null,
                $candidate['latitude'] ?? null,
                $candidate['longitude'] ?? null,
                $canonicalKey,
                $canonicalHash,
                $placeId
            );

            if ($master && empty($norm->master_address_id)) {
                $norm->master_address_id = $master->id;
                $norm->save();
            }

            return [(int) $counter->confirmation_count, $threshold, $master, $norm];
        });
    }

    private function sanitizeTenantId(?int $tenantId): ?int
    {
        if (empty($tenantId)) {
            return null;
        }

        return \App\Models\Tenant::whereKey($tenantId)->exists() ? $tenantId : null;
    }

    private function normalizedValidResponse(string $source, NormalizedAddress $normRow, ?int $confidence = null): array
    {
        $response = [
            'status' => 'valid',
            'source' => $source,
            'data' => [
                'normalize_id' => $normRow->id,
                'master_address_id' => $normRow->master_address_id,
                'formatted_address' => $normRow->validated_address ?? $normRow->masterAddress?->formatted_address,
                'latitude' => $normRow->google_lat ?? $normRow->masterAddress?->google_lat,
                'longitude' => $normRow->google_lng ?? $normRow->masterAddress?->google_lng,
            ],
        ];

        if ($confidence !== null) {
            $response['confidence'] = $confidence;
        }

        return $response;
    }

    private function shouldUseNormalizedCandidate(array $rawGeo, NormalizedAddress $row): bool
    {
        return $this->geoRegistry->matches($rawGeo, $this->extractGeoFromNormalized($row));
    }

    private function shouldUseMasterCandidate(array $rawGeo, MasterAddress $master): bool
    {
        return $this->geoRegistry->matches($rawGeo, $this->extractGeoFromMaster($master));
    }

    private function extractGeoFromNormalized(NormalizedAddress $row): array
    {
        $sources = array_values(array_filter([
            $row->validated_address,
            $row->masterAddress?->formatted_address,
            $row->original_address,
            $row->canonical_key,
        ]));

        foreach ($sources as $source) {
            $geo = $this->geoRegistry->extractFromText($source);
            if ($this->geoRegistry->hasGeo($geo)) {
                return $geo;
            }
        }

        return $this->geoRegistry->extractFromText($row->validated_address ?? $row->original_address ?? '');
    }

    private function extractGeoFromMaster(MasterAddress $master): array
    {
        return $this->geoRegistry->extractFromText($master->formatted_address ?? '');
    }

    private function logGeoRejectedCandidate(
        ?int $tenantId,
        string $raw,
        array $rawGeo,
        string $matchedBy,
        ?NormalizedAddress $norm = null,
        ?MasterAddress $master = null,
        array $extra = []
    ): void {
        $candidateGeo = $norm ? $this->extractGeoFromNormalized($norm) : ($master ? $this->extractGeoFromMaster($master) : ['has_geo' => false]);

        $this->log($tenantId, $raw, $master?->id ?? $norm?->master_address_id, $norm?->id, 'manual', array_merge([
            'matched_by' => $matchedBy,
            'geo_gate' => 'candidate_rejected',
            'raw_geo' => $rawGeo,
            'candidate_geo' => $candidateGeo,
            'reasons' => $this->geoRegistry->mismatchReasons($rawGeo, $candidateGeo),
        ], $extra));
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
        string $canonicalHash,
        ?string $placeId = null
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
                'place_id'           => $placeId,
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
