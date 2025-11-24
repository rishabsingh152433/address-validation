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
];
