<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Resolves the guest cart identity exactly like the host storefront
 * (encrypted `guest_id` cookie → raw cookie_id). Also bootstraps a brand-new
 * guest cart identity so first-time chatters can add to cart without login.
 */
class GuestCartService
{
    private ?int $customerId = null;
    private ?string $cookieId = null;
    private ?string $encryptedCookie = null;
    private bool $isNew = false;
    /**
     * True when the route runs Laravel's EncryptCookies middleware (Sanctum
     * stateful requests do). In that case an outgoing cookie is encrypted once
     * more on the way out, so we must hand it the PLAIN id — otherwise we
     * double-encrypt and the storefront (which decrypts only once) reads
     * garbage → empty cart.
     */
    private bool $statefulCookieEncryption = false;

    /**
     * Resolve once per request (chat controller calls this before the agent runs).
     */
    public function bootstrap(Request $request, ?int $customerId = null): self
    {
        $this->statefulCookieEncryption = $this->requestIsStateful($request);

        if ($customerId) {
            $this->customerId = $customerId;
            return $this;
        }

        // 1) Prefer the widget-supplied identity. The storefront WRITES its
        //    guest cart with localStorage['cookie'] (sent here as cookie_id /
        //    cookie), so this is the authoritative id — using it keeps chat
        //    carts on the SAME identity the storefront writes to.
        $input = trim((string) ($request->input('cookie_id') ?? $request->input('cookie', '')));
        if ($input !== '') {
            $plain = $this->normalizeCookie($input);
            if ($plain !== null && $plain !== '') {
                $this->cookieId = $plain;
                // Keep the host's encrypted form when the input already was one,
                // otherwise re-encrypt so the cookie we hand back is parity-safe.
                $this->encryptedCookie = $this->looksEncrypted($input)
                    ? $input
                    : Crypt::encrypt($plain);
                $this->isNew = false;
                return $this;
            }
        }

        // 2) Fall back to the storefront cookie (set by the host cart API).
        $cookie = $request->cookie('guest_id');
        if ($cookie) {
            try {
                $plain = Crypt::decrypt($cookie);
                if ($plain !== null && $plain !== '') {
                    $this->cookieId = (string) $plain;
                    $this->encryptedCookie = $cookie;
                    return $this;
                }
            } catch (\Throwable) {
                // broken cookie — fall through to minting below
            }
        }

        // 3) Brand-new guest: mint an identity and hand it back via Set-Cookie.
        $this->cookieId = Str::random(20);
        $this->encryptedCookie = Crypt::encrypt($this->cookieId);
        $this->isNew = true;
        return $this;
    }

    /** Normalize a raw-or-encrypted cookie value to the plain cookie id. */
    private function normalizeCookie(string $value): ?string
    {
        if ($this->looksEncrypted($value)) {
            try {
                $plain = Crypt::decrypt($value);
                if ($plain !== null && $plain !== '') return (string) $plain;
            } catch (\Throwable) {
                return null; // encrypted-shaped but not ours → ignore
            }
        }

        // Raw id: accept sane ids only (the host uses 20-char random strings,
        // but tolerate anything short/url-safe).
        if (strlen($value) <= 64 && preg_match('/^[A-Za-z0-9_\-:.]+$/', $value)) {
            return $value;
        }

        return null;
    }

    /** Heuristic: Laravel encrypted payloads are base64 JSON (long, 'eyJ…'). */
    private function looksEncrypted(string $value): bool
    {
        return strlen($value) > 64 || str_starts_with($value, 'eyJ');
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function cookieId(): ?string
    {
        return $this->cookieId;
    }

    public function encryptedCookie(): ?string
    {
        return $this->encryptedCookie;
    }

    /**
     * The exact value to pass to Cookie::make('guest_id', ...) so the value
     * the BROWSER stores matches the storefront's format:
     *  - non-stateful route: Laravel doesn't touch cookies → send encrypted.
     *  - stateful route (Sanctum/EncryptCookies): middleware encrypts once →
     *    send the PLAIN id so it isn't double-encrypted.
     */
    public function cookieValueForResponse(): ?string
    {
        if ($this->cookieId === null) return null;
        return $this->statefulCookieEncryption ? $this->cookieId : $this->encryptedCookie;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    /** Is this request stateful (Sanctum) so EncryptCookies will run? */
    private function requestIsStateful(Request $request): bool
    {
        try {
            $domains = (array) config('sanctum.stateful', []);
            $host = $request->getHost();
            foreach ($domains as $d) {
                $d = trim((string) $d);
                if ($d === '') continue;
                if (strcasecmp($d, $host) === 0) return true;
                // Leading-dot wildcard support (e.g. ".gunmahalalfood.com").
                if (str_starts_with($d, '.') && str_ends_with($host, $d)) return true;
            }
            // Fallback: an Origin/Referer from a stateful host means Sanctum
            // will treat the request as stateful even if the host differs.
            $origin = (string) ($request->header('Origin') ?? $request->header('Referer') ?? '');
            if ($origin !== '') {
                $oh = parse_url($origin, PHP_URL_HOST) ?: '';
                foreach ($domains as $d) {
                    $d = ltrim(trim((string) $d), '.');
                    if ($d !== '' && strcasecmp($d, (string) $oh) === 0) return true;
                }
            }
        } catch (\Throwable) { /* default to non-stateful */ }
        return false;
    }
}
