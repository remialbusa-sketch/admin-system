#!/usr/bin/env bash
#
# Idempotent production deployment for the MCBTSi Admin System on cPanel
# shared hosting (shared cPanel account: mcbtsi.com). Run from the SERVER via
# SSH after pulling/cloning the repo — or point it at a checkout that already
# lives at ~/admin-system.
#
# Container-free design (see docs/monday-integration-and-cpanel-deployment.md §B):
#   - No Node.js on the server: assets are built LOCALLY/CI and shipped as
#     public/build (the `build-local` helper below or your own step). This
#     script never runs `npm`.
#   - SQLite -> MySQL is wired through the server .env (never committed).
#   - Migrations are forward-only; back up the DB in cPanel BEFORE each deploy.
#   - Scheduler + queue are cron-driven, not supervisor-run (plan §B7).
#
# Usage (on the server):
#   bash scripts/deploy.sh                # code, deps, migrate, caches
#   bash scripts/deploy.sh --build        # also rebuild local assets first
#
# Env the script reads (set these in the server environment or export them):
#   DEPLOY_DOMAIN      (optional) health-check URL after deploy
#   MONDAY_SYNC_ENABLED, MONDAY_API_TOKEN, MONDAY_*_BOARD_ID   (from .env)
#
set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/admin-system}"
PHP_BIN="${PHP_BIN:-php}"
LOCK_DIR="${APP_DIR}/storage/logs"
LOCK_FILE="$LOCK_DIR/deploy.lock"

# ---- Prevent overlapping deploys (migrate is not concurrency-safe) ----------
mkdir -p "$LOCK_DIR"
if [ -f "$LOCK_FILE" ] && kill -0 "$(cat "$LOCK_FILE")" 2>/dev/null; then
    echo "Another deploy is already running. Aborting." >&2
    exit 1
fi
echo "$$" > "$LOCK_FILE"
trap 'rm -f "$LOCK_FILE"' EXIT

cd "$APP_DIR"

echo "==> [1/6] Pulling latest code (branch main)"
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Not a git checkout at $APP_DIR — aborting (clone the repo first)." >&2
    exit 1
fi
git checkout main
git pull --ff-only origin main

echo "==> [2/6] Installing Composer deps (no-dev)"
"$PHP_BIN" -r "file_exists('.env') || exit(1)" || { echo '.env missing — copy .env.production.example to .env and set values.' >&2; exit 1; }
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "==> [3/6] Running migrations (back up the DB in cPanel first!)"
"$PHP_BIN" artisan migrate --force

echo "==> [4/6] Compiling config/route/view caches (production)"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

echo "==> [5/6] Fixing permissions (storage + bootstrap/cache writable)"
chmod -R u+rwX storage bootstrap/cache
chmod 600 .env 2>/dev/null || true
find storage -type d -exec chmod u+rwX {} \; 2>/dev/null || true

echo "==> [6/6] Restarting the scheduler/queue isn't needed (cron-triggered)."

# Optional health check after deploy.
if [ -n "${DEPLOY_DOMAIN:-}" ]; then
    echo "==> Health check: https://${DEPLOY_DOMAIN}/"
    curl -fsS -o /dev/null -w "  HTTP %{http_code}\n" "https://${DEPLOY_DOMAIN}/login" || echo "  (non-fatal) health check failed"
fi

echo "==> Deploy complete."
echo "    Reminder: run one `monday:sync <domain> --dry-run` after enabling a board,"
echo "    and confirm the mono cron (`* * * * * … artisan schedule:run`) is installed."
