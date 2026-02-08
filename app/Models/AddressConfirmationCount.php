<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddressConfirmationCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'place_id',
        'confirmation_count',
        'first_confirmed_at',
        'last_confirmed_at',
    ];

    protected $casts = [
        'confirmation_count' => 'int',
        'first_confirmed_at' => 'datetime',
        'last_confirmed_at'  => 'datetime',
    ];
}
