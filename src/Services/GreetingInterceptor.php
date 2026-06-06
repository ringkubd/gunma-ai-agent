<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

class GreetingInterceptor
{
    private const GREETINGS = [
        'hi'         => null,
        'hello'      => null,
        'hey'        => null,
        'hey there'  => null,
        'good morning' => null,
        'good afternoon' => null,
        'good evening' => null,
        'asalam o alikum' => null,
        'assalamu alaikum' => null,
        'salam'      => null,
        'thank you'  => 'thanks',
        'thanks'     => 'thanks',
        'ty'         => 'thanks',
        'bye'        => 'bye',
        'goodbye'    => 'bye',
    ];

    public function intercept(string $query, ?array $userContext = null): ?string
    {
        $clean = strtolower(trim(preg_replace('/[?!.,]/', '', $query)));
        $type = self::GREETINGS[$clean] ?? null;

        // Direct greeting match
        if ($type === null && isset(self::GREETINGS[$clean])) {
            return $this->buildGreeting($userContext);
        }

        if ($type === 'thanks') {
            return "You're very welcome" . ($userContext['name'] ? ' ' . $userContext['name'] : '') . "! Let me know if you need anything else.";
        }

        if ($type === 'bye') {
            return 'Goodbye' . ($userContext['name'] ? ' ' . $userContext['name'] : '') . '! Have a great day and come back soon!';
        }

        return null;
    }

    private function buildGreeting(?array $ctx): string
    {
        $hour = (int) date('H');
        $timeGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

        $name = $ctx['name'] ?? null;
        $isReturning = ($ctx['previous_orders'] ?? 0) > 0;

        if ($name && $isReturning) {
            return "{$timeGreeting}, {$name}! Welcome back to Gunma Halal Food. How can I help you today?";
        }

        if ($name) {
            return "{$timeGreeting}, {$name}! This is Piku from Gunma Halal Food. How may I assist you today?";
        }

        return "{$timeGreeting}! This is Piku from Gunma Halal Food Customer Support. How may I assist you today?";
    }
}
