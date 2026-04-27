<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'symbol',
        'side',
        'strategy',
        'executed_quantity',
        'fill_price',
        'status',
    ];

    protected $casts = [
        'executed_quantity' => 'float',
        'fill_price' => 'float',
    ];
}
