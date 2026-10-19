<?php

declare(strict_types=1);

// Crea un administrador pidiendo los datos por consola (la password no se muestra).
// Uso: php scripts/crear-admin.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/autoload.php';

use App\Models\Admin;

const PASSWORD_MIN = 12;
// bcrypt (PASSWORD_DEFAULT) ignora lo que pasa de 72 bytes: se rechaza en vez de truncar en silencio.
const PASSWORD_MAX_BYTES = Admin::MAX_PASSWORD_BYTES;

function preguntar(string $texto): string
{
    echo $texto;
    $linea = fgets(STDIN);

    return $linea === false ? '' : trim($linea);
}

/**
 * Limitación conocida (B5): si se corta con Ctrl+C mientras pide la password, la terminal
 * queda sin eco (se arregla con `stty sane`); en Windows no hay stty y la password se ve.
 */
function preguntarOculto(string $texto): string
{
    $esTerminal = stream_isatty(STDIN);
    if ($esTerminal) {
        shell_exec('stty -echo');
    }
    try {
        echo $texto;
        $linea = fgets(STDIN);
    } finally {
        if ($esTerminal) {
            shell_exec('stty echo');
            echo "\n";
        }
    }

    // Solo se quita el salto de línea: espacios al inicio/fin son parte de la password.
    return $linea === false ? '' : rtrim($linea, "\r\n");
}

function fallar(string $mensaje): never
{
    fwrite(STDERR, "Error: {$mensaje}\n");
    exit(1);
}

$nombre = preguntar('Nombre: ');
if ($nombre === '' || mb_strlen($nombre) > 100) {
    fallar('el nombre es obligatorio (máx. 100 caracteres).');
}

$email = mb_strtolower(preguntar('Email: '));
if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fallar('email inválido.');
}

$password = preguntarOculto('Password (mín. ' . PASSWORD_MIN . ' caracteres): ');
if (mb_strlen($password) < PASSWORD_MIN) {
    fallar('la password debe tener al menos ' . PASSWORD_MIN . ' caracteres.');
}
if (strlen($password) > PASSWORD_MAX_BYTES) {
    fallar('la password supera ' . PASSWORD_MAX_BYTES . ' bytes.');
}
if (!hash_equals($password, preguntarOculto('Repetir password: '))) {
    fallar('las passwords no coinciden.');
}

try {
    if (Admin::findByEmail($email) !== null) {
        fallar('ya existe un administrador con ese email.');
    }
    $id = Admin::create($nombre, $email, $password);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Administrador creado (id {$id}).\n";
