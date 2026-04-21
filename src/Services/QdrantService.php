<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Qdrant vector search service — mirrors search_tools.js functionality.
 * Collections: products (1536d OpenAI), recipes (768d Ollama), gunmahal_kb (768d Ollama).
 */
class QdrantService
{
    private array $collections;

    public function __construct(
        private readonly string           $qdrantUrl,
        private readonly EmbeddingService $embeddingService,
    ) {
        $this->collections = config('gunma-agent.qdrant_collections');
    }

    /* ── Product Search (OpenAI embeddings, 1536d) ─────────────── */

    public function searchProducts(string $query, int $limit = 5): array
    {
        $vector = $this->embeddingService->openaiEmbed($query);

        return $this->vectorSearch($this->collections['products'], $vector, $limit);
    }

    /**
     * Bulk product search — embed all queries at once, then fan-out searches.
     */
    public function searchProductsBulk(array $queries, int $limitPerQuery = 3): array
    {
        if (empty($queries)) {
            return [];
        }

        $vectors = $this->embeddingService->openaiEmbedBulk($queries);
        $results = [];

        foreach ($queries as $index => $query) {
            $results[] = [
                'query'   => $query,
                'results' => $this->vectorSearch($this->collections['products'], $vectors[$index], $limitPerQuery),
            ];
        }

        return $results;
    }

    /* ── Recipe Search (Ollama embeddings, 768d) ───────────────── */

    public function searchRecipes(string $query, int $limit = 3): array
    {
        $vector = $this->embeddingService->ollamaEmbed($query);

        return $this->vectorSearch($this->collections['recipes'], $vector, $limit);
    }

    /* ── Support KB Search (Ollama embeddings, 768d) ───────────── */

    public function searchSupportKB(string $query, int $limit = 3): array
    {
        $vector = $this->embeddingService->ollamaEmbed($query);

        return $this->vectorSearch($this->collections['kb'], $vector, $limit);
    }

    /* ── Upsert Logic ──────────────────────────────────────────── */

    /**
     * Upsert a recipe (legacy method for AI loop).
     */
    public function upsertRecipe(array $recipe): void
    {
        $text   = ($recipe['title'] ?? '') . ' ' . implode(', ', $recipe['ingredients'] ?? []);
        $vector = $this->embeddingService->ollamaEmbed($text);
        
        $this->bulkUpsert($this->collections['recipes'], [
            [
                'id'      => $this->generateUuid(md5($recipe['title'] ?? microtime())),
                'vector'  => $vector,
                'payload' => $recipe,
            ]
        ]);
    }

    /**
     * Index or Update a product for real-time stock/price accuracy.
     */
    public function upsertProduct(array $product): void
    {
        $text = ($product['name'] ?? '') . ' ' . ($product['description'] ?? '');
        $vector = $this->embeddingService->openaiEmbed($text);

        $this->bulkUpsert($this->collections['products'], [
            [
                'id'      => $this->generateUuid(md5("prod_" . $product['id'])),
                'vector'  => $vector,
                'payload' => array_merge($product, ['last_updated' => now()->toIso8601String()]),
            ]
        ]);
    }

    /**
     * Index a purchase event to build a semantic profile for the customer.
     */
    public function indexOrderHistory(int $customerId, array $item): void
    {
        $text = "Customer bought: " . ($item['name'] ?? '');
        $vector = $this->embeddingService->openaiEmbed($text);

        $this->bulkUpsert($this->collections['history'], [
            [
                'id'      => $this->generateUuid(md5("order_" . ($item['id'] ?? microtime()))),
                'vector'  => $vector,
                'payload' => [
                    'customer_id' => $customerId,
                    'product_id'  => $item['product_id'] ?? null,
                    'name'        => $item['name'] ?? '',
                    'timestamp'   => now()->toIso8601String(),
                ]
            ]
        ]);
    }

