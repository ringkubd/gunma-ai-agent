<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use HasUuids;

    protected $table = 'support_tickets';

    protected $fillable = [
        'session_id',
        'customer_id',
        'name',
        'email',
        'phone',
        'order_id',
        'issue_type',
        'subject',
        'message',
        'status',
        'priority_score',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
