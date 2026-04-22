<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

class MarkdownService
{
    public static function toHtml(string $text, string $websiteUrl): string
    {
        if (!$text) return '';

        // 1. Parse Product Blocks
        $text = preg_replace_callback('/:{2,3}product\[(.*?)\|(.*?)\|(.*?)\|(.*?)\|(.*?)\]:{2,3}/s', function ($m) use ($websiteUrl) {
            $id = trim($m[1]);
            $title = trim($m[2]);
            $price = trim($m[3]);
            $image = trim($m[4]);
            $slug = trim($m[5]);
            $cleanPrice = str_replace(['.000', '.00'], '', $price);

            return "
                <div style='border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; margin: 10px 0; display: inline-block; width: 200px; font-family: sans-serif;'>
                    <a href='{$websiteUrl}/{$slug}' target='_blank' style='text-decoration: none;'>
                        <img src='{$image}' style='width: 100%; height: 150px; object-fit: contain; border-radius: 4px;' />
                        <h4 style='font-size: 14px; margin: 8px 0; color: #0f172a;'>{$title}</h4>
                        <p style='color: #10b981; font-weight: bold; margin: 0;'>৳{$cleanPrice}</p>
                    </a>
                </div>";
        }, $text);

        // 2. Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

        // 3. Lists
        $text = preg_replace('/^[-*•]\s+(.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/((?:<li>.*<\/li>\n?)+)/', '<ul>$1</ul>', $text);

        // 4. Links
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" style="color: #10b981; text-decoration: none;">$1</a>', $text);

        // 5. Line breaks
        $text = nl2br($text);

        return $text;
    }
}
