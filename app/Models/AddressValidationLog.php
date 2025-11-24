<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AddressValidationLog extends Model
{
    use HasFactory,SoftDeletes;

   protected $fillable = [
        'master_address_id', 'normalized_address_id', 'tenant_id',
        'validation_type', 'raw_payload', 'created_by'
    ];

    protected $casts = [ 'raw_payload' => 'array' ];

    public function masterAddress() { return $this->belongsTo(MasterAddress::class); }
    public function normalizedAddress() { return $this->belongsTo(NormalizedAddress::class); }
    public function tenant() { return $this->belongsTo(Tenant::class); }

}
