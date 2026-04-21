<?php

namespace Anwar\GunmaAgent\Observers;

use Anwar\GunmaAgent\Services\QdrantService;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    public function __construct(private QdrantService $qdrantService) {}

    public function saved($product): void
    {
        try {
            $this->qdrantService->upsertProduct([
                'id'          => $product->id,
                'name'        => $product->title,
                'description' => $product->description ?? $product->short_description,
                'price'       => (float) $product->price,
                'status'      => $product->status,
                'is_online'   => (bool) $product->is_online_available,
                'slug'        => $product->slug,
                'image_url'   => $product->images->first()?->image_path ?? null,
            ]);
        } catch (\Exception $e) {
            Log::warning("[ProductObserver] Failed to sync to Qdrant", ['id' => $product->id, 'error' => $e->getMessage()]);
        }
    }

    public function deleted($product): void
    {
        // Optional: Remove from Qdrant if needed, or just let status=Inactive handle it.
    }
}
