<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetupCollectionsCommand extends Command
{
    protected $signature = 'gunma:setup-qdrant';
    protected $description = 'Create required Qdrant collections';

    public function handle()
    {
        $url = config('gunma-agent.qdrant_url');
        $collections = config('gunma-agent.qdrant_collections');

        foreach ($collections as $key => $name) {
            $this->info("Creating collection: {$name}...");
            
            // Determine vector size
            // OpenAI (products, cache, history) = 1536
            // Ollama (recipes, kb, memories) = 768
            $size = in_array($key, ['products', 'cache', 'history']) ? 1536 : 768;

            $response = Http::put("{$url}/collections/{$name}", [
                'vectors' => [
                    'size' => $size,
                    'distance' => 'Cosine',
                ]
            ]);

            if ($response->ok()) {
                $this->info("Successfully created [{$name}].");
            } else {
                $this->warn("Collection [{$name}] might already exist or failed: " . $response->body());
            }
        }

        return 0;
    }
}
