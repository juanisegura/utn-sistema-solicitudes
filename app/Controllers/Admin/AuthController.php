<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\LoginThrottle;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** Login / logout del administrador (F4). */
final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::user() !== null) {
            Response::redirect('/admin');
        }
        $this->renderLogin('', null, 200);
    }

    public function login(): void
    {
        // Con sesión activa no se procesan credenciales (ni se regenera la sesión de otro login).
        if (Auth::user() !== null) {
            Response::redirect('/admin');
        }
        $email = Request::string('email') ?? '';
        $password = Request::string('password') ?? '';
        $ip = Request::ip();

        // Límite de intentos (ADR-0009): el intento se reserva antes de verificar (exacto bajo
        // concurrencia). Bloqueado → ni se verifica la password ni se consulta admins.
        [$intento, $espera] = LoginThrottle::reservar($ip, $email);
        if ($intento === null) {
            $minutos = (int) ceil($espera / 60);
            $this->renderLogin(
                $email,
                'Demasiados intentos. Probá de nuevo en ' . $minutos . ($minutos === 1 ? ' minuto.' : ' minutos.'),
                429,
                ['Retry-After' => (string) $espera]
            );

            return;
        }

        if (Auth::attempt($email, $password)) {
            try {
                LoginThrottle::exito($intento, $ip, $email);
            } catch (\Throwable $e) {
                // La sesión ya quedó autenticada: si la limpieza falla, no se responde 500 con
                // sesión válida (D-53). Se cierra y la excepción sigue al handler (log + 500).
                Auth::logout();
                throw $e;
            }
            Response::redirect('/admin');
        }

        // Todo fallo cuenta (también >72 bytes, NUL o email no UTF-8): la fila reservada queda.
        LoginThrottle::fallo();
        // Mismo mensaje para email inexistente, password errónea o admin inactivo.
        $this->renderLogin($email, 'Email o contraseña incorrectos.', 401);
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/admin/login');
    }

    /** Re-muestra el email (escapado en la vista); la password nunca vuelve al HTML. */
    /** @param array<string, string> $headers */
    private function renderLogin(string $email, ?string $error, int $status, array $headers = []): void
    {
        Response::html(View::render('admin/login', [
            'title' => 'Ingreso al panel',
            'email' => mb_substr(trim($email), 0, 254, 'UTF-8'), // D-53: tope, como el campo
            'error' => $error,
            'admin' => null,
        ], 'layouts/admin'), $status, $headers);
    }
}
