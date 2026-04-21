<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Scout;

use Anwar\GunmaAgent\Services\QdrantService;
use Anwar\GunmaAgent\Services\EmbeddingService;
use Laravel\Scout\Engines\Engine;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;

class QdrantEngine extends Engine
{
    public function __construct(
        private readonly QdrantService $qdrant,
        private readonly EmbeddingService $embeddings,
    ) {}

    /**
     * Update the given models in the index.
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $collection = $models->first()->searchableAs();
        $points = [];

        foreach ($models as $model) {
            $searchableData = $model->toSearchableArray();

            if (empty($searchableData)) {
                continue;
            }

            // Convert array to string for embedding
            $text = collect($searchableData)->values()->implode(' ');
            
            // Determine which embedding model to use based on collection name
            $vector = str_contains($collection, 'product') 
                ? $this->embeddings->openaiEmbed($text)
                : $this->embeddings->ollamaEmbed($text);

            $idHex = md5((string) $model->getScoutKey());

            $points[] = [
                'id'      => $this->formatUuid($idHex),
                'vector'  => $vector,
                'payload' => array_merge($searchableData, [
                    'id' => $model->getScoutKey(),
                ]),
            ];
        }

        $this->qdrant->bulkUpsert($collection, $points);
    }

    /**
     * Remove the given models from the index.
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $collection = $models->first()->searchableAs();
        $ids = $models->map(function ($model) {
            return $this->formatUuid(md5((string) $model->getScoutKey()));
        })->all();

        // Qdrant delete points
        \Illuminate\Support\Facades\Http::post(
            config('gunma-agent.qdrant_url') . "/collections/{$collection}/points/delete",
            ['points' => $ids]
        );
    }

    /**
     * Perform the given search on the engine.
     */
    public function search(Builder $builder)
    {
        $collection = $builder->index ?: $builder->model->searchableAs();
        
        $vector = str_contains($collection, 'product')
            ? $this->embeddings->openaiEmbed($builder->query)
            : $this->embeddings->ollamaEmbed($builder->query);

        $limit = $builder->limit ?? 10;

        return $this->qdrant->vectorSearch($collection, $vector, $limit);
    }

    /**
     * Perform the given search on the engine.
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        // Vector search pagination is tricky with Qdrant (offset)
        // For now, we'll just return results with limit
        return $this->search($builder);
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results)->pluck('payload.id')->values()->all();

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds);
            })->sortBy(function ($model) use ($objectIds) {
                return array_search($model->getScoutKey(), $objectIds);
            });
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function mapIds($results)
    {
        return collect($results)->pluck('payload.id')->values();
    }

    /**
     * Get the total count from the given results.
     */
    public function getTotalCount($results)
    {
        return count($results);
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush($model)
    {
        // No easy way to flush in Qdrant without recreating collection
    }

    /**
     * Create a search index.
     */
    public function createIndex($name, array $options = [])
    {
        // Usually handled manually or via migration/command
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex($name)
    {
        // Usually handled manually
    }

    private function formatUuid(string $idHex): string
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
