<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
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
        $email = Request::string('email') ?? '';
        $password = Request::string('password') ?? '';

        if (Auth::attempt($email, $password)) {
            Response::redirect('/admin');
        }

        // Mismo mensaje para email inexistente, password errónea o admin inactivo.
        $this->renderLogin($email, 'Email o contraseña incorrectos.', 401);
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/admin/login');
    }

    /** Re-muestra el email (escapado en la vista); la password nunca vuelve al HTML. */
    private function renderLogin(string $email, ?string $error, int $status): void
    {
        Response::html(View::render('admin/login', [
            'title' => 'Ingreso al panel',
            'email' => trim($email),
            'error' => $error,
            'admin' => null,
        ], 'layouts/admin'), $status);
    }
}
