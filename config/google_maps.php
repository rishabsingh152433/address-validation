<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Maps / Geocoding API configuration
    |--------------------------------------------------------------------------
    |
    | API key and request settings used by GoogleMapsClient.
    |
    */
    'api_key' => env('GOOGLE_MAPS_API_KEY'),
    'timeout' => (int) env('GOOGLE_MAPS_TIMEOUT', 10),
    

    // base endpoints (change if you route via proxy)
    'geocode_url' => env('GOOGLE_MAPS_GEOCODE_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
    'place_details_url' => env('GOOGLE_MAPS_PLACE_DETAILS_URL', 'https://maps.googleapis.com/maps/api/place/details/json'),

    // HTTP client settings
    'timeout_seconds' => env('GOOGLE_MAPS_TIMEOUT', 10),

    // Simple rate control values; implement enforcement in client if needed
    'rate_limit_per_second' => env('GOOGLE_MAPS_RATE_LIMIT_PER_SECOND', 5),

    // Whether to enable strict result verification (e.g., require country/postal_code)
    'strict_result_verification' => env('GOOGLE_MAPS_STRICT_VERIFY', false),
];
