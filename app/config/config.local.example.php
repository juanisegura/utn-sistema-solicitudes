<?php
// config.local.example.php — Plantilla de configuración local.
// Copiar a config.local.php (ignorado por Git) y completar las credenciales.
// Nunca versionar config.local.php.
return [
    'app' => [
        'env'      => 'local',          // local | production
        'debug'    => true,             // false fuera de desarrollo: nunca mostrar errores internos
        'base_url' => 'http://localhost:8080',
        // Opcionales (defaults en config.php):
        // 'session_timeout'  => 1800,           // segundos de inactividad
        // 'trusted_proxies'  => ['10.0.0.0/8'], // solo si hay un proxy/CDN propio delante
        // 'log_max_bytes'    => 1048576,        // rotación de storage/logs/app.log
        // 'log_max_archivos' => 5,
        // 'por_pagina'       => 20,             // solicitudes por página en el panel
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
