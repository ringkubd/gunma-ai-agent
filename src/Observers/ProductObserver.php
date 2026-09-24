<?php

namespace Anwar\GunmaAgent\Observers;

use Anwar\GunmaAgent\Jobs\SyncProductToQdrant;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    public function saved($product): void
    {
        try {
            $stock = $product->latestStock;
            $payload = [
                'id'          => $product->id,
                'name'        => $product->title,
                'description' => $product->description ?? $product->short_description,
                'price'       => (float) ($stock?->online_price ?? 0),
                'status'      => $product->status,
                'is_online'   => (bool) $product->is_online_available,
                'slug'        => $product->slug,
                'image_url'   => $product->images->first()?->image_path ?? null,
                'stock'       => (int) ($stock?->available_quantity ?? 0),
            ];

            // Offload embedding + Qdrant upsert to the queue so admin product
            // edits are never blocked by the embedding provider.
            if (config('gunma-agent.queue_embeddings', true)) {
                SyncProductToQdrant::dispatch($payload);
            } else {
                app(\Anwar\GunmaAgent\Services\QdrantService::class)->upsertProduct($payload);
            }
        } catch (\Exception $e) {
            Log::warning("[ProductObserver] Failed to sync to Qdrant", ['id' => $product->id, 'error' => $e->getMessage()]);
        }
    }

    public function deleted($product): void
    {
        // Optional: Remove from Qdrant if needed, or just let status=Inactive handle it.
    }
}
