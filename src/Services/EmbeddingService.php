<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-agnostic embedding service.
 *
 * All calls go through the OpenAI-compatible /v1/embeddings endpoint, which is
 * supported by Ollama, OpenAI, DeepSeek, OpenRouter and others. The active
 * provider/model can be switched at runtime via AgentSettingsService.
 *
 * Returned vectors must match the Qdrant collection dimension. Changing the
 * embedding provider therefore requires recreating collections and reindexing.
 */
class EmbeddingService
{
    public function __construct(
        private readonly AgentSettingsService $settings,
    ) {}

    /**
     * Embed a single text with the active provider.
     */
    public function embed(string $text): array
    {
        $vectors = $this->embedBulk([$text]);

        return $vectors[0] ?? [];
    }

    /**
     * Embed multiple texts in a single API call.
     *
     * @param  string[]  $texts
     * @return array<int,array<int,float>>
     */
    public function embedBulk(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $baseUrl = rtrim((string) $this->settings->get('embedding_base_url', config('gunma-agent.embedding.base_url')), '/');
        $apiKey  = (string) $this->settings->get('embedding_api_key', config('gunma-agent.embedding.api_key'));
        $model   = (string) $this->settings->get('embedding_model', config('gunma-agent.embedding.model'));

        $request = Http::timeout(60)->acceptJson();

        // Local Ollama ignores the key, but sending a dummy is harmless; skip it
        // entirely when blank to avoid confusing strict gateways.
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $response = $request->post("{$baseUrl}/embeddings", [
            'input' => $texts,
            'model' => $model,
        ]);

        if (! $response->ok()) {
            Log::error('[EmbeddingService] Embedding failed', [
                'provider' => $this->settings->get('embedding_provider'),
                'model'    => $model,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            throw new \RuntimeException('Embedding failed: ' . $response->body());
        }

        return collect($response->json('data') ?? [])
            ->sortBy('index')
            ->pluck('embedding')
            ->all();
    }

    /* ── Backwards-compatible aliases ──────────────────────────── */

    public function openaiEmbed(string $text): array
    {
        return $this->embed($text);
    }

    public function ollamaEmbed(string $text): array
    {
        return $this->embed($text);
    }

    public function openaiEmbedBulk(array $texts): array
    {
        return $this->embedBulk($texts);
    }
}
