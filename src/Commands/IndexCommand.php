<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class IndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gunma:index {model : The class name of the model to index} {--chunk=100 : The number of models to retrieve per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index a model into Qdrant using Laravel Scout';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $modelClass = $this->argument('model');

        if ($modelClass === 'Product' || $modelClass === 'App\Models\Product') {
            return $this->indexProducts();
        }

        if (!class_exists($modelClass)) {
            $this->error("Model [{$modelClass}] not found.");
            return 1;
        }

        // Fallback to standard Scout indexing if available
        if (method_exists($modelClass, 'searchable')) {
            $this->info("Indexing models of type [{$modelClass}] into Qdrant using Scout...");
            $modelClass::all()->searchable();
            return 0;
        }

        $this->error("Model [{$modelClass}] is not supported for automatic indexing and does not use Scout.");
        return 1;
    }

    private function indexProducts()
    {
        $this->info("Performing deep index for Products (including Stocks and Categories)...");

        $productClass = 'App\Models\Product';
        if (!class_exists($productClass)) {
            $this->error("App\Models\Product not found. Cannot perform deep index.");
            return 1;
        }

        $qdrant = app(\Anwar\GunmaAgent\Services\QdrantService::class);
        $embeddings = app(\Anwar\GunmaAgent\Services\EmbeddingService::class);
        $collection = config('gunma-agent.qdrant_collections.products');

        $productClass::with(['categories', 'latestStock', 'discount', 'images'])
            ->chunk(100, function ($products) use ($qdrant, $embeddings, $collection) {
                $points = [];

                foreach ($products as $product) {
                    $stock = $product->latestStock;
                    $image = $product->images->first();
                    
                    // Construct a rich text representation for embedding
                    $searchableText = implode(' ', [
                        $product->title,
                        $product->short_description ?? '',
                        $product->description ?? '',
                        $product->categories->pluck('title')->implode(' '),
                    ]);

                    $vector = $embeddings->openaiEmbed($searchableText);
                    $idHex = md5((string) $product->id);

                    $payload = [
                        'id' => $product->id,
                        'title' => $product->title,
                        'slug' => $product->slug,
                        'description' => $product->short_description,
                        'image' => $image ? $image->image : null,
                        'price' => $stock ? $stock->online_price : 0,
                        'stock' => $stock ? $stock->available_quantity : 0,
                        'categories' => $product->categories->pluck('title')->toArray(),
                        'category_ids' => $product->categories->pluck('id')->toArray(),
                        'status' => $product->status,
                        'is_online_available' => $product->is_online_available,
                    ];

                    $points[] = [
                        'id' => $this->formatUuid($idHex),
                        'vector' => $vector,
                        'payload' => $payload,
                    ];
                }

                $qdrant->bulkUpsert($collection, $points);
                $this->line("Indexed " . count($points) . " products...");
            });

        $this->info("Successfully deep-indexed products into Qdrant.");
        return 0;
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
