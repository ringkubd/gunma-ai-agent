<?php

namespace Anwar\GunmaAgent\Observers;

use Anwar\GunmaAgent\Services\QdrantService;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    public function __construct(private QdrantService $qdrantService) {}

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
            foreach ($order->orderItems as $item) {
                $this->qdrantService->indexOrderHistory((int) $order->customer_id, [
                    'id'         => $item->id,
                    'product_id' => $item->product_id,
                    'name'       => $item->product_name ?? $item->product?->title,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("[OrderObserver] Failed to index order history", ['id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
