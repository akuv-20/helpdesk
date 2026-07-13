# Mesa de Ayuda (Portal de usuario · GLPI 11)

Portal web enfocado en el **usuario final** para crear y seguir tickets, conectado a
**GLPI 11**. Los técnicos siguen trabajando en GLPI original. Patrón **SPA + BFF**:
el backend Laravel es el único que ve credenciales; el navegador solo recibe una
cookie de sesión `httpOnly`.

## Arquitectura de autenticación (dos puertas)

| Puerta | Para qué | Quién valida | Protocolo |
|--------|----------|--------------|-----------|
| **A** | Login de la persona | Entra ID | OIDC (Laravel Socialite) |
| **B** | Backend → API de GLPI | GLPI | OAuth2 `client_credentials` (driver `oauth`) o token legacy (driver `legacy`) |

- **Puerta A:** el portal es su *propio* Service Provider en Entra ID, independiente
  del SSO de GLPI. El usuario nunca pasa por GLPI para entrar.
- **Puerta B:** el backend usa **una cuenta de servicio** e **impersona** al
  solicitante (lo fija por email). El usuario solo ve sus propios tickets porque el
  filtro lo impone el backend, nunca el navegador.

Mientras `GLPI_DRIVER` esté vacío, el portal funciona en **modo demo** (login + UI),
sin romper, para poder probar antes de conectar GLPI.

## Stack

Laravel 13 · Inertia · Vue 3 · Tailwind · PHP 8.4

## Puesta en marcha (desarrollo)

```bash
composer install
npm install
# ya hay un .env local listo con Herd
php artisan key:generate
php artisan migrate
npm run dev                 # o: npm run build
```

Con Laravel Herd el sitio queda en `https://ticket.test`.

Para probar la UI sin Entra todavía, en `.env` (solo local):
```
ALLOW_DEV_LOGIN=true
```
y usa el botón **"Acceso de desarrollo"** en la pantalla de login.

## Estructura clave

```
app/Http/Controllers/Auth/EntraController.php   # Puerta A: redirect/callback/logout + dev-login
app/Http/Controllers/DashboardController.php     # "Mis solicitudes"
app/Http/Controllers/TicketController.php        # Crear ticket
app/Services/Glpi/GlpiClient.php                 # Puerta B: token, drivers oauth/legacy, impersonación
config/glpi.php                                  # Config de la conexión a GLPI
resources/js/Pages/                              # Login, Dashboard, Tickets/Create (Vue)
```

## Pendientes antes de producción

1. **Confirmar contra el Swagger** (`api.php/v2.3/doc`) los endpoints/campos marcados
   con `// TODO[api]` en `GlpiClient.php` (búsqueda de usuario, campo de solicitante,
   payload de creación). Si OAuth2 aún no está, usar driver `legacy` como plan B.
2. **Registrar el portal en Entra ID** (app registration) y rellenar `MICROSOFT_*`.
3. **Crear la cuenta de servicio en GLPI** con un perfil de permisos mínimos
   (crear/leer tickets) y generar credenciales OAuth2 o el `user_token` legacy.
4. **Deploy en Ubuntu** dedicado: Caddy (HTTPS automático) + Docker, sesiones en
   Redis, `ufw` restringido (solo 80/443), y restringir el acceso a la API de GLPI
   por IP de este servidor.

## Pruebas

```bash
php artisan test --filter=PortalFlowTest
```
