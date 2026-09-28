<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Anwar\GunmaAgent\Services\QdrantService;
use Illuminate\Console\Command;

/**
 * Seed the support knowledge base (used by search_support_kb) from
 * resources/kb.json. Safe to re-run — stable IDs.
 */
class SeedKbCommand extends Command
{
    protected $signature = 'gunma:seed-kb
        {--replace : Drop & recreate the KB collection first}
        {--file= : Custom KB file (defaults to bundled resources/kb.json)}';

    protected $description = 'Seed the Piku support knowledge base into Qdrant';

    public function __construct(private readonly QdrantService $qdrant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $file = $this->option('file') ?: __DIR__ . '/../../resources/kb.json';
        if (! is_file($file)) {
            $this->error("KB file not found: {$file}");
            return self::FAILURE;
        }

        $entries = json_decode((string) file_get_contents($file), true);
        if (! is_array($entries) || empty($entries)) {
            $this->error('KB file empty/invalid.');
            return self::FAILURE;
        }

        if ($this->option('replace')) {
            $prefix = (string) config('gunma-agent.qdrant_collection_prefix', '');
            $collection = $prefix . config('gunma-agent.qdrant_collections.kb', 'gunmahal_kb');
            $url = (string) config('gunma-agent.qdrant_url');
            $dims = (int) config('gunma-agent.embedding.dims', 768);
            $this->warn("Rebuilding {$collection} ({$dims}d)…");
            \Illuminate\Support\Facades\Http::timeout(30)->delete("{$url}/collections/{$collection}");
            \Illuminate\Support\Facades\Http::timeout(30)->put("{$url}/collections/{$collection}", [
                'vectors' => ['size' => $dims, 'distance' => 'Cosine'],
            ]);
        }

        $this->info('Seeding ' . count($entries) . ' KB entries…');
        $ok = $this->qdrant->upsertKbBulk($entries);
        $this->newLine();
        $this->info("Seeded {$ok}/" . count($entries) . " KB entries.");

        return self::SUCCESS;
    }
}
