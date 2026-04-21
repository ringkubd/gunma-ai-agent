<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Illuminate\Console\Command;
use Anwar\GunmaAgent\Services\QdrantService;
use Illuminate\Support\Facades\Log;

class SyncDataToQdrantCommand extends Command
{
    protected $signature = 'gunma:sync-qdrant {--type=all : all, products, history}';
    protected $description = 'Manually sync existing products and order history to Qdrant';

    public function __construct(private QdrantService $qdrantService)
    {
        parent::__construct();
    }

    public function handle()
    {
        $type = $this->option('type');

        if ($type === 'all' || $type === 'products') {
            $this->syncProducts();
        }

        if ($type === 'all' || $type === 'history') {
            $this->syncHistory();
        }

        $this->info('Sync completed successfully!');
    }

    private function syncProducts(): void
    {
        $productModel = config('gunma-agent.models.product');
        if (!class_exists($productModel)) {
            $this->error("Product model not found: {$productModel}");
            return;
        }

        $this->info('Syncing products...');
        $productModel::where('status', 'Active')
            ->where('is_online_available', 1)
            ->with(['latestStock', 'images'])
            ->chunk(100, function ($products) {
                foreach ($products as $product) {
                    try {
                        $this->qdrantService->upsertProduct([
                            'id'          => $product->id,
                            'name'        => $product->title,
                            'description' => $product->description ?? $product->short_description,
                            'price'       => (float) ($product->latestStock?->online_price ?? 0),
                            'status'      => $product->status,
                            'is_online'   => (bool) $product->is_online_available,
                            'slug'        => $product->slug,
                            'image_url'   => $product->images->first()?->image_path,
                        ]);
                        $this->output->write('.');
                    } catch (\Exception $e) {
                        $this->error("\nFailed to sync product #{$product->id}: " . $e->getMessage());
                    }
                }
            });
        $this->info("\nProducts synced.");
    }

    private function syncHistory(): void
    {
        $orderModel = config('gunma-agent.models.order');
        if (!class_exists($orderModel)) {
            $this->error("Order model not found: {$orderModel}");
            return;
        }

        $this->info('Syncing order history...');
        $orderModel::whereIn('status', ['Completed', 'Sent', 'Delivered', 'Paid'])
            ->whereNotNull('customer_id')
            ->with('orderItems')
            ->chunk(50, function ($orders) {
                foreach ($orders as $order) {
                    foreach ($order->orderItems as $item) {
                        try {
                            $this->qdrantService->indexOrderHistory((int) $order->customer_id, [
                                'id'         => $item->id,
                                'product_id' => $item->product_id,
                                'name'       => $item->product_name ?? $item->product?->title,
                            ]);
                        } catch (\Exception $e) {
                            Log::warning("Manual sync failed for order item #{$item->id}");
                        }
                    }
                    $this->output->write('.');
                }
            });
        $this->info("\nOrder history synced.");
    }
}
