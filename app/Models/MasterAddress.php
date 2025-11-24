<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterAddress extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = [
        'formatted_address', 'google_lat', 'google_lng', 'source',
        'validation_count', 'is_trusted', 'concordance_level',
        'last_validated_at', 'final_navigation_coordinate_lat',
        'final_navigation_coordinate_lng', 'created_from_history', 'tenant_id'
    ];

    protected $casts = [
        'google_lat' => 'float',
        'google_lng' => 'float',
        'is_trusted' => 'bool',
        'created_from_history' => 'bool',
        'last_validated_at' => 'datetime',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function normalizedAddresses() { return $this->hasMany(NormalizedAddress::class); }
    public function validationLogs() { return $this->hasMany(AddressValidationLog::class); }
    public function discrepancies() { return $this->hasMany(AddressDiscrepancy::class); }

    public function gpsEvents(){ return $this->hasMany(GpsEvent::class, 'master_address_id');}

}