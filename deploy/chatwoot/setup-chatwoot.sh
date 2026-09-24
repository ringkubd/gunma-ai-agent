#!/usr/bin/env bash
#
# setup-chatwoot.sh — Deploy self-hosted Chatwoot (Docker) + nginx + SSL.
#
# Requires sudo. Idempotent: safe to re-run. After it finishes, configure the
# Chatwoot UI (inboxes, API token) and set the Laravel env vars printed at the end.
#
# Usage:
#   sudo bash deploy/chatwoot/setup-chatwoot.sh
#
set -euo pipefail

DOMAIN="${CHATWOOT_DOMAIN:-support.gunmahalalfood.com}"
INSTALL_DIR="${CHATWOOT_DIR:-/home/gunmahalalfood/chatwoot}"
NGINX_SITE="/etc/nginx/sites-available/${DOMAIN}"

log()  { printf '\033[1;32m[+]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m[x]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[✓]\033[0m %s\n' "$*"; }

[[ "$(id -u)" -eq 0 ]] || { err "Run with sudo."; exit 1; }

command -v docker >/dev/null || { err "Docker not installed."; exit 1; }
docker compose version >/dev/null 2>&1 || { err "Docker Compose plugin missing."; exit 1; }

log "Preparing $INSTALL_DIR ..."
mkdir -p "$INSTALL_DIR"

# ── docker-compose.yml + .env ────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp -f "$SCRIPT_DIR/docker-compose.yml" "$INSTALL_DIR/docker-compose.yml"

if [[ ! -f "$INSTALL_DIR/.env" ]]; then
  log "Creating $INSTALL_DIR/.env (generating secrets) ..."
  POSTGRES_PW="$(openssl rand -hex 16)"
  SECRET_KB="$(openssl rand -hex 48)"
  cat > "$INSTALL_DIR/.env" <<EOF
CHATWOOT_FRONTEND_URL=https://${DOMAIN}
POSTGRES_PASSWORD=${POSTGRES_PW}
SECRET_KEY_BASE=${SECRET_KB}
MAILER_SENDER_EMAIL=Gunma Support <support@gunmahalalfood.com>
EOF
  chmod 600 "$INSTALL_DIR/.env"
  ok "Secrets written to $INSTALL_DIR/.env"
else
  warn "Existing $INSTALL_DIR/.env kept"
fi

# ── Start containers ─────────────────────────────────────────────────────────
log "Starting Chatwoot containers ..."
cd "$INSTALL_DIR"
docker compose pull
docker compose up -d

log "Waiting for the web container..."
for i in $(seq 1 30); do
  if curl -fsS -m 5 http://127.0.0.1:3000/ >/dev/null 2>&1; then ok "Chatwoot is up on :3000"; break; fi
  sleep 5
  [[ "$i" -eq 30 ]] && warn "Chatwoot not responding yet — check: docker compose logs web"
done

# ── Database seed (creates the first admin) ──────────────────────────────────
log "Seeding database (creates default admin) ..."
docker compose run --rm web bundle exec rails db:seed 2>/dev/null || \
  warn "db:seed returned non-zero (already seeded?)."

# ── nginx ────────────────────────────────────────────────────────────────────
log "Installing nginx site for ${DOMAIN} ..."
cp -f "$SCRIPT_DIR/nginx.conf" "$NGINX_SITE"
ln -sf "$NGINX_SITE" "/etc/nginx/sites-enabled/${DOMAIN}"
nginx -t && systemctl reload nginx && ok "nginx reloaded"

# ── SSL ──────────────────────────────────────────────────────────────────────
if command -v certbot >/dev/null 2>&1; then
  log "Requesting certificate for ${DOMAIN} ..."
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email || \
    warn "certbot failed — run manually once DNS is ready."
else
  warn "certbot not installed — install it and issue a certificate for ${DOMAIN}"
fi

cat <<EOF

============================================================
 Chatwoot deployment step complete.
============================================================
 Next (manual, in the Chatwoot UI at https://${DOMAIN}):
   1. Log in with the seeded admin (see 'docker compose run --rm web bundle exec rails c'
      or the seed output for credentials).
   2. Create Inboxes: Website, Email (SMTP/IMAP), WhatsApp, Facebook.
   3. Settings → API → generate an access token.
   4. Settings → Integrations → Webhooks:
        URL:    https://${DOMAIN%%support.}api.gunmahalalfood.com/api/chat/webhook/chatwoot
        Secret: same value as CHATWOOT_WEBHOOK_SECRET below
      (Beta API: https://beta-api.gunmahalalfood.com/api/chat/webhook/chatwoot)

 Then add to the Laravel .env and run: php artisan config:clear

   CHATWOOT_BASE_URL=https://${DOMAIN}
   CHATWOOT_API_KEY=<token from step 3>
   CHATWOOT_ACCOUNT_ID=1
   CHATWOOT_WEBHOOK_SECRET=<random string; also paste in the webhook Secret>
   CHATWOOT_HMAC_SECRET=<optional; for X-Chatwoot-Signature verification>
============================================================
EOF
