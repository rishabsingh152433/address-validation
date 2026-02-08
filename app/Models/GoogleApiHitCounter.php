<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleApiHitCounter extends Model
{
    protected $fillable = ['hit_date', 'endpoint', 'hits'];

    protected $casts = [
        'hit_date' => 'date',
        'hits' => 'int',
    ];
}
