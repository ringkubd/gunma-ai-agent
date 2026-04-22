<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public mixed $ticket,
        public array $args
    ) {}
}
