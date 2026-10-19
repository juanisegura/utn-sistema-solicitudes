<?php
// config.local.example.php — Plantilla de configuración local.
// Copiar a config.local.php (ignorado por Git) y completar las credenciales.
// Nunca versionar config.local.php.
return [
    'app' => [
        'env'      => 'local',          // local | production
        'debug'    => true,             // false fuera de desarrollo: nunca mostrar errores internos
        'base_url' => 'http://localhost:8080',
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'utn_solicitudes',
        'user'    => 'utn_solicitudes',
        'pass'    => 'CAMBIAR',
        'charset' => 'utf8mb4',
    ],
];
