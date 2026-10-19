<?php

declare(strict_types=1);

// Tabla de rutas (PLAN §Rutas). Opciones: 'auth' => true exige admin; los POST validan CSRF
// por defecto en el Router (se puede desactivar con 'csrf' => false, hoy ninguna lo hace).

use App\Controllers\SolicitudController;

return [
    ['GET',  '/',            [SolicitudController::class, 'create']],
    ['POST', '/solicitudes', [SolicitudController::class, 'store']],
    ['GET',  '/gracias',     [SolicitudController::class, 'gracias']],

    // TODO A1-03: descomentar al crear los controllers de admin y App\Core\Auth.
    // ['GET',  '/admin/login',                   [AuthController::class, 'showLogin']],
    // ['POST', '/admin/login',                   [AuthController::class, 'login']],
    // ['POST', '/admin/logout',                  [AuthController::class, 'logout'], ['auth' => true]],
    // ['GET',  '/admin',                         [SolicitudAdminController::class, 'index'], ['auth' => true]],
    // ['GET',  '/admin/solicitudes/{id}',        [SolicitudAdminController::class, 'show'], ['auth' => true]],
    // ['POST', '/admin/solicitudes/{id}/estado', [SolicitudAdminController::class, 'updateEstado'], ['auth' => true]],
];
