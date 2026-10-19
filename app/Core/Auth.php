<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Admin;

/** Autenticación del único rol (admin). En sesión se guarda solo el id (ADR-0004). */
final class Auth
{
    private const SESSION_KEY = 'admin_id';

    /**
     * Hash bcrypt de un valor aleatorio descartado, con el mismo algoritmo y costo que
     * Admin::HASH_ALGO/HASH_OPTIONS (bcrypt, 12). Se verifica contra él cuando el email no existe
     * para que la respuesta tarde lo mismo (anti-enumeración). Si cambia el costo, regenerarlo.
     */
    private const DUMMY_HASH = '$2y$12$b4G73qS5Pyvkg2oN9vJu3uJgNEnABoDdNXn0tPgqhodpewCOLWvyy';

    /** @var array<string, mixed>|null admin de esta request, ya verificado contra la DB */
    private static ?array $current = null;

    /** true si las credenciales son válidas y el admin está activo; cualquier fallo es indistinguible. */
    public static function attempt(string $email, #[\SensitiveParameter] string $password): bool
    {
        // >72 bytes o con NUL nunca fue una password válida (bcrypt truncaría): fallo genérico sin verificar.
        if (!Admin::passwordAcceptable($password)) {
            return false;
        }
        // Bytes no UTF-8 en el email: la DB en modo estricto rechazaría la consulta (→ 500).
        if (!mb_check_encoding($email, 'UTF-8')) {
            return false;
        }

        $admin = Admin::findByEmail($email); // normaliza y compara en binario (D-41)
        $hash = $admin['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash);
        if (!$valid || $admin === null || (int) $admin['activo'] !== 1) {
            return false;
        }

        $id = (int) $admin['id'];
        // Efectos en la DB antes de fijar la sesión (D-53): si alguno falla, la request termina en
        // 500 sin dejar una sesión autenticada a medias.
        Admin::touchLastLogin($id);
        if (Admin::needsRehash($hash)) {
            Admin::updatePasswordHash($id, $password);
        }
        // Nuevo ID de sesión y nuevo token CSRF: lo que se haya obtenido como invitado deja de servir.
        // El admin_id se escribe último: es lo que vuelve autenticada a la sesión.
        Session::regenerate();
        Csrf::rotate();
        Session::set(self::SESSION_KEY, $id);

        return true;
    }

    /**
     * Admin autenticado o null. Re-verifica en la DB en cada request: un admin borrado o
     * desactivado pierde el acceso de inmediato, aunque su sesión siga viva.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $id = Session::get(self::SESSION_KEY);
        if (!is_int($id)) {
            return null;
        }
        $admin = Admin::find($id);
        if ($admin === null || (int) $admin['activo'] !== 1) {
            Session::forget(self::SESSION_KEY);

            return null;
        }

        return self::$current = $admin;
    }

    /** Guardia de las rutas 'auth'. Sin sesión: GET → login; POST → 401 sin ejecutar nada (D-17). */
    public static function requireAdmin(): void
    {
        if (self::user() !== null) {
            return;
        }
        if (Request::method() === 'GET' || Request::method() === 'HEAD') {
            Response::redirect('/admin/login');
        }
        Response::abort(401);
    }

    public static function logout(): void
    {
        self::$current = null;
        Session::destroy();
    }
}
