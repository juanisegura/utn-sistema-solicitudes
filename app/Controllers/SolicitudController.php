<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CodigoSeguimiento;
use App\Core\FormThrottle;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\Solicitud;

/** Formulario público: alta de solicitudes (F1–F3). */
final class SolicitudController
{
    private const RULES = [
        'nombre'      => ['required', 'max:100'],
        'email'       => ['required', 'email', 'max:254'],
        'asunto'      => ['required', 'max:150'],
        'descripcion' => ['required', 'max:5000', 'multiline'],
    ];

    private const LABELS = [
        'nombre'      => 'Nombre',
        'email'       => 'Email',
        'asunto'      => 'Asunto',
        'descripcion' => 'Descripción',
    ];

    private const FLASH_ID = 'solicitud_creada';
    /** Código de seguimiento (F-3): vive en la sesión solo entre el 302 y el GET de /gracias. */
    private const FLASH_CODIGO = 'solicitud_codigo';
    /** Confirmación sin número para el honeypot (F-1): el bot no puede distinguirla de un alta real. */
    private const FLASH_GENERICA = 'solicitud_generica';
    private const HONEYPOT = 'sitio_web';

    public function create(): void
    {
        $this->renderForm([], [], 200);
    }

    public function store(): void
    {
        [$data, $errors] = Validator::validate(Request::only(array_keys(self::RULES)), self::RULES, self::LABELS);

        // F-1: todo envío que pasó el CSRF cuenta (válido, con errores o del honeypot), reservado
        // antes de procesarlo. Bloqueado → 429 con el formulario y sus valores (escapados en la vista).
        [$envio, $espera] = FormThrottle::reservar('solicitud', Request::ip());
        if ($envio === null) {
            SecurityLog::evento('form_bloqueado', 'retry=' . $espera);
            $aviso = 'Demasiados envíos. Probá de nuevo en ' . FormThrottle::minutos($espera) . '.';
            $this->renderForm($data, [], 429, $aviso, ['Retry-After' => (string) $espera]);

            return;
        }

        // Honeypot: un humano no ve ni completa el campo. Se responde como un éxito y no se guarda nada.
        if ($this->honeypotCompletado()) {
            SecurityLog::evento('honeypot');
            Session::flash(self::FLASH_GENERICA, true);
            Response::redirect('/gracias');
        }

        if ($errors !== []) {
            $this->renderForm($data, $errors, 422);

            return;
        }

        // F-3: el código se muestra una sola vez; en la DB queda solo su hash (ADR-0013).
        $codigo = CodigoSeguimiento::generar();
        $id = Solicitud::create($data['nombre'], $data['email'], $data['asunto'], $data['descripcion'], CodigoSeguimiento::hash($codigo));
        Session::flash(self::FLASH_ID, $id);
        Session::flash(self::FLASH_CODIGO, $codigo);
        // PRG: recargar /gracias no reenvía el formulario.
        Response::redirect('/gracias');
    }

    public function gracias(): void
    {
        // HEAD (prefetch, chequeos de links) no debe gastar la confirmación antes de que la vea el usuario.
        $leer = Request::method() === 'HEAD' ? Session::peekFlash(...) : Session::pullFlash(...);
        $id = $leer(self::FLASH_ID);
        $codigo = $leer(self::FLASH_CODIGO);
        $generica = !is_int($id) && $leer(self::FLASH_GENERICA) === true;
        if (!is_int($id) && !$generica) {
            Response::redirect('/');
        }

        Response::html(View::render('solicitudes/gracias', [
            'title'  => 'Solicitud registrada',
            'id'     => $generica ? null : $id,
            'codigo' => $generica || !is_string($codigo) ? null : $codigo,
        ]));
    }

    /** Lleno = con texto (tras trim) o enviado como array: en ambos casos no lo completó una persona. */
    private function honeypotCompletado(): bool
    {
        if (!Request::has(self::HONEYPOT)) {
            return false;
        }
        $valor = Request::string(self::HONEYPOT);

        return $valor === null || trim($valor) !== '';
    }

    /**
     * @param array<string, string> $old
     * @param array<string, string> $errors
     * @param array<string, string> $headers
     */
    private function renderForm(array $old, array $errors, int $status, ?string $aviso = null, array $headers = []): void
    {
        Response::html(View::render('solicitudes/create', [
            'title'  => 'Nueva solicitud',
            'old'    => $old,
            'errors' => $errors,
            'labels' => self::LABELS,
            'aviso'  => $aviso,
        ]), $status, $headers);
    }
}
