<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Models;

use Illuminate\Database\Eloquent\Model;

class PikuEvent extends Model
{
    protected $table = 'piku_events';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'session_id',
        'customer_id',
        'visitor_id',
        'product_id',
        'order_id',
        'tool',
        'value',
        'lang',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'value'      => 'float',
        'created_at' => 'datetime',
    ];
}
