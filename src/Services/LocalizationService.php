<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

/**
 * Resolves the language/region the agent should reply in.
 *
 * Priority: explicit request language → customer profile (native_language)
 * → customer country → Accept-Language header → configurable default.
 * The agent is also instructed to mirror whatever language the customer
 * actually writes in, so this mainly handles proactive/openers.
 */
class LocalizationService
{
    /**
     * @return array{code:string,name:string,source:string,script:?string,rtl:bool}
     */
    public function resolve(?object $customer = null, ?string $acceptLanguage = null): array
    {
        $map = (array) config('gunma-agent.localization.language_names', []);
        $default = (string) config('gunma-agent.localization.default_language', 'bn');

        $candidates = [];

        if ($customer) {
            if (!empty($customer->native_language)) {
                $candidates[] = ['code' => (string) $customer->native_language, 'source' => 'profile'];
            }
            if (!empty($customer->country)) {
                $candidates[] = ['code' => (string) $customer->country, 'source' => 'country'];
            }
        }

        if ($acceptLanguage) {
            foreach ($this->acceptLanguageCandidates($acceptLanguage) as $tag) {
                $candidates[] = ['code' => $tag, 'source' => 'header'];
            }
        }

        foreach ($candidates as $c) {
            $normalized = $this->normalize($c['code'], $map);
            if ($normalized) {
                return $this->decorate($normalized['code'], $normalized['name'], $c['source']);
            }
        }

        $fallback = $this->normalize($default, $map) ?? ['code' => $default, 'name' => $default];
        return $this->decorate($fallback['code'], $fallback['name'], 'default');
    }

    /**
     * Attach script and RTL info to a resolved language.
     *
     * @return array{code:string,name:string,source:string,script:?string,rtl:bool}
     */
    private function decorate(string $code, string $name, string $source): array
    {
        $scripts = (array) config('gunma-agent.localization.scripts', []);
        $rtl = (array) config('gunma-agent.localization.rtl', []);
        $primary = strtolower(explode('-', $code)[0]);

        return [
            'code'   => $code,
            'name'   => $name,
            'source' => $source,
            'script' => $scripts[$primary] ?? null,
            'rtl'    => in_array($primary, array_map('strtolower', $rtl), true),
        ];
    }

    /**
     * All language tags from an Accept-Language header, quality-ordered.
     *
     * @return string[]
     */
    private function acceptLanguageCandidates(string $header): array
    {
        $tags = [];
        foreach (explode(',', $header) as $part) {
            $tag = trim(explode(';', $part)[0]);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /**
     * Map an arbitrary code (native_language/country/header tag) to a language.
     *
     * @return array{code:string,name:string}|null
     */
    private function normalize(string $code, array $map): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        // Exact match first.
        if (isset($map[$code])) {
            return ['code' => $code, 'name' => $map[$code]];
        }

        // Case-insensitive / primary-subtag match (bn-BD → bn).
        $lower = strtolower($code);
        foreach ($map as $key => $name) {
            if (strtolower($key) === $lower) {
                return ['code' => $key, 'name' => $name];
            }
        }

        $primary = strtolower(explode('-', $lower)[0]);
        foreach ($map as $key => $name) {
            if (strtolower(explode('-', $key)[0]) === $primary) {
                return ['code' => $primary, 'name' => $name];
            }
        }

        // Country names / ISO codes that commonly map to a language.
        $countryToLanguage = [
            'japan' => 'ja', 'jp' => 'ja',
            'bangladesh' => 'bn', 'bd' => 'bn',
            'india' => 'hi', 'in' => 'hi',
            'pakistan' => 'ur', 'pk' => 'ur',
            'nepal' => 'ne', 'np' => 'ne',
            'sri lanka' => 'si', 'lk' => 'si',
            'maldives' => 'dv', 'mv' => 'dv',
            'afghanistan' => 'ps', 'af' => 'ps',
            'bhutan' => 'dz', 'bt' => 'dz',
            'china' => 'zh', 'cn' => 'zh',
            'korea' => 'ko', 'kr' => 'ko',
            'vietnam' => 'vi', 'vn' => 'vi',
            'myanmar' => 'my', 'mm' => 'my',
            'thailand' => 'th', 'th' => 'th',
            'indonesia' => 'id', 'id' => 'id',
            'philippines' => 'tl', 'ph' => 'tl',
            'saudi arabia' => 'ar', 'sa' => 'ar',
            'uae' => 'ar', 'ae' => 'ar',
            'turkey' => 'tr', 'tr' => 'tr',
            'iran' => 'fa', 'ir' => 'fa',
        ];
        if (isset($countryToLanguage[$lower])) {
            $lc = $countryToLanguage[$lower];
            return ['code' => $lc, 'name' => $map[$lc] ?? $lc];
        }

        return null;
    }
}
