<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Anwar\GunmaAgent\Models\PikuProductBlurb;
use Anwar\GunmaAgent\Services\AgentSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pre-generate short, friendly product blurbs in 3 languages
 * (en = fallback, bn = Banglish roman, hi = Hindi).
 *
 * Deliberately excludes price/stock — those are injected live by the
 * serving endpoint so the doodle never quotes stale numbers.
 *
 * Usage:
 *   php artisan gunma:generate-product-blurbs --limit=200
 *   php artisan gunma:generate-product-blurbs --product=123 --force
 *   php artisan gunma:generate-product-blurbs --lang=bn
 */
class GenerateProductBlurbsCommand extends Command
{
    protected $signature = 'gunma:generate-product-blurbs
        {--lang=* : Languages to generate (default en,bn,hi)}
        {--limit=0 : Max products this run (0 = all)}
        {--product= : Only this product id}
        {--from= : Only products with id >= this}
        {--to= : Only products with id <= this}
        {--force : Regenerate even if unchanged}
        {--sleep=0 : Milliseconds to sleep between LLM calls}';

    protected $description = 'Pre-generate AI product blurbs for the Piku doodle';

    private const LANGS = ['en', 'bn', 'hi'];

    public function __construct(private readonly AgentSettingsService $settings)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $langs = array_values(array_intersect((array) $this->option('lang'), self::LANGS)) ?: self::LANGS;
        $limit = (int) $this->option('limit');
        $productId = $this->option('product') ? (int) $this->option('product') : null;
        $force = (bool) $this->option('force');
        $sleepMs = (int) $this->option('sleep');

        $productModel = config('gunma-agent.models.product', \App\Models\Product::class);
        if (! class_exists($productModel)) {
            $this->error('Product model not found.');
            return self::FAILURE;
        }

        $query = $productModel::query()->where('status', 'Active');
        if ($productId) {
            $query->where('id', $productId);
        }
        if ($this->option('from')) {
            $query->where('id', '>=', (int) $this->option('from'));
        }
        if ($this->option('to')) {
            $query->where('id', '<=', (int) $this->option('to'));
        }

        $total = (clone $query)->count();
        $this->info("Products: {$total} | langs: " . implode(',', $langs) . ($force ? ' | force' : ''));

        $done = 0;
        $skipped = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(50, function ($products) use (&$done, &$skipped, &$failed, $langs, $force, $sleepMs, $limit) {
            foreach ($products as $product) {
                if ($limit > 0 && $done >= $limit) {
                    return false;
                }

                $title = (string) $product->title;
                $desc = trim((string) ($product->short_description ?: $product->description));
                $category = '';
                try {
                    $category = (string) ($product->categories()->pluck('title')->first() ?? '');
                } catch (\Throwable) {
                }

                $hash = md5($title . '|' . mb_substr($desc, 0, 600) . '|' . $category);

                foreach ($langs as $lang) {
                    $existing = PikuProductBlurb::where('product_id', $product->id)->where('lang', $lang)->first();
                    if ($existing && ! $force && $existing->source_hash === $hash) {
                        $skipped++;
                        continue;
                    }

                    $text = $this->generate($title, $desc, $category, $lang);
                    if ($text === null) {
                        $failed++;
                        continue;
                    }

                    PikuProductBlurb::updateOrCreate(
                        ['product_id' => $product->id, 'lang' => $lang],
                        ['text' => $text, 'source_hash' => $hash, 'generated_at' => now()]
                    );
                    $done++;
                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
            }

            if ($limit > 0 && $done >= $limit) {
                return false;
            }
            return true;
        });

        $this->newLine();
        $this->info("Generated: {$done} | unchanged: {$skipped} | failed: {$failed}");

        return self::SUCCESS;
    }

    private function generate(string $title, string $desc, string $category, string $lang): ?string
    {
        $desc = mb_substr($desc, 0, 400);

        $style = match ($lang) {
            'bn' => 'Write in Banglish (romanized Bengali, shopkeeper tone, friendly).',
            'hi' => 'Write in Hindi (Devanagari script).',
            default => 'Write in English.',
        };

        $system = 'You write very short ecommerce product blurbs for a halal grocery store in Japan. '
            . 'Rules: ONE sentence, max 16 words. Focus on taste, cooking use, or pairing suggestion. '
            . 'NEVER mention price, stock, discounts, shipping, or emojis. No quotes around the text.';

        $user = "{$style}\n"
            . "Product: {$title}\n"
            . ($category !== '' ? "Category: {$category}\n" : '')
            . ($desc !== '' ? "Details: {$desc}\n" : '')
            . 'Return ONLY the sentence.';

        try {
            $baseUrl = rtrim((string) $this->settings->get('llm_base_url', config('gunma-agent.llm.base_url')), '/');
            $apiKey  = (string) $this->settings->get('llm_api_key', config('gunma-agent.llm.api_key'));
            $model   = (string) $this->settings->get('llm_model', config('gunma-agent.llm.model'));

            $req = Http::timeout(60)->acceptJson();
            if ($apiKey !== '') {
                $req = $req->withToken($apiKey);
            }

            $res = $req->post($baseUrl . '/chat/completions', [
                'model' => trim($model),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.6,
            ]);

            if (! $res->ok()) {
                Log::warning('[Blurb] LLM error', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
                return null;
            }

            $content = (string) ($res->json('choices.0.message.content') ?? '');
            $content = trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '');
            $content = trim($content, " \t\n\r\"'“”");

            if ($content === '' || mb_strlen($content) > 200) {
                return null;
            }

            return $content;
        } catch (\Throwable $e) {
            Log::warning('[Blurb] generate failed', ['title' => $title, 'lang' => $lang, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
