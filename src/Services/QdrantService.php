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

    private string $collectionPrefix;

    public function __construct(
        private readonly string           $qdrantUrl,
        private readonly EmbeddingService $embeddingService,
    ) {
        $this->collectionPrefix = config('gunma-agent.qdrant_collection_prefix', '');
        $raw = config('gunma-agent.qdrant_collections', []);
        $this->collections = [];
        foreach ($raw as $key => $name) {
            $this->collections[$key] = $this->collectionPrefix . $name;
        }
    }

    private function prefixed(string $collection): string
    {
        // Idempotent: avoid double-prefixing when callers already pass a
        // fully-qualified (prefixed) collection name.
        if ($this->collectionPrefix !== '' && !str_starts_with($collection, $this->collectionPrefix)) {
            return $this->collectionPrefix . $collection;
        }
        return $collection;
    }

    /**
     * Public, prefix-aware vector search for external integrations (e.g. Scout).
     */
    public function searchCollection(string $collection, array $vector, int $limit = 10): array
    {
        return $this->vectorSearch($this->prefixed($collection), $vector, $limit);
    }

    /**
     * Public, prefix-aware point upsert for external integrations (e.g. Scout).
     */
    public function upsertPoints(string $collection, array $points): void
    {
        $this->bulkUpsert($this->prefixed($collection), $points);
    }

    /**
     * Public, prefix-aware point delete for external integrations (e.g. Scout).
     */
    public function deletePoints(string $collection, array $ids): void
    {
        try {
            Http::timeout(15)->post(
                "{$this->qdrantUrl}/collections/" . $this->prefixed($collection) . '/points/delete',
                ['points' => $ids]
            );
        } catch (\Exception $e) {
            Log::warning('[QdrantService] Point delete failed', ['error' => $e->getMessage()]);
        }
    }

    /* ── Product Search (OpenAI embeddings, 1536d) ─────────────── */

    public function searchProducts(string $query, int $limit = 5): array
    {
        // Skip vector search (and its embedding timeout) when the provider is down.
        if (! $this->embeddingService->isCircuitOpen()) {
            $results = $this->doVectorSearch($this->collections['products'], $query, $limit, 'openai');
            if (!empty($results)) return $results;
        }

        // Fallback: DB search when Qdrant is empty or embeddings unavailable.
        return $this->fallbackDbSearch($query, $limit);
    }

    /**
     * Bulk product search — embed all queries at once, then fan-out searches.
     */
    public function searchProductsBulk(array $queries, int $limitPerQuery = 3): array
    {
        if (empty($queries)) return [];

        // Try Qdrant first (skip when the embedding circuit is open).
        if (! $this->embeddingService->isCircuitOpen()) {
            try {
                $vectors = $this->embeddingService->openaiEmbedBulk($queries);
                $results = [];
                foreach ($queries as $index => $query) {
                    $hits = $this->vectorSearch($this->collections['products'], $vectors[$index], $limitPerQuery);
                    $results[] = [
                        'query'   => $query,
                        'results' => $hits,
                    ];
                }
                // If any query returned results, return all
                if (!empty(array_filter($results, fn($r) => !empty($r['results'])))) {
                    return $results;
                }
            } catch (\Exception $e) {
                Log::warning('[QdrantService] Bulk search failed, falling back to DB', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: DB search for each query
        $results = [];
        foreach ($queries as $query) {
            $results[] = [
                'query'   => $query,
                'results' => $this->fallbackDbSearch($query, $limitPerQuery),
            ];
        }
        return $results;
    }

    /* ── DB Fallback Search ────────────────────────────────────── */

    private function fallbackDbSearch(string $query, int $limit = 5): array
    {
        try {
            $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
            if (!class_exists($productModel)) return [];

            // Exact title/slug first (users often paste the exact product name,
            // sometimes with sizes like "3.5kg (±100g)" that break plain LIKE).
            $clean = trim(str_replace(['+', '||'], ' ', preg_replace('/\([^)]*\)/', '', $query)));
            $hits = $productModel::where('status', 'Active')
                ->where('is_online_available', 'Yes')
                ->where(function ($q) use ($query) {
                    $q->where('title', $query)->orWhere('slug', \Illuminate\Support\Str::slug($query));
                })
                ->with(['latestStock', 'images'])
                ->limit($limit)
                ->get();
            if (count($hits) < 1) {
                // Token-based fuzzy match: every significant word must appear
                // somewhere in title/short description/categories.
                $tokens = array_values(array_filter(
                    preg_split('/[^a-z0-9]+/i', $clean) ?: [],
                    fn ($t) => mb_strlen($t) > 2 && ! in_array($t, ['kg', 'gm', 'pcs', 'fresh'], true)
                ));
                foreach ($tokens as $t) {
                    $t = mb_strtolower($t);
                    $hits = $hits->merge(
                        $productModel::where('status', 'Active')
                            ->where('is_online_available', 'Yes')
                            ->where(function ($q) use ($t) {
                                $like = "%{$t}%";
                                $q->where('title', 'LIKE', $like)
                                  ->orWhere('short_description', 'LIKE', $like)
                                  ->orWhereHas('categories', fn($cq) => $cq->where('title', 'LIKE', $like));
                            })
                            ->with(['latestStock', 'images'])
                            ->limit($limit)
                            ->get()
                    );
                }
                if (empty($tokens)) {
                    $t = mb_strtolower($clean);
                    if (mb_strlen($t) > 2) {
                        $hits = $productModel::where('status', 'Active')
                            ->where('is_online_available', 'Yes')
                            ->where(function ($q) use ($t) {
                                $like = "%{$t}%";
                                $q->where('title', 'LIKE', $like)
                                  ->orWhere('short_description', 'LIKE', $like);
                            })
                            ->with(['latestStock', 'images'])
                            ->limit($limit)
                            ->get();
                    }
                }
                $hits = $hits->unique('id')->take($limit);
            }

            return $hits->map(fn($p) => [
                'id'      => (string) $p->id,
                'score'   => 0.95,
                'payload' => [
                    'id'       => $p->id,
                    'title'    => $p->title,
                    'slug'     => $p->slug,
                    'price'    => (float) ($p->latestStock?->online_price ?? 0),
                    'image'    => $p->images->first()?->image_path ?? $p->images->first()?->image,
                    'stock'    => (int) ($p->latestStock?->available_quantity ?? 0),
                    'status'   => $p->status,
                ],
            ])->values()->toArray();
        } catch (\Exception $e) {
            Log::warning('[QdrantService] DB fallback search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function doVectorSearch(string $collection, string $query, int $limit, string $embedType): array
    {
        try {
            $vector = $embedType === 'openai'
                ? $this->embeddingService->openaiEmbed($query)
                : $this->embeddingService->ollamaEmbed($query);
            return $this->vectorSearch($collection, $vector, $limit);
        } catch (\Exception $e) {
            Log::warning('[QdrantService] Vector search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /* ── Recipe Search (Ollama embeddings, 768d) ───────────────── */

    public function searchRecipes(string $query, int $limit = 3): array
    {
        try {
            $vector = $this->embeddingService->ollamaEmbed($query);
            $results = $this->vectorSearch($this->collections['recipes'], $vector, $limit);
            if (!empty($results)) {
                return $results;
            }
        } catch (\Exception $e) {
            Log::warning('[QdrantService] Recipe search failed', ['error' => $e->getMessage()]);
        }

        // Fallback: keyword search over the bundled recipe JSON so recipe help
        // still works when Qdrant or the embedding provider is unavailable.
        return $this->fallbackRecipeSearch($query, $limit);
    }

    /**
     * Lightweight keyword search over the bundled resources/recipes.json.
     * Returns the same hit shape as vectorSearch ([payload, score, ...]).
     */
    private function fallbackRecipeSearch(string $query, int $limit = 3): array
    {
        try {
            $file = dirname(__DIR__, 2) . '/resources/recipes.json';
            if (! is_file($file)) {
                return [];
            }
            $recipes = json_decode((string) file_get_contents($file), true);
            if (! is_array($recipes) || empty($recipes)) {
                return [];
            }

            $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/i', mb_strtolower($query)) ?: [], fn ($t) => strlen($t) > 2));
            if (empty($tokens)) {
                return [];
            }

            $scored = [];
            foreach ($recipes as $recipe) {
                $haystack = mb_strtolower(
                    ($recipe['title'] ?? '') . ' '
                    . implode(' ', $recipe['ingredients'] ?? []) . ' '
                    . ($recipe['cuisine'] ?? '') . ' '
                    . ($recipe['category'] ?? '')
                );
                $score = 0;
                foreach ($tokens as $token) {
                    if (str_contains($haystack, $token)) {
                        $score += 1;
                    }
                    if (str_contains(mb_strtolower((string) ($recipe['title'] ?? '')), $token)) {
                        $score += 2;
                    }
                }
                if ($score > 0) {
                    $scored[] = ['payload' => $recipe, 'score' => $score / (count($tokens) * 3)];
                }
            }

            usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($scored, 0, $limit);
        } catch (\Throwable $e) {
            Log::warning('[QdrantService] Recipe JSON fallback failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /* ── Support KB Search (Ollama embeddings, 768d) ───────────── */

    public function searchSupportKB(string $query, int $limit = 3): array
    {
        try {
            $vector = $this->embeddingService->ollamaEmbed($query);
            return $this->vectorSearch($this->collections['kb'], $vector, $limit);
        } catch (\Exception $e) {
            Log::warning('[QdrantService] KB search failed', ['error' => $e->getMessage()]);
            return [];
        }
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
     * Bulk-upsert many recipes with ONE batched embedding call per chunk.
     * Deterministic IDs (md5 of title) keep re-seeds idempotent.
     *
     * @param array<int,array<string,mixed>> $recipes
     */
    public function upsertRecipesBulk(array $recipes, int $batch = 32): int
    {
        if (empty($recipes)) {
            return 0;
        }

        $texts = array_map(
            fn ($r) => trim(($r['title'] ?? '') . ' ' . ($r['cuisine'] ?? '') . ' ' . ($r['category'] ?? '') . ' ' . implode(', ', $r['ingredients'] ?? [])),
            $recipes
        );

        $embedded = 0;
        foreach (array_chunk($recipes, max(1, $batch), true) as $chunkIndex => $chunk) {
            $sliceTexts = array_slice($texts, $chunkIndex * $batch, count($chunk));
            try {
                $vectors = $this->embeddingService->embedBulk($sliceTexts);
            } catch (\Throwable $e) {
                Log::error('[QdrantService] Recipe bulk embedding failed', ['error' => $e->getMessage()]);
                continue;
            }

            $points = [];
            foreach (array_values($chunk) as $i => $recipe) {
                $vector = $vectors[$i] ?? null;
                if (empty($vector) || count($vector) < 64) {
                    continue;
                }
                $points[] = [
                    'id'      => $this->generateUuid(md5($recipe['title'] ?? microtime())),
                    'vector'  => $vector,
                    'payload' => $recipe,
                ];
            }
            if (! empty($points)) {
                $this->bulkUpsert($this->collections['recipes'], $points);
                $embedded += count($points);
            }
        }

        return $embedded;
    }

    /**
     * Index or Update a product for real-time stock/price accuracy.
     */
    public function upsertProduct(array $product): void
    {
        $this->upsertProductsBulk([$product]);
    }

    /**
     * Bulk upsert products using a single batched embedding call.
     * Much faster than per-product embedding for full reindexes.
     *
     * @param array<int,array<string,mixed>> $products
     */
    public function upsertProductsBulk(array $products): void
    {
        if (empty($products)) {
            return;
        }

        $texts = array_map(
            fn ($p) => trim(($p['name'] ?? '') . ' ' . ($p['description'] ?? '')),
            $products
        );

        $vectors = $this->embeddingService->openaiEmbedBulk($texts);

        $points = [];
        foreach ($products as $i => $product) {
            $vector = $vectors[$i] ?? null;
            if (empty($vector)) {
                continue;
            }
            $points[] = [
                'id'      => $this->generateUuid(md5("prod_" . $product['id'])),
                'vector'  => $vector,
                'payload' => [
                    'id'           => $product['id'],
                    'name'         => $product['name'] ?? '',
                    'description'  => $product['description'] ?? '',
                    'price'        => (float) ($product['price'] ?? 0),
                    'status'       => $product['status'] ?? '',
                    'is_online'    => (bool) ($product['is_online'] ?? false),
                    'slug'         => $product['slug'] ?? '',
                    'image_url'    => $product['image_url'] ?? null,
                    'stock'        => (int) ($product['stock'] ?? 0),
                    'last_updated' => now()->toIso8601String(),
                ],
            ];
        }

        $this->bulkUpsert($this->collections['products'], $points);
    }

    /**
     * Bulk index purchase-history items using a single batched embedding call.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public function indexOrderHistoryBulk(int $customerId, array $items): void
    {
        if (empty($items)) {
            return;
        }

        $texts = array_map(fn ($it) => 'Customer bought: ' . ($it['name'] ?? ''), $items);
        $vectors = $this->embeddingService->openaiEmbedBulk($texts);

        $points = [];
        foreach ($items as $i => $item) {
            $vector = $vectors[$i] ?? null;
            if (empty($vector)) {
                continue;
            }
            $points[] = [
                'id'      => $this->generateUuid(md5("order_" . ($item['id'] ?? microtime()))),
                'vector'  => $vector,
                'payload' => [
                    'customer_id' => $customerId,
                    'product_id'  => $item['product_id'] ?? null,
                    'name'        => $item['name'] ?? '',
                    'timestamp'   => now()->toIso8601String(),
                ],
            ];
        }

        $this->bulkUpsert($this->collections['history'], $points);
    }

    /**
     * Index a purchase event to build a semantic profile for the customer.
     */
    public function indexOrderHistory(int $customerId, array $item): void
    {
        $this->indexOrderHistoryBulk($customerId, [$item]);
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

        $endpoint = "{$this->qdrantUrl}/collections/{$collection}/points";

        $response = Http::timeout(30)
            ->put($endpoint, [
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

        try {
            $vector = $this->embeddingService->openaiEmbed($query);
            $results = $this->vectorSearch($this->collections['cache'], $vector, 1);
        } catch (\Exception $e) {
            // Embedding provider slow/unavailable — skip cache, never break chat.
            Log::warning('[QdrantService] Semantic cache lookup skipped', ['error' => $e->getMessage()]);
            return null;
        }

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
     * Search past conversation memories for similar Q&As to augment context.
     */
    public function searchMemories(string $query, int $limit = 3, ?int $customerId = null): array
    {
        // Only ever recall a *logged-in customer's own* past conversations.
        // Guest traffic is intentionally excluded so one visitor's Q&A can never
        // leak into another visitor's context.
        if (! $customerId) {
            return [];
        }

        try {
            $vector = $this->embeddingService->ollamaEmbed($query);
            $raw = $this->vectorSearch($this->collections['memories'], $vector, $limit * 3);
            $memories = [];
            foreach ($raw as $hit) {
                if (($hit['score'] ?? 0) < 0.85) {
                    continue;
                }
                $payload = $hit['payload'] ?? [];
                if ((int) ($payload['customer_id'] ?? 0) !== (int) $customerId) {
                    continue;
                }
                $memories[] = $payload;
                if (count($memories) >= $limit) {
                    break;
                }
            }
            return $memories;
        } catch (\Exception $e) {
            Log::warning('[QdrantService] Memory search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Index a conversation memory for future RAG use.
     */
    public function indexMemory(string $sessionId, string $query, string $answer, ?int $customerId = null): void
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
                        'session_id'  => $sessionId,
                        'customer_id' => $customerId,
                        'query'       => $query,
                        'answer'      => $answer,
                        'timestamp'   => now()->toIso8601String(),
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
