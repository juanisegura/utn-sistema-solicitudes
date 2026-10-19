<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\FormThrottle;
use App\Core\LoginThrottle;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\View;
use App\Models\Admin;

/** Cambio de contraseña del admin logueado (F-5). Rutas con auth + CSRF. */
final class CuentaController
{
    private const MIN_CARACTERES = 12;
    /** Aviso que muestra el layout del panel después del redirect. */
    public const FLASH_PANEL = 'panel_aviso';

    public function showPassword(): void
    {
        $this->renderForm([], null, 200);
    }

    public function cambiarPassword(): void
    {
        $admin = Auth::user(); // requireAdmin ya garantizó que existe
        $id = (int) $admin['id'];
        $actual = Request::string('password_actual') ?? '';
        $nueva = Request::string('password_nueva') ?? '';
        $confirmacion = Request::string('password_confirmacion') ?? '';
        $ip = Request::ip();

        // La contraseña actual es una credencial: sus intentos cuentan en el límite de login de esta
        // cuenta (IP + email del admin, D-38). Bloqueado → 429 sin verificarla.
        [$intento, $espera] = LoginThrottle::reservar($ip, (string) $admin['email']);
        if ($intento === null) {
            SecurityLog::evento('login_bloqueado', 'origen=cuenta email=' . SecurityLog::emailRef((string) $admin['email']) . ' retry=' . $espera, $id);
            $this->renderForm([], 'Demasiados intentos. Probá de nuevo en ' . FormThrottle::minutos($espera) . '.', 429, ['Retry-After' => (string) $espera]);

            return;
        }

        $errores = [];
        if (Auth::passwordActualValida($id, $actual)) {
            LoginThrottle::exito($intento, $ip, (string) $admin['email']);
        } else {
            LoginThrottle::fallo(); // la fila reservada queda como intento fallido
            SecurityLog::evento('password_fallida', 'email=' . SecurityLog::emailRef((string) $admin['email']), $id);
            $errores['password_actual'] = 'La contraseña actual no es correcta.';
        }

        $errorNueva = $this->validarNueva($nueva, $actual);
        if ($errorNueva !== null) {
            $errores['password_nueva'] = $errorNueva;
        }
        if (!hash_equals($nueva, $confirmacion)) {
            $errores['password_confirmacion'] = 'La confirmación no coincide con la nueva contraseña.';
        }

        if ($errores !== []) {
            $this->renderForm($errores, null, 422); // nunca se re-muestran contraseñas

            return;
        }

        Auth::cambiarPassword($id, $nueva);
        SecurityLog::evento('password_cambiada', '', $id);
        Session::flash(self::FLASH_PANEL, 'Contraseña actualizada.');
        Response::redirect('/admin');
    }

    private function validarNueva(#[\SensitiveParameter] string $nueva, #[\SensitiveParameter] string $actual): ?string
    {
        if (mb_strlen($nueva, 'UTF-8') < self::MIN_CARACTERES) {
            return 'La nueva contraseña debe tener al menos ' . self::MIN_CARACTERES . ' caracteres.';
        }
        if (!Admin::passwordAcceptable($nueva)) {
            return 'La nueva contraseña no puede superar 72 bytes ni contener el carácter nulo.';
        }
        if (hash_equals($actual, $nueva)) {
            return 'La nueva contraseña debe ser distinta de la actual.';
        }

        return null;
    }

    /**
     * @param array<string, string> $errores
     * @param array<string, string> $headers
     */
    private function renderForm(array $errores, ?string $aviso, int $status, array $headers = []): void
    {
        Response::html(View::render('admin/cuenta/password', [
            'title'   => 'Cambiar contraseña',
            'errores' => $errores,
            'aviso'   => $aviso,
            'admin'   => Auth::user(),
        ], 'layouts/admin'), $status, $headers);
    }
}
