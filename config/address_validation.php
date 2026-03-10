<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trust and concordance thresholds
    |--------------------------------------------------------------------------
    |
    | Tunable thresholds used by AddressValidationService and GpsConcordanceService.
    | Keep these in config so you can change per-environment without code changes.
    |
    */
    'trust_threshold' => env('ADDRESS_TRUST_THRESHOLD', 5),            // validations required to mark trusted
    'gps_concordance_meters' => env('GPS_CONCORDANCE_METERS', 50),     // meters for concordance
    'gps_discrepancy_events_threshold' => env('GPS_DISCREPANCY_THRESHOLD', 10), // events before admin review
     // matching + google
     'max_candidates' => env('ADDRESS_MAX_CANDIDATES', 5),
    'auto_pick_min_score' => env('ADDRESS_AUTO_PICK_MIN_SCORE', 80),
    'auto_pick_min_gap' => env('ADDRESS_AUTO_PICK_MIN_GAP', 15),

    // DB fuzzy
    'db_fuzzy_enabled' => env('ADDRESS_DB_FUZZY_ENABLED', true),
    'db_fuzzy_candidate_limit' => env('ADDRESS_DB_FUZZY_CANDIDATE_LIMIT', 50),
    'db_fuzzy_min_score' => env('ADDRESS_DB_FUZZY_MIN_SCORE', 80),
    'db_fuzzy_min_gap' => env('ADDRESS_DB_FUZZY_MIN_GAP', 10),
    'db_fuzzy_min_overlap' => env('ADDRESS_DB_FUZZY_MIN_OVERLAP', 0.75),
     // optional: 1.5M scale pe master LIKE ko off kar sakte ho
     'master_like_enabled' => env('ADDRESS_MASTER_LIKE_ENABLED', true),
    /*
    |--------------------------------------------------------------------------
    | Normalization / abbreviation mapping
    |--------------------------------------------------------------------------
    |
    | Common abbreviations -> canonical form used by the AddressNormalizationService.
    | Add locale-specific mappings as required.
    |
    */
    'abbreviation_map' => [
        'av' => 'avenida',
        'ave' => 'avenue',
        'av.' => 'avenida',
        'avda' => 'avenida',
        'st' => 'street',
        'st.' => 'street',
        'rd' => 'road',
        'rd.' => 'road',
        'apt' => 'apartment',
        'apt.' => 'apartment',
        'of' => 'office',
        'of.' => 'office',
        'ofc' => 'office',
        'suite' => 'suite',
        'ste' => 'suite',
        'fl' => 'floor',
        'bldg' => 'building',
        'no' => 'number',
    ],

    /*
    |--------------------------------------------------------------------------
    | Normalizer settings
    |--------------------------------------------------------------------------
    |
    | Settings controlling normalization behaviour.
    |
    */
    'normalizer' => [
        'remove_punctuation' => true,
        'collapse_spaces' => true,
        'lowercase' => true,
        // fields to extract into components if parsing logic supports it
        'extract_components' => ['street', 'number', 'unit'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Import & caching
    |--------------------------------------------------------------------------
    |
    | Chunk sizes for bootstrapping historical LMT imports and cache TTLs.
    |
    */
    'lmt_import' => [
        'chunk_size' => env('LMT_IMPORT_CHUNK_SIZE', 1000),
    ],

    'cache' => [
        'normalized_ttl_seconds' => env('NORMALIZED_CACHE_TTL', 86400), // 1 day
    ],
    'geo_registry_path' => env('ADDRESS_GEO_REGISTRY_PATH', resource_path('data/chile_regions_comunas.json')),
];
