# Deploy — Portal Ticket en Ubuntu Server (Apache2 + PHP-FPM + SQLite)

Destino actual: **ticket.verfrut.cl** en el mismo servidor donde corre GLPI
(`helpdesk.verfrut.cl`). Base de datos: **SQLite** (sesiones/caché/colas también
en la BD; un solo archivo).

> **Certificado:** el wildcard `*.verfrut.cl` ya instalado para `helpdesk.verfrut.cl`
> cubre también `ticket.verfrut.cl` — se **reutilizan las mismas rutas** del cert (paso 7),
> no hay que instalar nada nuevo.
>
> **Migración futura a unifrutti.com:** cuando GLPI pase a `unifrutti.com`, cambiar
> `APP_URL`, la **URL base de GLPI** (`/admin/conexion`) y **re-registrar** las redirect
> URIs (Entra + cliente OAuth de aprobaciones) al nuevo dominio. Hay un vhost de ejemplo
> para ese caso en `deploy/apache-ticket.unifrutti.com.conf`.

---

## 1. Requisitos en el servidor

**PHP 8.4** (obligatorio: el `composer.lock` trae Symfony 8.x que exige >= 8.4.1).
Se instala junto al PHP de GLPI (conviven); NO cambies el `php` por defecto del
sistema para no afectar el cron/CLI de GLPI — usa `php8.4` explícito en el portal.

```bash
# PPA con PHP 8.4 (si no lo tienes)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-sqlite3 php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd \
  unzip git

# Composer
php8.4 -r "copy('https://getcomposer.org/installer','composer-setup.php');"
sudo php8.4 composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php

# Node.js LTS (para compilar los assets en el server)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Verifica: `php8.4 -v`, `node -v`, `php8.4 /usr/local/bin/composer -V`.

## 2. Código

```bash
sudo mkdir -p /var/www/ticket
sudo chown -R $USER:$USER /var/www/ticket
git clone https://github.com/akuv-20/helpdesk.git /var/www/ticket
cd /var/www/ticket
```

## 3. Dependencias + build

```bash
php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader
npm ci
npm run build          # genera public/build
```

## 4. Configuración (.env)

```bash
cp .env.example .env    # o sube tu .env
php8.4 artisan key:generate
```

Valores mínimos de producción en `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ticket.verfrut.cl

# NO habilitar en producción
ALLOW_DEV_LOGIN=false

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/ticket/database/database.sqlite

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

# Entra (Puerta A) — redirect de producción
MICROSOFT_CLIENT_ID=...
MICROSOFT_CLIENT_SECRET=...
MICROSOFT_TENANT_ID=...
MICROSOFT_REDIRECT_URI=https://ticket.verfrut.cl/auth/entra/callback

# Cliente OAuth de aprobaciones (Puerta C)
GLPI_AC_CLIENT_ID=...
GLPI_AC_CLIENT_SECRET=...

# GLPI sigue en helpdesk.verfrut.cl (sin cambios por ahora)
GLPI_BASE_URL=https://helpdesk.verfrut.cl
```

> La conexión a GLPI (URL base, cliente OAuth de servicio, tokens legacy) y el cliente
> `oauth_ac` también se pueden cargar desde la UI admin (`/admin/conexion`, `/admin/acceso`,
> `/admin/aprobaciones-oauth`), que mandan sobre el `.env`.

## 5. Base de datos SQLite + migraciones

```bash
touch database/database.sqlite
php8.4 artisan migrate --force
```

## 6. Permisos (Apache corre como www-data)

```bash
sudo chown -R www-data:www-data /var/www/ticket/storage \
  /var/www/ticket/bootstrap/cache /var/www/ticket/database \
  /var/www/ticket/public/branding
sudo find /var/www/ticket/storage -type d -exec chmod 775 {} \;
```

## 7. Optimización + VirtualHost

```bash
php8.4 artisan config:cache
php8.4 artisan route:cache
php8.4 artisan view:cache
```

> Si luego editas `.env`, vuelve a correr `php8.4 artisan config:cache`.

Averigua las rutas del wildcard que ya usa helpdesk y reutilízalas:

```bash
sudo grep -ri "SSLCertificate" /etc/apache2/sites-available/ | grep -i helpdesk
```

Copia el vhost incluido y pega esas rutas de certificado + ajusta la versión de PHP-FPM:

```bash
sudo cp deploy/apache-ticket.verfrut.cl.conf /etc/apache2/sites-available/
sudo nano /etc/apache2/sites-available/apache-ticket.verfrut.cl.conf   # pega las rutas del cert
sudo a2enmod rewrite ssl headers proxy_fcgi setenvif
sudo a2enconf php8.4-fpm            # FPM del portal (GLPI mantiene el suyo)
sudo a2ensite apache-ticket.verfrut.cl
sudo apache2ctl configtest && sudo systemctl reload apache2
```

> Asegúrate de que `ticket.verfrut.cl` resuelva por DNS al servidor (registro A/CNAME).

## 8. Registrar las redirect URIs (producción)

- **Entra ID** (app registration): agregar redirect URI
  `https://ticket.verfrut.cl/auth/entra/callback`
- **Cliente OAuth de aprobaciones en GLPI**: redirect URI
  `https://ticket.verfrut.cl/tickets/validacion/callback`

## 9. Actualizaciones futuras

```bash
cd /var/www/ticket
git pull
php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader
npm ci && npm run build
php8.4 artisan migrate --force
php8.4 artisan config:cache && php8.4 artisan route:cache && php8.4 artisan view:cache
sudo systemctl reload apache2
```

---

## Notas

- **Correo/notificaciones**: las envía GLPI, no el portal. El envío 24/7 depende del
  cron `queuednotification` de GLPI en modo CLI (config del server GLPI, aparte).
- **SQLite** es suficiente por el bajo volumen (config + sesiones). Si a futuro crece,
  migrar a MySQL es cambiar `DB_*` y re-migrar.
- **Backups**: basta respaldar `database/database.sqlite`, `.env` y `public/branding/`.
