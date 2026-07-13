<?php

return [

    /*
    | Correos con acceso al área de administración (módulo de conexión GLPI).
    | Lista separada por comas en TICKET_ADMINS.
    | En local, el acceso de desarrollo también cuenta como admin.
    */
    'admins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('TICKET_ADMINS', ''))
    )),
];
