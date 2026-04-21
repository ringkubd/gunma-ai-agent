<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unified embedding service — Ollama (768d) for recipes/KB, OpenAI (1536d) for products.
 */
class EmbeddingService
{
    public function __construct(
        private readonly string $ollamaUrl,
        private readonly string $ollamaModel,
        private readonly string $openaiKey,
        private readonly string $openaiBaseUrl,
        private readonly string $openaiEmbedModel,
    ) {}

    /* ── Ollama (768 dimensions) ───────────────────────────────── */

    public function ollamaEmbed(string $text): array
    {
        $response = Http::timeout(30)
            ->post("{$this->ollamaUrl}/api/embeddings", [
                'model'  => $this->ollamaModel,
                'prompt' => $text,
            ]);

        if (! $response->ok()) {
            Log::error('[EmbeddingService] Ollama embed failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Ollama embedding failed: ' . $response->body());
        }

        return $response->json('embedding');
    }

    /* ── OpenAI (1536 dimensions) ──────────────────────────────── */

    public function openaiEmbed(string $text): array
    {
        $url = rtrim($this->openaiBaseUrl, '/') . '/embeddings';

        $response = Http::withToken($this->openaiKey)
            ->timeout(30)
            ->post($url, [
                'input' => $text,
                'model' => $this->openaiEmbedModel,
            ]);

        if (! $response->ok()) {
            Log::error('[EmbeddingService] OpenAI embed failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('OpenAI embedding failed: ' . $response->body());
        }

        return $response->json('data.0.embedding');
    }

    /**
     * Bulk OpenAI embeddings in a single API call.
     */
    public function openaiEmbedBulk(array $texts): array
    {
        $url = rtrim($this->openaiBaseUrl, '/') . '/embeddings';

        $response = Http::withToken($this->openaiKey)
            ->timeout(60)
            ->post($url, [
                'input' => $texts,
                'model' => $this->openaiEmbedModel,
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException('OpenAI bulk embedding failed: ' . $response->body());
        }

        return collect($response->json('data'))
            ->sortBy('index')
            ->pluck('embedding')
            ->all();
    }
}
