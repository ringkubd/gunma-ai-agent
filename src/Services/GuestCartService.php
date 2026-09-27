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

        // Prefer the storefront cookie; fall back to an explicit cookie_id input.
        $cookie = $request->cookie('guest_id');
        $input  = trim((string) $request->input('cookie_id', ''));

        if ($cookie) {
            try {
                $this->cookieId = Crypt::decrypt($cookie);
                $this->encryptedCookie = $cookie;
                return $this;
            } catch (\Throwable) {
                // broken cookie — regenerate below
            }
        }

        if ($input !== '' && strlen($input) <= 64) {
            // Widget-provided raw cookie id (localStorage['cookie']).
            $this->cookieId = $input;
            $this->encryptedCookie = Crypt::encrypt($input);
            $this->isNew = true;
            return $this;
        }

        // Brand-new guest: mint an identity and hand it back via Set-Cookie.
        $this->cookieId = Str::random(20);
        $this->encryptedCookie = Crypt::encrypt($this->cookieId);
        $this->isNew = true;
        return $this;
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
