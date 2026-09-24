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
 * Index (or re-index) a product into Qdrant off the request thread.
 * Product saves can be frequent; embedding calls must not block admin edits.
 */
class SyncProductToQdrant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public array $payload) {}

    public function handle(QdrantService $qdrantService): void
    {
        try {
            $qdrantService->upsertProduct($this->payload);
        } catch (\Exception $e) {
            Log::warning('[SyncProductToQdrant] Failed', [
                'id' => $this->payload['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
