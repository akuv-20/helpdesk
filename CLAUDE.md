# Helpdesk — Portal de usuario sobre GLPI 11

Portal web enfocado en el **usuario final** para crear/seguir tickets de **GLPI 11**.
Los técnicos siguen trabajando en GLPI original. Instancia GLPI: `https://helpdesk.verfrut.cl`
(Swagger v2 en `api.php/v2.3/doc`). Stack: **Laravel 13 + Inertia + Vue 3 + Tailwind**, PHP 8.4 (Herd → `https://helpdesk.test`). Idioma del producto: español.

## Arquitectura de autenticación (dos "puertas" independientes)

- **Puerta A — login del usuario:** OIDC contra **Entra ID** vía Socialite (`socialiteproviders/microsoft`).
  El portal es su propio Service Provider; NO pasa por GLPI. (Se eligió OIDC sobre SAML por simplicidad.)
  Controlador: `app/Http/Controllers/Auth/EntraController.php`. Incluye `ALLOW_DEV_LOGIN` (solo local)
  para entrar sin Entra durante desarrollo.
- **Puerta B — backend → API GLPI:** patrón SPA + BFF (el backend es el único que ve credenciales;
  el navegador solo recibe cookie `httpOnly`). Cuenta de servicio + impersonación (fija el solicitante
  por email). Cliente: `app/Services/Glpi/GlpiClient.php`.

## Hallazgos del Swagger real (GLPI High-Level REST API 2.3.0)

- API v2 activa (`/api.php/v2/` → 401). Legacy `apirest.php` también activa (plan B).
- OAuth2: grants **authorizationCode** y **password** (NO `client_credentials`). Para cuenta de servicio
  se usa **password grant**. Token: `/api.php/token`, scope `api`. Clientes OAuth se crean en GLPI Setup.
- Recursos: Ticket = **`/Assistance/Ticket`**; User = **`/Administration/User`**; categorías = **`/Dropdowns/ITILCategory`**.
- Filtrado **RSQL** (`filter=`), `sort=campo:desc`, paginación `start`/`limit`. Solicitante vía `team` (role=requester).
- Drivers en `GlpiClient`: `oauth` y `legacy`. Config en `config/glpi.php`. Si `GLPI_DRIVER` vacío → modo demo (no rompe).
- **Pendiente confirmar con token real** (marcado `// TODO[api]`): propiedad RSQL exacta del solicitante
  en el listado y forma exacta del item de `team` al crear.

## Módulos construidos

1. **Portal usuario:** login, "Mis solicitudes" (`DashboardController`), crear ticket (wizard).
2. **Formularios dinámicos (motor):** GLPI 11 tiene Formularios nativos PERO no expuestos en la API v2.3.
   Por eso motor propio: esquema JSON propio + renderer liviano (`resources/js/Components/DynamicForm.vue`).
   Tabla `form_definitions` (type incident|request + itil_category_id → `fields` JSON). Regla condicional
   `showIf:{field,equals|in}` evaluada igual en back (`FormDefinition::fieldIsVisible`) y front. Wizard de 3
   pasos: tipo → categoría ITIL → campos dinámicos (endpoint `/tickets/form-schema`). Respuestas se componen
   en el `content` del ticket (`app/Services/Tickets/TicketComposer.php`).
3. **Builder drag-and-drop (admin):** `resources/js/Pages/Admin/Forms/{Index,Edit}.vue` con vuedraggable,
   vista previa en vivo reutilizando `DynamicForm`. Produce el mismo JSON que consume el wizard.
4. **Módulo de Conexión GLPI (admin):** `/admin/conexion` configura OAuth/legacy desde la UI (en vez del .env).
   Ajustes en tabla `settings` (secretos cifrados con Crypt) vía `app/Services/Settings/Settings.php`.
   `app/Services/Glpi/GlpiConfig::resolve()` combina .env con BD (BD manda). Botón "Probar conexión" → `GlpiClient::ping()`.

**Admin:** middleware `admin` (`EnsureUserIsAdmin`) + `User::isAdmin()` (local dev-login o email en
`config/helpdesk.php` `admins` / env `HELPDESK_ADMINS`).

## DECISIÓN ABIERTA (importante) — dónde guardar los "additional fields"

El builder guarda las respuestas del usuario en el `content` del ticket. Problema planteado por el cliente:
así **no son editables como campos en la UI de GLPI ni filtrables/reportables**. La API NO expone definiciones
de campos personalizados (ni Forms module ni plugin Fields), y el recurso Ticket es esquema cerrado
(sin `additionalProperties`). Opciones evaluadas:
- A) content (texto) — no editable/filtrable en GLPI.
- B) BD propia — no visible en GLPI.
- C) **mapear cada campo a un campo REAL de GLPI** (nativo o plugin "Fields") → editable + filtrable. Requiere
  que el campo exista en GLPI y sea **escribible por API** (por confirmar con token; v2 no lo expone, legacy quizás).

Recomendación pendiente de decidir: **híbrido** — builder para la UX condicional + por cada campo un "campo
destino en GLPI"; mapear a campos reales solo los pocos que deban filtrarse, el resto al `content`.
**Antes de construir esto hay que verificar con un token real** si el plugin Fields está instalado y qué campos
de ticket son escribibles por API.

## Pendientes generales

- Registrar el portal en Entra ID y rellenar `MICROSOFT_*`.
- Crear cliente OAuth + usuario de servicio en GLPI (permisos mínimos), configurar en `/admin/conexion`.
- Cerrar los `// TODO[api]` con un token real.
- Resolver la DECISIÓN ABIERTA de additional fields.
- Deploy: Ubuntu dedicado, Caddy (HTTPS auto) + Docker, sesiones en Redis, `ufw` restringido.

## Comandos

```bash
composer install && npm install
php artisan migrate --seed   # incluye rama de ejemplo "Incidente · Soporte"
npm run dev                  # o npm run build
php artisan test             # 23 tests (PortalFlow, AdminFormBuilder, IntegrationModule)
```
