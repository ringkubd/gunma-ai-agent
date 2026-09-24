<?php

namespace Anwar\GunmaAgent\Observers;

use Anwar\GunmaAgent\Jobs\SyncOrderHistoryToQdrant;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    public function saved($order): void
    {
        // Only index history if order is completed/paid/sent to ensure high-quality data
        if (!in_array($order->status, ['Completed', 'Sent', 'Delivered', 'Paid'])) {
            return;
        }

        if (!$order->customer_id) {
            return;
        }

        try {
            $order->load('orderItems');

            $items = $order->orderItems->map(fn($item) => [
                'id'         => $item->id,
                'product_id' => $item->product_id,
                'name'       => $item->product_title ?? $item->product?->title,
            ])->all();

            if (empty($items)) {
                return;
            }

            if (config('gunma-agent.queue_embeddings', true)) {
                SyncOrderHistoryToQdrant::dispatch((int) $order->customer_id, $items);
            } else {
                $qdrant = app(\Anwar\GunmaAgent\Services\QdrantService::class);
                foreach ($items as $item) {
                    $qdrant->indexOrderHistory((int) $order->customer_id, $item);
                }
            }
        } catch (\Exception $e) {
            Log::warning("[OrderObserver] Failed to index order history", ['id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
