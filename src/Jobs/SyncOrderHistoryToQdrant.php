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
 * Index a completed order's items into the customer's purchase-history
 * collection off the request thread.
 */
class SyncOrderHistoryToQdrant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    /** @param array<int,array{id:mixed,product_id:mixed,name:?string}> $items */
    public function __construct(public int $customerId, public array $items) {}

    public function handle(QdrantService $qdrantService): void
    {
        foreach ($this->items as $item) {
            try {
                $qdrantService->indexOrderHistory($this->customerId, $item);
            } catch (\Exception $e) {
                Log::warning('[SyncOrderHistoryToQdrant] Failed', [
                    'customer_id' => $this->customerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
