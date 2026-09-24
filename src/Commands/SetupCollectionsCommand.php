<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetupCollectionsCommand extends Command
{
    protected $signature = 'gunma:setup-qdrant {--recreate : Drop and recreate collections (destroys indexed vectors)}';
    protected $description = 'Create required Qdrant collections';

    public function handle()
    {
        $url = config('gunma-agent.qdrant_url');
        $prefix = config('gunma-agent.qdrant_collection_prefix', '');
        $collections = config('gunma-agent.qdrant_collections');

        // All collections use the active embedding dimension. Legacy installs
        // that mixed OpenAI (1536) and Ollama (768) must re-run with the new
        // uniform dimension after a full reindex.
        $size = (int) config('gunma-agent.embedding.dims', 768);
        $force = $this->option('recreate');

        foreach ($collections as $key => $name) {
            $prefixedName = $prefix . $name;

            if ($force) {
                $this->warn("Dropping collection: {$prefixedName} ...");
                Http::delete("{$url}/collections/{$prefixedName}");
            }

            $this->info("Creating collection: {$prefixedName} ({$size}d)...");

            $response = Http::put("{$url}/collections/{$prefixedName}", [
                'vectors' => [
                    'size' => $size,
                    'distance' => 'Cosine',
                ]
            ]);

            if ($response->ok()) {
                $this->info("Successfully created [{$prefixedName}].");
            } else {
                $this->warn("Collection [{$name}] might already exist or failed: " . $response->body());
            }
        }

        return 0;
    }
}
