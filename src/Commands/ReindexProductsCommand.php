<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Anwar\GunmaAgent\Services\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Bulk reindex ALL active online products into the Qdrant products
 * collection using batched embeddings (fast). Safe to re-run.
 */
class ReindexProductsCommand extends Command
{
    protected $signature = 'gunma:reindex-products {--fresh : Also drop existing product points first} {--chunk=100 : Products per batch}';
    protected $description = 'Bulk reindex active products into Qdrant (batched embeddings)';

    public function __construct(private readonly QdrantService $qdrant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
        if (! class_exists($productModel)) {
            $this->error('Product model not found.');
            return self::FAILURE;
        }

        $chunk = max(20, (int) $this->option('chunk'));
        $total = $productModel::where('status', 'Active')->where('is_online_available', 'Yes')->count();
        $this->info("Reindexing {$total} active products (chunk={$chunk})…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $ok = 0;
        $failed = 0;

        $productModel::where('status', 'Active')
            ->where('is_online_available', 'Yes')
            ->with(['latestStock', 'images'])
            ->chunkById($chunk, function ($products) use (&$ok, &$failed, $bar) {
                $payloads = [];
                foreach ($products as $p) {
                    try {
                        $stock = $p->latestStock;
                        $payloads[] = [
                            'id'          => $p->id,
                            'name'        => $p->title,
                            'description' => (string) ($p->short_description ?: $p->description),
                            'price'       => (float) ($stock?->online_price ?? 0),
                            'status'      => $p->status,
                            'is_online'   => $p->is_online_available === 'Yes',
                            'slug'        => (string) $p->slug,
                            'image_url'   => $p->images->first()?->image_path ?? $p->images->first()?->image,
                            'stock'       => (int) ($stock?->available_quantity ?? 0),
                        ];
                    } catch (\Throwable $e) {
                        $failed++;
                    }
                }

                if (! empty($payloads)) {
                    try {
                        $this->qdrant->upsertProductsBulk($payloads);
                        $ok += count($payloads);
                    } catch (\Throwable $e) {
                        $failed += count($payloads);
                        Log::warning('[Reindex] bulk failed', ['error' => $e->getMessage()]);
                    }
                }
                $bar->advance($products->count());
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Indexed: {$ok} | failed: {$failed}");
        return self::SUCCESS;
    }
}
