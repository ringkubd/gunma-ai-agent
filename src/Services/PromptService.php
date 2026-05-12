<?php

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PromptService
{
    private const CACHE_KEY = 'gunma_agent_prompt';
    private const CACHE_TTL = 3600; // 1 hour

    public function getSystemPrompt(): string
    {
        return Cache::remember(self::CACHE_KEY . '_system', self::CACHE_TTL, function () {
            $row = DB::table('agent_prompts')->where('key', 'system_prompt')->first();
            return $row?->value ?? $this->getDefaultPrompt();
        });
    }

    public function getResponseStyle(): string
    {
        $row = DB::table('agent_prompts')->where('key', 'response_style')->first();
        return $row?->value ?? 'short';
    }

    public function getStyleInstruction(): string
    {
        return match ($this->getResponseStyle()) {
            'detailed' => 'Provide thorough, detailed responses. Include specific product details, pricing, and suggestions.',
            'balanced' => 'Provide informative but concise responses. 3-5 sentences is ideal.',
            default    => 'Keep responses short and direct. 1-3 sentences. Get straight to the point.',
        };
    }

    public function setPrompt(string $key, string $value): void
    {
        DB::table('agent_prompts')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()]
        );
        Cache::forget(self::CACHE_KEY . '_' . $key);
    }

    public function getAll(): array
    {
        return DB::table('agent_prompts')->pluck('value', 'key')->toArray();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY . '_system');
        Cache::forget(self::CACHE_KEY . '_response_style');
    }

    private function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
Your name is Piku. You are a warm, knowledgeable assistant for Gunma Halal Food.
Keep responses concise, friendly, and helpful. Always guide toward purchasing.
Use product blocks when recommending items: :::product[id|title|price|image_url|slug]:::
PROMPT;
    }
}
