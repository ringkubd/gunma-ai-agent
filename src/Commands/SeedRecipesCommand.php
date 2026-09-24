<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Anwar\GunmaAgent\Services\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Build (or rebuild) the Qdrant recipes collection from a curated dataset.
 *
 * Replaces the previous synthetic/broken recipe data with real recipes so the
 * agent's recipe search returns sensible results. Safe to re-run.
 *
 * Usage:
 *   php artisan gunma:seed-recipes              # add/update curated recipes
 *   php artisan gunma:seed-recipes --fresh      # drop & recreate recipes first
 *   php artisan gunma:seed-recipes --file=path  # custom dataset (JSON)
 */
class SeedRecipesCommand extends Command
{
    protected $signature = 'gunma:seed-recipes
                            {--fresh : Drop and recreate the recipes collection first}
                            {--file= : Path to a JSON file of recipes (defaults to bundled dataset)}';

    protected $description = 'Seed curated recipes into the Qdrant recipes collection';

    public function __construct(private QdrantService $qdrantService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $file = $this->option('file') ?: __DIR__ . '/../../resources/recipes.json';

        if (! is_file($file)) {
            $this->error("Recipe file not found: {$file}");
            return self::FAILURE;
        }

        $recipes = json_decode((string) file_get_contents($file), true);
        if (! is_array($recipes) || empty($recipes)) {
            $this->error('Recipe file is empty or invalid JSON.');
            return self::FAILURE;
        }

        $prefix = (string) config('gunma-agent.qdrant_collection_prefix', '');
        $collection = $prefix . config('gunma-agent.qdrant_collections.recipes', 'recipes');
        $url = (string) config('gunma-agent.qdrant_url');
        $dims = (int) config('gunma-agent.embedding.dims', 768);

        if ($this->option('fresh')) {
            $this->warn("Dropping collection {$collection} ...");
            Http::timeout(30)->delete("{$url}/collections/{$collection}");
            Http::timeout(30)->put("{$url}/collections/{$collection}", [
                'vectors' => ['size' => $dims, 'distance' => 'Cosine'],
            ]);
            $this->info("Recreated {$collection} ({$dims}d).");
        }

        $bar = $this->output->createProgressBar(count($recipes));
        $bar->start();
        $ok = 0;
        foreach ($recipes as $recipe) {
            try {
                $this->qdrantService->upsertRecipe($recipe);
                $ok++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Failed: " . ($recipe['title'] ?? '?') . ' — ' . $e->getMessage());
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->info("Seeded {$ok}/" . count($recipes) . " recipes into {$collection}.");
        return self::SUCCESS;
    }
}
