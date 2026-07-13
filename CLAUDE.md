# Ticket — Portal de usuario sobre GLPI 11

Portal web enfocado en el **usuario final** para crear/seguir tickets de **GLPI 11**.
Los técnicos siguen trabajando en GLPI original. Instancia GLPI: `https://helpdesk.verfrut.cl`
(Swagger v2 en `api.php/v2.3/doc`). Stack: **Laravel 13 + Inertia + Vue 3 + Tailwind**, PHP 8.4 (Herd → `https://ticket.test`). Idioma del producto: español.

## Arquitectura de autenticación (dos "puertas" independientes)

- **Puerta A — login del usuario:** OIDC contra **Entra ID** vía Socialite (`socialiteproviders/microsoft`).
  El portal es su propio Service Provider; NO pasa por GLPI. (Se eligió OIDC sobre SAML por simplicidad.)
  Controlador: `app/Http/Controllers/Auth/EntraController.php`. Incluye `ALLOW_DEV_LOGIN` (solo local)
  para entrar sin Entra durante desarrollo.
- **Puerta B — backend → API GLPI:** patrón SPA + BFF (el backend es el único que ve credenciales;
  el navegador solo recibe cookie `httpOnly`). Cuenta de servicio + impersonación (fija el solicitante
  por email). Cliente: `app/Services/Glpi/GlpiClient.php`.
- **Puerta C — token del PROPIO usuario (OAuth authorization_code):** para acciones que GLPI ata a la
  sesión del usuario (aprobar/rechazar **validaciones**), la cuenta de servicio NO sirve. El portal
  obtiene un token del usuario vía authorization_code (login GLPI por SAML → casi transparente) y actúa
  como él. Servicio: `app/Services/Glpi/GlpiUserOAuth.php`. Cliente OAuth aparte (`config glpi.oauth_ac`,
  env `GLPI_AC_*`), redirect URI `{APP_URL}/tickets/validacion/callback`. El token (access+refresh) se
  **guarda cifrado** en `users` y se reutiliza/renueva → el consentimiento se pide una sola vez.

**Provisioning JIT del usuario en GLPI:** GLPI crea usuarios en su primer login SAML; como el portal entra
por Entra, el solicitante puede no existir. `GlpiClient::ensureUser()` lo da de alta al vuelo por legacy
(`POST /User` con login=email + `POST /UserEmail`) al crear su primer ticket. Login=email para que, cuando
luego entre por SAML, GLPI reutilice ese registro (no duplique).

**Zona horaria:** en el login se pide el campo `country` a Graph (`config services.microsoft.fields`) y
`EntraController::timezoneFromCountry()` la deriva (CL/Chile→America/Santiago, PE/Perú→America/Lima),
guardándola en `users.timezone`. Se envía a GLPI **solo** en el alta JIT del usuario (no en cada ticket).

## Hallazgos del Swagger real (GLPI High-Level REST API 2.3.0)

- API v2 activa (`/api.php/v2/` → 401). Legacy `apirest.php` también activa (plan B).
- OAuth2: grants **authorizationCode** y **password** (NO `client_credentials`). Para cuenta de servicio
  se usa **password grant**. Token: `/api.php/token`, scope `api`. Clientes OAuth se crean en GLPI Setup.
