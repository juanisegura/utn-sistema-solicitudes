<?php

declare(strict_types=1);

// Configuración efectiva: defaults seguros pisados por config.local.php (fuera de Git).
// Si falta el local se corta acá: sin credenciales la app no puede funcionar.

$defaults = [
    'app' => [
        'env'      => 'production',
        'debug'    => false,
        'base_url' => '',
        // Inactividad máxima de la sesión, en segundos (D-21).
        'session_timeout' => 1800,
        // Límite de intentos de login (ADR-0009): fallos tolerados en la ventana antes del 429.
        'login_max_por_cuenta' => 5,   // por IP + email
        'login_max_por_ip'     => 20,  // por IP sola (muchos emails desde la misma IP)
        'login_ventana_seg'    => 900,
        // IPs o CIDR de proxies propios (ADR-0009, D-47). Vacío: se ignora X-Forwarded-For.
        'trusted_proxies' => [],
        // Rotación de storage/logs/app.log (M-6): tamaño máximo y cantidad de archivos viejos.
        'log_max_bytes'    => 1048576,
        'log_max_archivos' => 5,
        // Solicitudes por página en el listado del panel (M-3).
        'por_pagina' => 20,
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
];

$localFile = __DIR__ . '/config.local.php';
if (!is_file($localFile)) {
    // Mensaje con ruta relativa al proyecto: no revela la ubicación en el servidor.
    throw new RuntimeException(
        'Falta la configuración local: copiar app/config/config.local.example.php '
        . 'a app/config/config.local.php y completar las credenciales.'
    );
}

$local = require $localFile;
if (!is_array($local)) {
    throw new RuntimeException('app/config/config.local.php debe devolver un array.');
}

$config = array_replace_recursive($defaults, $local);
// (bool) 'false' sería true: se interpreta como booleano y cualquier valor dudoso queda en false.
$config['app']['debug'] = filter_var($config['app']['debug'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

return $config;
