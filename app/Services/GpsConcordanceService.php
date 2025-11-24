<?php


namespace App\Services;

use App\Models\GpsEvent;
use App\Models\MasterAddress;
use App\Models\AddressDiscrepancy;

class GpsConcordanceService
{
    public function recordEvent(int $masterAddressId, float $deliveryLat, float $deliveryLng): GpsEvent
    {
        $addr = MasterAddress::findOrFail($masterAddressId);
        $googleLat = (float) $addr->google_lat;
        $googleLng = (float) $addr->google_lng;

        $meters = $this->haversineMeters($deliveryLat, $deliveryLng, $googleLat, $googleLng);
        $threshold = (float) config('address_validation.gps_concordance_meters', 50);

        $event = GpsEvent::create([
            'master_address_id' => $masterAddressId,
            'delivery_lat' => $deliveryLat,
            'delivery_lng' => $deliveryLng,
            'google_lat' => $googleLat,
            'google_lng' => $googleLng,
            'discrepancy_meters' => $meters,
            'event_count' => 1,
            'requires_admin_validation' => $meters > $threshold,
        ]);

        $this->updateDiscrepancyAggregate($addr, $meters, $event->requires_admin_validation);

        return $event;
    }

    protected function updateDiscrepancyAggregate(MasterAddress $addr, float $meters, bool $flag): void
    {
        $agg = AddressDiscrepancy::firstOrCreate(
            ['master_address_id' => $addr->id],
            ['discrepancy_count' => 0, 'requires_admin_validation' => false]
        );
        $agg->discrepancy_count += ($meters > 0 ? 1 : 0);
        if ($flag) $agg->requires_admin_validation = true;
        $agg->last_discrepancy_at = now();
        $agg->save();

        // optionally adjust master concordance level
        $addr->concordance_level = max((int) $addr->concordance_level, $flag ? 1 : 0);
        $addr->save();
    }

    protected function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000; // meters
        $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
        $dphi = deg2rad($lat2 - $lat1); $dl = deg2rad($lon2 - $lon1);
        $a = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dl/2)**2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }
}


