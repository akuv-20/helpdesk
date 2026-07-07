<?php

return [

    /*
    | Correos con acceso al área de administración (builder de formularios).
    | Lista separada por comas en HELPDESK_ADMINS.
    | En local, el acceso de desarrollo también cuenta como admin.
    */
    'admins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('HELPDESK_ADMINS', ''))
    )),

    // Tipos de campo soportados por el motor de formularios.
    'field_inputs' => ['text', 'textarea', 'select', 'number', 'date'],
];
