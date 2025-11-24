<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = ['name','slug'];
    protected $casts = ['config' => 'array'];
    public function masterAddresses() { return $this->hasMany(MasterAddress::class); }
    public function validationLogs() { return $this->hasMany(AddressValidationLog::class); }
}