- Recursos: Ticket = **`/Assistance/Ticket`**; User = **`/Administration/User`**; categorías = **`/Dropdowns/ITILCategory`**.
- Filtrado **RSQL** (`filter=`), `sort=campo:desc`, paginación `start`/`limit`. Solicitante vía `team` (role=requester).
- Drivers en `GlpiClient`: `oauth` y `legacy`. Config en `config/glpi.php`. Si `GLPI_DRIVER` vacío → modo demo (no rompe).
- **Confirmado con token real (cerrados los `// TODO[api]`):**
  - Usuario por email: filtro RSQL `emails.email==<correo>` (el simple `emails==` no filtra).
  - Crear ticket: se hace por **legacy** en UN solo POST `/Ticket` (`_users_id_requester` + categoría +
    inline + adjuntos), porque el v2 ignora actores/inline. (El v2 fija el solicitante recién en un 2º paso
    vía `POST /Assistance/Ticket/{id}/TeamMember` — no lo usamos.)
  - Listar "mis tickets": el API v2 **NO** filtra por solicitante (paths `team.*` dan 500;
    `users_id_requester` se ignora y devuelve TODO). Por eso el listado usa **híbrido**: si hay tokens
    legacy, `ticketsForRequesterPaged` filtra por el **search legacy** (`/search/Ticket`, `field 4`=
    solicitante, `field 12`=estado, `field 1|21`=búsqueda título/descripción) con `range` (paginación) +
    `totalcount`, todo en el SERVIDOR y sí escala. Si no hay tokens legacy, cae a oauth (baja hasta 200 y
    filtra/pagina en memoria). El `DashboardController` es server-driven (params `q`/`status`/`page`). Los
    escaneos de aprobaciones (`pendingApprovalsForUser`) siguen por oauth (acotados por `global_validation`).
  - Ping de salud: usa `/Administration/User` (no `/User/Me`, que exige scope `user`).
  - Adjuntos: el API v2 **no** vincula documentos a tickets (siempre 403 `ERROR_RIGHT_MISSING`, aun con
    super-admin). Se usan por el **API legacy** (`apirest.php`): `POST /Document/` multipart con
    `uploadManifest` = `{"input":{"name","_filename":[...],"itemtype":"Ticket","items_id":<id>}}` +
    parte binaria `filename[0]`. Sube y vincula en un POST. Por eso hay que tener App-Token + User-Token
    configurados aunque el driver sea `oauth` (patrón híbrido: OAuth crea, legacy adjunta) → `uploadDocument()`.
  - **Validaciones (TicketValidation):** el técnico pide una aprobación a una persona **en GLPI**; el portal
    solo la muestra y deja responderla. Vienen en el timeline (`type:"Validation"`) / `/Timeline/Validation`
    con `requested_approver_id` (validador), `status` (2=pendiente, 3=concedida, 4=rechazada),
    `submission_comment`, `requester`, `approver`. **Clave:** GLPI **solo permite que el propio validador
    responda desde SU sesión** — la cuenta de servicio NO puede (devuelve 2xx e ignora el `status`), ni siendo
    super-admin, ni con substitutos (403 al crearlos), ni leyendo el token del usuario (no lo expone). Por eso
    la respuesta se hace con la **Puerta C** (token del usuario). Aprobar/rechazar → `PATCH
    /Assistance/Ticket/{id}/Timeline/Validation/{vid}` `{status, approval_comment}`. `global_validation` del
    ticket (2=esperando) sirve para acotar el escaneo de "aprobaciones pendientes" en el dashboard.

## Módulos construidos

1. **Portal usuario:** login, "Mis solicitudes" (`DashboardController`), crear ticket (wizard).
2. **Wizard de creación:** Tipo (Incidente|Solicitud) → navegación por el árbol de categorías → Asunto+Descripción.
   El ticket se arma con la ITIL category + asunto + descripción (sin campos dinámicos). El árbol ITIL de GLPI
   es **Área > Incidente|Solicitud > … (profundidad variable)**: unas ramas tienen 3 niveles
   (`Soporte > Incidente > Equipos Computacionales`) y otras más (`Sistemas > Incidente > Frusys > Error`).
   El nivel 2 (Incidente/Solicitud) NUNCA se muestra: se deriva del tipo del paso 1 y se usa como filtro.
   `GlpiClient::categoriesByType($type)` baja las ITILCategory, parte el `completename` por " > ", **elimina el
   nivel 2** y construye un **árbol anidado** (`buildCategoryTree`); el wizard baja nivel a nivel hasta una
   **hoja** (nodo sin hijos), que es la categoría real que se envía a GLPI. Los nodos con hijos son solo
   navegación. Endpoint `/tickets/categorias?type=`. En modo demo (sin GLPI) usa un árbol de ejemplo interno
   (`demoCategoryRows`) que replica la estructura real, incluyendo una rama de 4 niveles (Sistemas).
3. **Módulo de Conexión GLPI (admin):** `/admin/conexion` configura OAuth/legacy desde la UI (en vez del .env).
   Ajustes en tabla `settings` (secretos cifrados con Crypt) vía `app/Services/Settings/Settings.php`.
   `app/Services/Glpi/GlpiConfig::resolve()` combina .env con BD (BD manda). Botón "Probar conexión" → `GlpiClient::ping()`.
4. **Detalle de ticket** (`/tickets/{id}`, `Tickets/Show.vue`): timeline (seguimientos, solución, documentos,
   **validaciones**), responder, aprobar/rechazar **solución** (solicitante) y **validación** (validador).
   Acceso: lo ve el solicitante O quien es/fue **validador** del ticket (aunque no sea solicitante).
   Al crear un ticket, modal con el número asignado (`Dashboard.vue`, flash `createdTicket`).
