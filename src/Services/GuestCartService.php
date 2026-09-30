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
     * Resolve once per request (chat controller calls this before the agent runs).
     */
    public function bootstrap(Request $request, ?int $customerId = null): self
    {
        if ($customerId) {
            $this->customerId = $customerId;
            return $this;
        }

        // 1) Prefer the storefront cookie (set by the host cart API) — the
        //    single source of truth shared with the storefront's own cart.
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
                // broken cookie — fall through to the input below
            }
        }

        // 2) Widget-provided identity. It may hand us EITHER the raw cookie id
        //    or the host's encrypted cookie value (localStorage['cookie']).
        //    Normalize both to the SAME plain id the storefront stores in the
        //    DB, so chat-added items and the host cart never diverge.
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
                // Not "new": the identity already exists on the host side.
                $this->isNew = false;
                return $this;
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

    public function isNew(): bool
    {
        return $this->isNew;
    }
}
