<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves LLM/embedding provider settings at runtime.
 *
 * Precedence: DB value (agent_settings) → config (env) → hard-coded default.
 * Cached permanently; call flush() after changing any key.
 *
 * The goal is to allow switching between any OpenAI-compatible provider
 * (Ollama, OpenAI, DeepSeek, OpenRouter, Groq, Gemini compatibility gateway …)
 * from the admin dashboard without a deploy.
 */
class AgentSettingsService
{
    private const CACHE_KEY = 'gunma_agent_settings';

    /** Keys that are resolvable and their defaults. */
    public const KEYS = [
        'llm_provider',
        'llm_base_url',
        'llm_api_key',
        'llm_model',
        'llm_fallback_enabled',
        'llm_fallback_base_url',
        'llm_fallback_api_key',
        'llm_fallback_model',
        'embedding_provider',
        'embedding_base_url',
        'embedding_api_key',
        'embedding_model',
        'embedding_dims',
    ];

    /** @var array<string,string|null>|null */
    private ?array $settings = null;

    public function all(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $db = [];
        try {
            $db = DB::table('agent_settings')->pluck('value', 'key')->toArray();
        } catch (\Throwable $e) {
            // Table may not exist yet (pre-migration) — fall back to config only.
            Log::debug('[AgentSettings] DB read skipped', ['error' => $e->getMessage()]);
        }

        $this->settings = array_replace($this->configDefaults(), $db);

        return $this->settings;
    }

    public function get(string $key, $default = null)
    {
        $value = $this->all()[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function set(string $key, ?string $value): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown agent setting: {$key}");
        }

        DB::table('agent_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
        );

        $this->flush();
    }

    public function flush(): void
    {
        $this->settings = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Provider presets for the admin UI.
     */
    public static function presets(): array
    {
        return [
            'ollama'     => ['label' => 'Ollama (local / Ollama Cloud)', 'base_url' => 'http://127.0.0.1:11434/v1', 'embed_base_url' => 'http://127.0.0.1:11434/v1'],
            'openai'     => ['label' => 'OpenAI',                        'base_url' => 'https://api.openai.com/v1',  'embed_base_url' => 'https://api.openai.com/v1'],
            'deepseek'   => ['label' => 'DeepSeek',                      'base_url' => 'https://api.deepseek.com/v1', 'embed_base_url' => 'https://api.deepseek.com/v1'],
            'openrouter' => ['label' => 'OpenRouter',                    'base_url' => 'https://openrouter.ai/api/v1', 'embed_base_url' => 'https://openrouter.ai/api/v1'],
            'groq'       => ['label' => 'Groq',                          'base_url' => 'https://api.groq.com/openai/v1', 'embed_base_url' => 'https://api.groq.com/openai/v1'],
            'gemini'     => ['label' => 'Google Gemini (OpenAI-compatible)', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'embed_base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai'],
        ];
    }

    private function configDefaults(): array
    {
        return [
            'llm_provider'           => config('gunma-agent.llm.provider', 'ollama'),
            'llm_base_url'           => config('gunma-agent.llm.base_url'),
            'llm_api_key'            => config('gunma-agent.llm.api_key'),
            'llm_model'              => config('gunma-agent.llm.model'),
            'llm_fallback_enabled'   => config('gunma-agent.llm.fallback_enabled') ? '1' : '0',
            'llm_fallback_base_url'  => config('gunma-agent.llm.fallback_base_url'),
            'llm_fallback_api_key'   => config('gunma-agent.llm.fallback_api_key'),
            'llm_fallback_model'     => config('gunma-agent.llm.fallback_model'),
            'embedding_provider'     => config('gunma-agent.embedding.provider', 'ollama'),
            'embedding_base_url'     => config('gunma-agent.embedding.base_url'),
            'embedding_api_key'      => config('gunma-agent.embedding.api_key'),
            'embedding_model'        => config('gunma-agent.embedding.model'),
            'embedding_dims'         => (string) config('gunma-agent.embedding.dims', 768),
        ];
    }
}
