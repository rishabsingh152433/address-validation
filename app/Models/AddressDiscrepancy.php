<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AddressDiscrepancy extends Model
{
    use HasFactory,SoftDeletes;

     protected $fillable = [
        'master_address_id','discrepancy_count','last_discrepancy_at','requires_admin_validation'
    ];

    protected $casts = [
        'last_discrepancy_at' => 'datetime',
        'requires_admin_validation' => 'bool',
    ];

    public function masterAddress() { return $this->belongsTo(MasterAddress::class); }

}
