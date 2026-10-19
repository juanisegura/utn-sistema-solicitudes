<?php

declare(strict_types=1);

// Arranque mínimo para scripts CLI y tests que usan la DB (D-58): autoload, helpers, zona horaria,
// Config y Database configuradas. Sin sesión, headers ni handlers HTTP. Devuelve la config ya validada
// (para decisiones como app.env); nunca la imprime. Si falta config.local.php lanza RuntimeException;
// si es inválida, InvalidArgumentException.

use App\Core\Config;
use App\Core\Database;

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/helpers.php';

date_default_timezone_set('America/Argentina/Buenos_Aires'); // D-24, igual que bootstrap.php

Config::configure(require __DIR__ . '/config/config.php'); // valida (AU-3); excepción si es inválida
Database::configure(Config::get('db'));

return Config::all();
