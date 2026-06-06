<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

/**
 * Generates proactive suggestions based on time, season, reorder windows, and customer context.
 */
class ProactiveTriggerService
{
    private array $seasonalProducts = [
        'ramadan' => [
            'dates', 'chola boot', 'semai', 'muri', 'shemai', 'ghee', 'chira',
            'beef', 'haleem mix', 'saffron', 'rose water', 'jorda',
        ],
        'eid' => [
            'beef premium cut', 'khasir meat', 'chicken whole', 'ghee', 'saffron',
            'nuts', 'pistachio', 'almonds', 'semai', 'sugar', 'rosh malai',
        ],
        'winter' => [
            'ghee', 'honey', 'date molasses', 'patali gur', 'khoya', 'cream',
            'hot chocolate', 'tea premium', 'coffee', 'soup mix',
        ],
        'summer' => [
            'ice cream', 'mango', 'lashchi', 'borhani mix', 'lassi', 'drinking water',
            'lemon', 'cucumber', 'yogurt', 'green mango',
        ],
        'rainy' => [
            'khichuri mix', 'tehari masala', 'tea', 'pakora mix', 'piyaju mix',
            'mustard oil', 'jhalmuri mix', 'chanachur',
        ],
    ];

    private array $timeBasedSuggestions = [
        'morning'    => ['bread', 'paratha', 'egg', 'butter', 'jam', 'tea bags', 'milk', 'honey'],
        'afternoon'  => ['rice', 'oil', 'spices', 'chicken', 'vegetables', 'lentils', 'salt'],
        'evening'    => ['snacks', 'chanachur', 'biscuit', 'tea', 'coffee', 'cake', 'noodles'],
    ];

    private array $dayBasedSuggestions = [
        'Thursday'  => ['fish', 'shrimp', 'fish fry mix', 'mustard oil'],
        'Friday'    => ['beef', 'chicken', 'ghee', 'biriyani spice', 'cool drinks'],
        'Saturday'  => ['rice', 'oil', 'lentils', 'onion', 'garlic', 'ginger', 'spices'],
        'Sunday'    => ['vegetables', 'salad items', 'fruits', 'juice'],
    ];

    /* ── Main Entry: Get triggers for this moment ───────────────── */

    public function getTriggers(?array $customerInsight = null): array
    {
        $triggers = [];

        // Time-based
        $hour = (int) date('H');
        $timeKey = $hour < 11 ? 'morning' : ($hour < 16 ? 'afternoon' : 'evening');
        $triggers['time_period'] = $timeKey;
        $triggers['time_suggestions'] = $this->timeBasedSuggestions[$timeKey];

        // Day-based
        $dayName = date('l');
        if (isset($this->dayBasedSuggestions[$dayName])) {
            $triggers['day'] = $dayName;
            $triggers['day_suggestions'] = $this->dayBasedSuggestions[$dayName];
        }

        // Season
        $season = $this->getSeason();
        $triggers['season'] = $season;
        $triggers['seasonal_suggestions'] = $this->seasonalProducts[$season] ?? [];

        // Reorder window (if customer context available)
        if ($customerInsight && !empty($customerInsight['suggested_reorders'])) {
            $triggers['reorder_alerts'] = $customerInsight['suggested_reorders'];
            $triggers['days_since_last_order'] = $customerInsight['days_since_last'] ?? 0;
        }

        // Special dates
        $triggers['ramadan_coming'] = $this->isRamadanNear();
        $triggers['eid_coming'] = $this->isEidNear();

        return $triggers;
    }

    /* ── Generate proactive message ─────────────────────────────── */

    public function buildProactiveMessage(?array $customerInsight = null): ?string
    {
        $triggers = $this->getTriggers($customerInsight);
        $parts = [];

        // Reorder alerts (most important)
        if (!empty($triggers['reorder_alerts'])) {
            $items = array_slice($triggers['reorder_alerts'], 0, 3);
            $names = array_map(fn($i) => $i['title'], $items);
            $parts[] = "By the way — it's been a while since you bought " . implode(', ', $names) . ". Want me to add them to your cart?";
            return implode(' ', $parts);
        }

        // Day-specific
        if ($triggers['day'] === 'Friday') {
            $parts[] = "Friday special! We have fresh beef and biriyani ingredients. Cooking something special today?";
        } elseif ($triggers['day'] === 'Thursday') {
            $parts[] = "Fresh fish arrived today! Perfect for a Thursday fish curry. Want to see what's available?";
        } elseif ($triggers['day'] === 'Saturday') {
            $parts[] = "Weekend stocking up? I can help with your weekly essentials — rice, oil, spices, and more.";
        }

        // Time-based
        if ($triggers['time_period'] === 'morning') {
            $parts[] = "Good morning! Need anything for breakfast or your morning chaa?";
        } elseif ($triggers['time_period'] === 'evening') {
            $parts[] = "Evening plans? I can suggest some great snack ideas for chaa time.";
        }

        // Seasonal
        if (!empty($triggers['seasonal_suggestions'])) {
            $sample = array_slice($triggers['seasonal_suggestions'], 0, 3);
            $parts[] = "Seasonal tip: " . implode(', ', $sample) . " are in demand right now.";
        }

        // Upcoming events
        if ($triggers['ramadan_coming']) {
            $parts[] = "Ramadan is approaching! Stock up early on dates, semai, chola, and haleem ingredients.";
        }
        if ($triggers['eid_coming']) {
            $parts[] = "Eid preparations? We have premium beef, khasir meat, and everything for a festive meal.";
        }

        return !empty($parts) ? implode(' ', $parts) : null;
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    private function getSeason(): string
    {
        $month = (int) date('n');
        return match (true) {
            $month >= 3 && $month <= 4  => 'ramadan',  // Approximate
            $month >= 5 && $month <= 6  => 'eid',
            $month >= 7 && $month <= 9  => 'rainy',
            $month >= 10 && $month <= 11 => 'winter',
            default                       => 'summer',
        };
    }

    private function isRamadanNear(): bool
    {
        $month = (int) date('n');
        return $month === 2 || $month === 3;
    }

    private function isEidNear(): bool
    {
        $month = (int) date('n');
        return $month === 4 || $month === 5 || $month === 6;
    }
}
