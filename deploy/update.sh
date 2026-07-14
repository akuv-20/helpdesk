#!/usr/bin/env bash
#
# Actualiza el portal en producción tras un cambio en el repo.
#
#   Uso:   bash deploy/update.sh
#
# Trae el código, reinstala dependencias, recompila los assets, corre las
# migraciones, regenera las cachés de Laravel y recarga PHP-FPM (OPcache).
# Pensado para el server Ubuntu donde el portal corre junto a GLPI, usando el
# PHP 8.4 explícito (ver deploy/DEPLOY.md). El sitio queda en modo mantenimiento
# mientras dura la actualización y se levanta solo al terminar (incluso si falla).
#
# Variables opcionales (override):
#   PHP=php8.4  FPM_SERVICE=php8.4-fpm  bash deploy/update.sh

set -euo pipefail

# --- Config -------------------------------------------------------------
PHP="${PHP:-php8.4}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$APP_DIR"
echo "==> Proyecto: $APP_DIR"

# --- Modo mantenimiento (se levanta sí o sí al salir) -------------------
$PHP artisan down --retry=15 || true
trap '$PHP artisan up || true' EXIT

# --- Código -------------------------------------------------------------
echo "==> git pull"
git pull --ff-only

# --- Dependencias PHP ---------------------------------------------------
COMPOSER_BIN="$(command -v composer || echo /usr/local/bin/composer)"
echo "==> composer install"
$PHP "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

# --- Base de datos ------------------------------------------------------
echo "==> migraciones"
$PHP artisan migrate --force

# --- Frontend (Vite) ----------------------------------------------------
echo "==> build de assets (npm)"
npm ci
npm run build

# --- Cachés de Laravel --------------------------------------------------
# optimize:clear vacía route/config/view/cache; optimize vuelve a cachear
# config + rutas (aquí es donde se soluciona el 404 de rutas nuevas).
echo "==> regenerando cachés"
$PHP artisan optimize:clear
$PHP artisan optimize
$PHP artisan view:cache

# --- OPcache ------------------------------------------------------------
# Sin esto, con opcache.validate_timestamps=0 el PHP viejo sigue en memoria
# (rutas/controladores nuevos no se ven aunque el código ya esté en disco).
echo "==> recargando $FPM_SERVICE"
sudo systemctl reload "$FPM_SERVICE"

echo "==> Listo. Portal actualizado."
