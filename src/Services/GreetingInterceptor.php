<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

/**
 * Zero-cost instant replies for common greetings — bypasses LLM entirely.
 * Ported from agent_server.js LOCAL_GREETINGS.
 */
class GreetingInterceptor
{
    private const GREETINGS = [
        'hi'               => 'Hello, this is Piku from Gunma Halal Food Customer Support. How may I assist you today?',
        'hello'            => 'Hello, this is Piku from Gunma Halal Food Customer Support. How may I assist you today?',
        'hey'              => 'Hello, this is Piku from Gunma Halal Food Customer Support. How may I assist you today?',
        'thanks'           => "You're very welcome! Let me know if you need anything else.",
        'thank you'        => "You're very welcome! Happy to help.",
        'bye'              => 'Goodbye! Have a great day and come back soon!',
        'goodbye'          => 'Goodbye! We hope to see you again soon!',
        'asalam o alikum'  => 'Walaikum Assalam! How can I help you today?',
        'assalamu alaikum' => 'Walaikum Assalam! How can I help you today?',
        'salam'            => 'Walaikum Assalam! How can I help you today?',
    ];

    /**
     * Check if the query matches a greeting and return instant reply.
     * Returns null if no greeting match (proceed to AI).
     */
    public function intercept(string $query): ?string
    {
        $clean = strtolower(trim(preg_replace('/[?!.]/', '', $query)));

        return self::GREETINGS[$clean] ?? null;
    }
}