5. **Aprobaciones (validaciones):** recuadro para aprobar/rechazar en `Show.vue`; sección "Aprobaciones
   pendientes" en el dashboard (`GlpiClient::pendingApprovalsForUser`). La respuesta pasa por la Puerta C
   (`TicketController::validation` → rebote OAuth si no hay token → `validationCallback`).
6. **Explorador de Entra (admin):** `/admin/explorador-entra` consulta Graph por usuario (permiso de
   **aplicación** `User.Read.All`, client_credentials) para ver qué campos trae Entra. `EntraExplorerController`.

**Admin:** middleware `admin` (`EnsureUserIsAdmin`) + `User::isAdmin()` (local dev-login o email en
`config/ticket.php` `admins` / env `TICKET_ADMINS`). Navbar (`AppLayout.vue`): Home + Aprobaciones para
todos; admin: Marca, Acceso, Explorar Entra, GLPI, OAuth aprob.

## Decisión cerrada — additional fields eliminados

Se **descartó** el motor de campos dinámicos (builder + `form_definitions` + `TicketComposer`). El cliente
solo usa las **ITIL categories** de GLPI para clasificar; el ticket se compone con categoría + asunto +
descripción libre. Esto evita el problema de los campos no filtrables/editables en GLPI: la categoría es
un campo nativo y filtrable. Si en el futuro se necesitan campos extra, habría que revisar el plugin "Fields".

## Pendientes generales

- **HECHO:** aprobar/rechazar solución + reabrir · **validaciones/aprobaciones** (Puerta C) · buscador/filtros
  y paginación en "Mis solicitudes" · mantenedores de marca (`/admin/marca`) · provisioning JIT + timezone.
- **Encuesta de satisfacción:** pendiente. Al cerrar un ticket GLPI genera `TicketSatisfaction`; mostrarla y
  permitir responder desde el portal. (Es la última funcionalidad grande de usuario.)
- **HECHO** — mantenedor del 2º cliente OAuth (`oauth_ac`): `/admin/aprobaciones-oauth` (client_id/secret,
  secreto cifrado en `settings`). Las pantallas de config tienen **copiar/revelar (ojo)** de valores
  (`CopyableField.vue`; los secretos se precargan a propósito para verlos/copiarlos, solo en vistas admin).
- **Diseño (aplicado):** identidad Unifrutti — paleta corporativa mapeada sobre la escala `blue` de
  Tailwind v4 (`@theme` en `resources/css/app.css`: `blue-600`=#2463AE, `blue-900`=#0B3456, celeste
  #E6ECF5) + fuente **Montserrat** + degradado en login + **navbar corporativa** (degradado azul, base
  redondeada, texto blanco, botón Home, iconos). Nombre configurable por `APP_NAME` ("HelpDesk Unifrutti").
  Pendiente (opcional): versión **blanca** del logo para el navbar oscuro, esquinas más redondeadas en
  tarjetas, acento rojo `#DA251C`.
- Correo: GLPI encola bien las notificaciones pero el envío depende del cron `queuednotification`
  (modo GLPI se dispara con visitas web; para envío 24/7 → cron del sistema en modo CLI).
- **HECHO** — listado por search legacy (híbrido) con **paginación real** + búsqueda + filtro de estado
  server-side (`ticketsForRequesterPaged`); ya no hay tope de 200.
- **VERIFICADO** — el nivel 2 del árbol ITIL real es exactamente "Incidente"/"Solicitud" (24 categorías),
  así que `categoriesByType` filtra bien. Nada que ajustar.
- **DESPLEGADO** en producción: **ticket.verfrut.cl** en el mismo Ubuntu Server que GLPI, con **Apache2 +
  PHP-FPM 8.4 + SQLite** (sesiones/caché/colas en la BD). Requiere **PHP 8.4** (el `composer.lock` trae
  Symfony 8.x); se instala junto al PHP de GLPI y se usa `php8.4` explícito para composer/artisan. HTTPS con
  el **wildcard `*.verfrut.cl`** ya existente (reutilizado del vhost de GLPI). Admins por `TICKET_ADMINS`.
  Guía y vhost en `deploy/`. Migración futura a `unifrutti.com`: ajustar `APP_URL`, URL base de GLPI y
  redirect URIs (Entra + OAuth), y usar el wildcard `*.unifrutti.com` (vhost de ejemplo en `deploy/`).

## Comandos

```bash
composer install && npm install   # en el server de prod: php8.4 /usr/local/bin/composer install
php artisan migrate          # sin seeders: las categorías viven en GLPI
npm run dev                  # o npm run build
php artisan test             # 26 tests (PortalFlow, IntegrationModule, Entra, Branding)
```
