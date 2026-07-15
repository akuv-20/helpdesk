<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Puerta B — Conexión del backend con la API de GLPI 11
    |--------------------------------------------------------------------------
    |
    | El backend (BFF) es el ÚNICO que habla con GLPI. El navegador nunca ve
    | estas credenciales. Soportamos dos drivers de autenticación:
    |
    |   - "oauth"  : API nueva v2, grant client_credentials (recomendado).
    |   - "legacy" : API clásica (apirest.php) con App-Token + user_token.
    |                Plan B mientras se habilita OAuth2 en GLPI.
    |
    | Si no hay nada configurado (driver = null), el portal arranca igual y
    | muestra estados vacíos en vez de romper, para poder probar el login.
    |
    */

    'driver' => env('GLPI_DRIVER'), // 'oauth' | 'legacy' | null

    // URL base de GLPI, sin barra final. Ej: https://helpdesk.verfrut.cl
    'base_url' => rtrim((string) env('GLPI_BASE_URL', ''), '/'),

    'timeout' => (int) env('GLPI_TIMEOUT', 15),

    // Verificar TLS. Solo poner en false en entornos de prueba internos.
    'verify' => env('GLPI_VERIFY_TLS', true),

    /*
    | Zona horaria en la que la API de GLPI DEVUELVE las fechas. GLPI convierte
    | las horas según la zona del usuario de la sesión; como el portal consulta
    | con la cuenta de servicio, las fechas llegan en la zona de esa cuenta (o,
    | si no tiene, en la del servidor de GLPI). El portal las reinterpreta desde
    | aquí y las convierte a la zona de cada usuario (`users.timezone`) al
    | mostrarlas. Si las horas se ven corridas, este es el valor a ajustar.
    */
    'timezone' => env('GLPI_TIMEZONE', 'America/Santiago'),

    /*
    | Driver OAuth2 (API v2). Confirmado contra el Swagger de la instancia
    | (GLPI High-Level REST API 2.3.0): los grants disponibles son
    | "authorizationCode" y "password" (NO existe client_credentials).
    | Para una cuenta de servicio usamos el grant "password": el backend se
    | autentica como el usuario de servicio y obtiene un token con scope "api".
    | Token endpoint: /api.php/token  ·  Scopes: email,user,api,inventory,status,graphql
    */
    'oauth' => [
        'token_url' => env('GLPI_OAUTH_TOKEN_URL', env('GLPI_BASE_URL', '').'/api.php/token'),
        'api_url' => env('GLPI_API_URL', env('GLPI_BASE_URL', '').'/api.php/v2'),
        'client_id' => env('GLPI_OAUTH_CLIENT_ID'),
        'client_secret' => env('GLPI_OAUTH_CLIENT_SECRET'),
        // Credenciales del usuario de servicio (password grant).
        'username' => env('GLPI_OAUTH_USERNAME'),
        'password' => env('GLPI_OAUTH_PASSWORD'),
        'scope' => env('GLPI_OAUTH_SCOPE', 'api'),
    ],

    /*
    | Driver legacy (apirest.php). Necesita un App-Token (config de la API en
    | GLPI) y el user_token de una cuenta de servicio dedicada.
    */
    'legacy' => [
        'api_url' => env('GLPI_LEGACY_API_URL', env('GLPI_BASE_URL', '').'/apirest.php'),
        'app_token' => env('GLPI_APP_TOKEN'),
        'user_token' => env('GLPI_USER_TOKEN'),
    ],

    /*
    | Cliente OAuth authorization_code para acciones que exigen la identidad del
    | PROPIO usuario (p. ej. aprobar/rechazar validaciones): GLPI solo permite
    | que el validador responda desde su sesión, así que el backend obtiene un
    | token del usuario (login por SAML/Entra, casi transparente) y actúa como
    | él, no como la cuenta de servicio. Redirect URI a registrar en GLPI:
    | {APP_URL}/tickets/validacion/callback
    */
    'oauth_ac' => [
        'client_id' => env('GLPI_AC_CLIENT_ID'),
        'client_secret' => env('GLPI_AC_CLIENT_SECRET'),
    ],

    /*
    | Cómo mapeamos a la persona autenticada por Entra con su usuario de GLPI.
    | Por defecto buscamos por email. El backend impone el solicitante; el
    | usuario nunca puede pedir tickets de otra persona.
    */
    'requester_match' => env('GLPI_REQUESTER_MATCH', 'email'), // 'email' | 'login'

    // Categoría/urgencia por defecto al crear tickets desde el portal.
    'defaults' => [
        'type' => (int) env('GLPI_DEFAULT_TYPE', 2),       // 1=Incidencia, 2=Requerimiento
        'urgency' => (int) env('GLPI_DEFAULT_URGENCY', 3), // 3=Media
    ],
];
