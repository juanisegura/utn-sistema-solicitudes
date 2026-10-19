<?php
// config.local.example.php — Plantilla de configuración local, pensada para DESARROLLO.
// Copiar a config.local.php (ignorado por Git) y completar las credenciales.
// Nunca versionar config.local.php.
// En producción: 'env' => 'production' (fuerza debug = false aunque acá diga true) y nunca el seed.
// Una configuración inválida (tipos o rangos) se rechaza al arrancar: ver App\Core\Config.
return [
    'app' => [
        'env'      => 'local',          // local | production
        'debug'    => true,             // solo en desarrollo; con env = production se ignora (siempre false)
        'base_url' => 'http://localhost:8080',
        // Opcionales (defaults en config.php):
        // 'session_timeout'  => 1800,           // segundos de inactividad
        // 'login_max_por_cuenta' => 5,         // intentos fallidos por IP + email en la ventana
        // 'login_max_por_ip'     => 20,        // intentos fallidos por IP sola en la ventana
        // 'login_ventana_seg'    => 900,       // ventana del límite de intentos (segundos)
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
