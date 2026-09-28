<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Models;

use Illuminate\Database\Eloquent\Model;

class PikuProductBlurb extends Model
{
    protected $table = 'piku_product_blurbs';

    protected $fillable = [
        'product_id',
        'lang',
        'text',
        'source_hash',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];
}
