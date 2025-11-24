<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class GpsEvent extends Model
{
    use HasFactory,SoftDeletes;

     protected $fillable = [
        'event_id','master_address_id','delivery_lat','delivery_lng','google_lat','google_lng',
        'discrepancy_meters','event_count','requires_admin_validation'
    ];

       protected $casts = [
        'delivery_lat' => 'float','delivery_lng' => 'float',
        'google_lat' => 'float','google_lng' => 'float',
        'discrepancy_meters' => 'float',
        'event_count' => 'int',
        'requires_admin_validation' => 'bool',
    ];

    public function masterAddress() { return $this->belongsTo(MasterAddress::class); }


    public function gpsEvents()
{
    return $this->hasMany(GpsEvent::class, 'master_address_id');
}

}