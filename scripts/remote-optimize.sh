#!/bin/sh
set -eu

DEPLOY_PATH="${DEPLOY_PATH:?DEPLOY_PATH is required}"
DEBUG_LOG="$DEPLOY_PATH/deploy-debug.txt"
PUBLIC_HTML="${PUBLIC_HTML_PATH:-$(dirname "$DEPLOY_PATH")/public_html}"
APP_FOLDER="$(basename "$DEPLOY_PATH")"

cd "$DEPLOY_PATH"

PHP_FROM_ENV="${PHP_BIN:-}"
PHP_BIN=""
for candidate in $PHP_FROM_ENV /usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
  if [ -n "$candidate" ] && [ -x "$candidate" ]; then
    PHP_BIN="$candidate"
    break
  fi
done

: > "$DEBUG_LOG"

log() {
  echo "$@" | tee -a "$DEBUG_LOG"
}

log "=== deploy $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
log "DEPLOY_PATH=$DEPLOY_PATH"
log "PUBLIC_HTML=$PUBLIC_HTML"
log "APP_FOLDER=$APP_FOLDER"

if [ ! -f artisan ]; then
  log "ERROR: artisan missing"
  exit 1
fi

if [ ! -f .env ]; then
  log "ERROR: .env missing"
  exit 1
fi

chmod 600 .env
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/routes.php bootstrap/cache/events.php
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

if [ ! -d "$PUBLIC_HTML" ]; then
  log "ERROR: public_html not found"
  exit 1
fi

cat > "$PUBLIC_HTML/index.php" <<EOF
<?php

require __DIR__ . '/../${APP_FOLDER}/public/index.php';
EOF
chmod 644 "$PUBLIC_HTML/index.php"
log "Wrote $PUBLIC_HTML/index.php"

cat > "$PUBLIC_HTML/check.php" <<'PHP'
<?php
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__ . '/../APP_FOLDER_PLACEHOLDER';
echo 'PHP ' . PHP_VERSION . "\n";
echo "root=$root\n";
if (!is_dir($root)) { echo "ERROR: app dir missing\n"; exit(1); }
try {
    require $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::connection()->getPdo();
    echo "DB: OK\nLaravel: " . $app->version() . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
PHP
sed -i "s/APP_FOLDER_PLACEHOLDER/${APP_FOLDER}/g" "$PUBLIC_HTML/check.php"
chmod 644 "$PUBLIC_HTML/check.php"

{
  if [ -f "$DEPLOY_PATH/scripts/hostinger-public_html.htaccess" ]; then
    cat "$DEPLOY_PATH/scripts/hostinger-public_html.htaccess"
  fi
  if [ -f "$DEPLOY_PATH/public/.htaccess" ]; then
    cat "$DEPLOY_PATH/public/.htaccess"
  fi
} > "$PUBLIC_HTML/.htaccess"
chmod 644 "$PUBLIC_HTML/.htaccess"
log "Wrote public_html/.htaccess"

touch "$DEPLOY_PATH/storage/logs/laravel.log" 2>/dev/null || true
chmod -R 777 "$DEPLOY_PATH/storage" 2>/dev/null || true

if [ -z "$PHP_BIN" ]; then
  log "WARN: no PHP CLI - skipped artisan"
  exit 0
fi

log "PHP_BIN=$PHP_BIN"
"$PHP_BIN" artisan package:discover --ansi 2>&1 | tee -a "$DEBUG_LOG" || true
"$PHP_BIN" artisan config:clear 2>&1 | tee -a "$DEBUG_LOG" || true
"$PHP_BIN" artisan route:cache 2>&1 | tee -a "$DEBUG_LOG" || true
"$PHP_BIN" artisan config:cache 2>&1 | tee -a "$DEBUG_LOG" || true
"$PHP_BIN" artisan db:show 2>&1 | tee -a "$DEBUG_LOG" || true
log "Deploy finished OK"
