<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NormalizedAddress extends Model
{
    use HasFactory,SoftDeletes;
    protected $fillable = [
        'original_address', 'validated_address', 'normalized_key','canonical_key', 'canonical_key_hash',
        'street', 'number', 'unit', 'google_lat', 'google_lng','master_address_id','place_id'
    ];
     protected $casts = [
        'google_lat' => 'float',
        'google_lng' => 'float',
    ];

    public function masterAddress() { return $this->belongsTo(MasterAddress::class); }

}