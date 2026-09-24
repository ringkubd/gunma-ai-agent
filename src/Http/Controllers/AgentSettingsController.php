<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Http\Controllers;

use Anwar\GunmaAgent\Services\AgentSettingsService;
use Anwar\GunmaAgent\Services\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

/**
 * Admin API for switching the LLM / embedding provider at runtime.
 *
 * GET  /api/admin/chat/settings/llm
 * PUT  /api/admin/chat/settings/llm
 * GET  /api/admin/chat/models
 * POST /api/admin/chat/settings/test
 * POST /api/admin/chat/reindex
 */
class AgentSettingsController extends Controller
{
    public function __construct(
        private readonly AgentSettingsService $settings,
        private readonly EmbeddingService $embeddings,
    ) {}

    public function show(): JsonResponse
    {
        $all = $this->settings->all();

        // Never leak raw API keys — mask them.
        foreach (['llm_api_key', 'llm_fallback_api_key', 'embedding_api_key'] as $k) {
            if (! empty($all[$k])) {
                $all[$k] = $this->mask((string) $all[$k]);
            }
        }

        return response()->json([
            'data'    => $all,
            'presets' => AgentSettingsService::presets(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'llm_provider'            => 'nullable|string|max:50',
            'llm_base_url'            => 'nullable|url|max:255',
            'llm_api_key'             => 'nullable|string|max:500',
            'llm_model'               => 'nullable|string|max:150',
            'llm_fallback_enabled'    => 'nullable|boolean',
            'llm_fallback_base_url'   => 'nullable|url|max:255',
            'llm_fallback_api_key'    => 'nullable|string|max:500',
            'llm_fallback_model'      => 'nullable|string|max:150',
            'embedding_provider'      => 'nullable|string|max:50',
            'embedding_base_url'      => 'nullable|url|max:255',
            'embedding_api_key'       => 'nullable|string|max:500',
            'embedding_model'         => 'nullable|string|max:150',
            'embedding_dims'          => 'nullable|integer|min:64|max:4096',
        ]);

        $dimsChanged = false;
        foreach ($validated as $key => $value) {
            // Ignore masked/sentinel values so we don't overwrite stored keys with "••••".
            if (($key === 'llm_api_key' || $key === 'llm_fallback_api_key' || $key === 'embedding_api_key')
                && is_string($value) && str_contains($value, '•')) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if ($key === 'embedding_dims' && (int) $value !== $this->settings->int('embedding_dims', 768)) {
                $dimsChanged = true;
            }
            $this->settings->set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return response()->json([
            'message'      => 'Settings updated.',
            'dims_changed' => $dimsChanged,
            'warning'      => $dimsChanged
                ? 'Embedding dimensions changed. Run the reindex endpoint and recreate Qdrant collections before new searches will work.'
                : null,
        ]);
    }

    /**
     * List models available on the configured LLM provider (/v1/models).
     */
    public function models(Request $request): JsonResponse
    {
        $baseUrl = rtrim((string) $request->query('base_url', $this->settings->get('llm_base_url', config('gunma-agent.llm.base_url'))), '/');
        $apiKey  = (string) $request->query('api_key', $this->settings->get('llm_api_key', config('gunma-agent.llm.api_key')));

        $req = Http::timeout(15)->acceptJson();
        if ($apiKey !== '') $req = $req->withToken($apiKey);

        try {
            $res = $req->get("{$baseUrl}/models");
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not reach provider: ' . $e->getMessage()], 502);
        }

        if (! $res->ok()) {
            return response()->json(['error' => 'Provider returned ' . $res->status(), 'body' => mb_substr($res->body(), 0, 500)], 502);
        }

        $ids = collect($res->json('data') ?? [])->pluck('id')->values()->all();

        return response()->json(['data' => $ids]);
    }

    /**
     * Connectivity + tool-call test against a provider.
     */
    public function test(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'base_url' => 'nullable|url|max:255',
            'api_key'  => 'nullable|string|max:500',
            'model'    => 'nullable|string|max:150',
        ]);

        $baseUrl = rtrim((string) ($payload['base_url'] ?? $this->settings->get('llm_base_url', config('gunma-agent.llm.base_url'))), '/');
        $apiKey  = (string) ($payload['api_key'] ?? $this->settings->get('llm_api_key', config('gunma-agent.llm.api_key')));
        $model   = (string) ($payload['model'] ?? $this->settings->get('llm_model', config('gunma-agent.llm.model')));

        $req = Http::timeout(60)->acceptJson();
        if ($apiKey !== '') $req = $req->withToken($apiKey);

        try {
            $res = $req->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => 'Reply with the single word: ok'],
                ],
                'max_tokens' => 20,
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        if (! $res->ok()) {
            return response()->json(['ok' => false, 'status' => $res->status(), 'body' => mb_substr($res->body(), 0, 500)], 502);
        }

        return response()->json([
            'ok'      => true,
            'model'   => $model,
            'reply'   => $res->json('choices.0.message.content'),
            'usage'   => $res->json('usage'),
        ]);
    }

    /**
     * Trigger a full reindex (products + purchase history) into Qdrant.
     * Dispatched to the queue so large catalogs do not block the request.
     */
    public function reindex(Request $request): JsonResponse
    {
        $type = $request->input('type', 'all');

        \Anwar\GunmaAgent\Jobs\ReindexQdrant::dispatch($type);

        return response()->json([
            'message' => "Reindex ({$type}) queued. Embeddings will be regenerated in the background.",
        ]);
    }

    private function mask(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }
        return substr($value, 0, 4) . str_repeat('•', 8) . substr($value, -4);
    }
}
