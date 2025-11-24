<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\GpsEventRequest;
use App\Services\GpsConcordanceService;

class GpsEventController extends Controller
{
    public function __construct(private GpsConcordanceService $gps) {}

    public function store(GpsEventRequest $request)
    {
        $data = $request->validated();
        $event = $this->gps->recordEvent(
            masterAddressId: $data['master_address_id'],
            deliveryLat: (float) $data['delivery_lat'],
            deliveryLng: (float) $data['delivery_lng']
        );
        return response()->json(['success' => true, 'data' => $event]);
    }
}
