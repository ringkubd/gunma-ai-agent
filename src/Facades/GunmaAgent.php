<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Facades;

use Illuminate\Support\Facades\Facade;
use Anwar\GunmaAgent\Services\AgentOrchestrator;

/**
 * @method static string chat(\Anwar\GunmaAgent\Models\ChatSession $session, string $userMessage)
 * @method static \Generator chatStream(\Anwar\GunmaAgent\Models\ChatSession $session, string $userMessage)
 *
 * @see \Anwar\GunmaAgent\Services\AgentOrchestrator
 */
class GunmaAgent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AgentOrchestrator::class;
    }
}
