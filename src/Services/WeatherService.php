<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight weather lookup via Open-Meteo (no API key) so the agent can make
 * weather-aware food suggestions. Results are cached per location+day.
 */
class WeatherService
{
    /**
     * Get current weather for a location key (postcode/prefecture/city).
     *
     * @return array{summary:string,temp:?float,code:?int,condition:string}|null
     */
    public function forLocation(?string $locationKey): ?array
    {
        if (! config('gunma-agent.weather.enabled', true)) {
            return null;
        }

        $key = trim((string) $locationKey);
        if ($key === '') {
            return null;
        }

        $cacheKey = 'gunma_weather_' . md5($key);

        try {
            return Cache::remember($cacheKey, (int) config('gunma-agent.weather.cache_ttl', 1800), function () use ($key) {
                $coords = $this->geocode($key);
                if (! $coords) {
                    return null;
                }

                $response = Http::timeout((int) config('gunma-agent.weather.timeout', 4))
                    ->get('https://api.open-meteo.com/v1/forecast', [
                        'latitude'      => $coords['lat'],
                        'longitude'     => $coords['lon'],
                        'current'       => 'temperature_2m,weather_code,is_day',
                        'timezone'      => 'Asia/Tokyo',
                    ]);

                if (! $response->ok()) {
                    return null;
                }

                $current = $response->json('current') ?? [];
                $code = (int) ($current['weather_code'] ?? -1);
                $temp = isset($current['temperature_2m']) ? (float) $current['temperature_2m'] : null;
                $condition = $this->describe($code);

                return [
                    'summary'   => trim(($temp !== null ? round($temp) . '°C' : '') . ' ' . $condition),
                    'temp'      => $temp,
                    'code'      => $code,
                    'condition' => $condition,
                ];
            });
        } catch (\Throwable $e) {
            Log::debug('[WeatherService] lookup failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Geocode a Japanese postcode or place name using Open-Meteo geocoding.
     */
    private function geocode(string $key): ?array
    {
        $cacheKey = 'gunma_geo_' . md5($key);

        return Cache::remember($cacheKey, 86400, function () use ($key) {
            $response = Http::timeout((int) config('gunma-agent.weather.timeout', 4))
                ->get('https://geocoding-api.open-meteo.com/v1/search', [
                    'name'     => $key,
                    'count'    => 1,
                    'language' => 'ja',
                    'format'   => 'json',
                ]);

            if ($response->ok()) {
                $hit = $response->json('results.0');
                if ($hit && isset($hit['latitude'], $hit['longitude'])) {
                    return ['lat' => (float) $hit['latitude'], 'lon' => (float) $hit['longitude']];
                }
            }

            // Fallback to configured default coordinates (Gunma/Nara area).
            $defaultLat = config('gunma-agent.weather.default_lat');
            $defaultLon = config('gunma-agent.weather.default_lon');
            if ($defaultLat !== null && $defaultLon !== null) {
                return ['lat' => (float) $defaultLat, 'lon' => (float) $defaultLon];
            }

            return null;
        });
    }

    private function describe(int $code): string
    {
        return match (true) {
            $code === 0                     => 'clear sky',
            in_array($code, [1, 2], true)   => 'partly cloudy',
            $code === 3                     => 'overcast',
            in_array($code, [45, 48], true) => 'foggy',
            $code >= 51 && $code <= 57      => 'drizzle',
            $code >= 61 && $code <= 67      => 'rainy',
            $code >= 71 && $code <= 77      => 'snowy',
            $code >= 80 && $code <= 82      => 'rain showers',
            $code >= 85 && $code <= 86      => 'snow showers',
            $code >= 95                     => 'thunderstorm',
            default                         => 'unknown',
        };
    }
}