    /**
     * Search for products similar to what a customer has bought before.
     */
    public function searchPersonalizedProducts(int $customerId, int $limit = 5): array
    {
        // 1. Get recent purchase vectors for this customer
        $history = $this->getCollectionPoints($this->collections['history'], [
            'must' => [['key' => 'customer_id', 'match' => ['value' => $customerId]]]
        ], 3);

        if (empty($history)) {
            return $this->searchProducts("popular halal items", $limit); // Fallback to trending
        }

        // 2. Average the vectors (simple centroid) or just take the latest
        $latestVector = $history[0]['vector'];

        // 3. Search products collection using that vector
        return $this->vectorSearch($this->collections['products'], $latestVector, $limit);
    }

    /**
     * Generic bulk upsert for Scout or other integrations.
     */
    public function bulkUpsert(string $collection, array $points): void
    {
        if (empty($points)) return;

        $response = Http::timeout(30)
            ->put("{$this->qdrantUrl}/collections/{$collection}/points", [
                'points' => $points,
            ]);

        if (! $response->ok()) {
            Log::error("[QdrantService] Bulk upsert failed on {$collection}", [
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Search semantic cache for similar query.
     */
    public function getSemanticCache(string $query): ?string
    {
        if (! config('gunma-agent.semantic_cache_enabled')) {
            return null;
        }

        $vector = $this->embeddingService->openaiEmbed($query);
        $results = $this->vectorSearch($this->collections['cache'], $vector, 1);

        if (empty($results)) {
            return null;
        }

        $match = $results[0];
        $threshold = (float) config('gunma-agent.semantic_cache_threshold', 0.95);

        if ($match['score'] >= $threshold) {
            Log::info("[QdrantService] Semantic cache hit", ['query' => $query, 'score' => $match['score']]);
            return $match['payload']['answer'] ?? null;
        }

        return null;
    }

    /**
     * Store query and answer in semantic cache.
     */
    public function setSemanticCache(string $query, string $answer): void
    {
        if (! config('gunma-agent.semantic_cache_enabled')) {
            return;
        }

        try {
            $vector = $this->embeddingService->openaiEmbed($query);
            $this->bulkUpsert($this->collections['cache'], [
                [
                    'id' => $this->generateUuid(md5($query)),
                    'vector' => $vector,
                    'payload' => [
                        'query'     => $query,
                        'answer'    => $answer,
                        'timestamp' => now()->toIso8601String(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::warning("[QdrantService] Cache storage failed", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Index a conversation memory for future RAG use.
     */
    public function indexMemory(string $sessionId, string $query, string $answer): void
    {
        try {
            $collection = $this->collections['memories'];
            $text = "Q: {$query} A: {$answer}";
            $vector = $this->embeddingService->ollamaEmbed($text);

            $this->bulkUpsert($collection, [
                [
                    'id' => $this->generateUuid(md5($sessionId . microtime())),
                    'vector' => $vector,
                    'payload' => [
                        'session_id' => $sessionId,
                        'query'      => $query,
                        'answer'     => $answer,
                        'timestamp'  => now()->toIso8601String(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::warning("[QdrantService] Memory indexing failed", ['error' => $e->getMessage()]);
        }
    }

    /* ── Helpers ───────────────────────────────────────────────── */

    private function vectorSearch(string $collection, array $vector, int $limit): array
    {
        try {
            $response = Http::timeout(15)
                ->post("{$this->qdrantUrl}/collections/{$collection}/points/search", [
                    'vector'       => $vector,
                    'limit'        => $limit,
                    'with_payload' => true,
                    'with_vector'  => true, // Needed for centroid calculation if we scale
                ]);

            if (! $response->ok()) {
                Log::warning("[QdrantService] Search failed on {$collection}", [
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $response->json('result') ?? [];
        } catch (\Exception $e) {
            Log::error("[QdrantService] Search exception on {$collection}", [
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function getCollectionPoints(string $collection, array $filter, int $limit): array
    {
        try {
            $response = Http::timeout(15)
                ->post("{$this->qdrantUrl}/collections/{$collection}/points/scroll", [
                    'filter'       => $filter,
                    'limit'        => $limit,
                    'with_payload' => true,
                    'with_vector'  => true,
                ]);

            return $response->json('result.points') ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function generateUuid(string $idHex): string
    {
        return implode('-', [
            substr($idHex, 0, 8),
            substr($idHex, 8, 4),
            substr($idHex, 12, 4),
            substr($idHex, 16, 4),
            substr($idHex, 20, 12),
        ]);
    }
}
