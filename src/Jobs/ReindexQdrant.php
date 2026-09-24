<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Jobs;

use Anwar\GunmaAgent\Services\QdrantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-embed and index the whole catalog / order history into Qdrant.
 * Run after switching embedding providers (dimensions must already match).
 */
class ReindexQdrant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public string $type = 'all') {}

    public function handle(QdrantService $qdrantService): void
    {
        if (in_array($this->type, ['all', 'products'], true)) {
            $this->reindexProducts($qdrantService);
        }
        if (in_array($this->type, ['all', 'history'], true)) {
            $this->reindexHistory($qdrantService);
        }
    }

    private function reindexProducts(QdrantService $qdrantService): void
    {
        $productModel = config('gunma-agent.models.product');
        if (!class_exists($productModel)) {
            return;
        }

        $productModel::where('status', 'Active')
            ->where('is_online_available', 'Yes')
            ->with(['latestStock', 'images'])
            ->chunk(100, function ($products) use ($qdrantService) {
                $batch = [];
                foreach ($products as $product) {
                    $stock = $product->latestStock;
                    $batch[] = [
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
                }
                try {
                    $qdrantService->upsertProductsBulk($batch);
                } catch (\Exception $e) {
                    Log::warning('[ReindexQdrant] product batch failed', ['error' => $e->getMessage()]);
                }
            });
    }

    private function reindexHistory(QdrantService $qdrantService): void
    {
        $orderModel = config('gunma-agent.models.order');
        if (!class_exists($orderModel)) {
            return;
        }

        $orderModel::whereIn('status', ['Completed', 'Sent', 'Delivered', 'Paid'])
            ->whereNotNull('customer_id')
            ->with('orderItems')
            ->chunk(100, function ($orders) use ($qdrantService) {
                // Group items by customer for batched embedding per customer.
                $byCustomer = [];
                foreach ($orders as $order) {
                    foreach ($order->orderItems as $item) {
                        $byCustomer[(int) $order->customer_id][] = [
                            'id'         => $item->id,
                            'product_id' => $item->product_id,
                            'name'       => $item->product_title ?? $item->product?->title,
                        ];
                    }
                }
                foreach ($byCustomer as $customerId => $items) {
                    try {
                        $qdrantService->indexOrderHistoryBulk((int) $customerId, $items);
                    } catch (\Exception $e) {
                        Log::warning('[ReindexQdrant] history batch failed', ['customer' => $customerId, 'error' => $e->getMessage()]);
                    }
                }
            });
    }
}
