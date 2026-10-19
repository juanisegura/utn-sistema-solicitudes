<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
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

    public function create(): void
    {
        $this->renderForm([], [], 200);
    }

    public function store(): void
    {
        [$data, $errors] = Validator::validate(Request::only(array_keys(self::RULES)), self::RULES, self::LABELS);
        if ($errors !== []) {
            $this->renderForm($data, $errors, 422);

            return;
        }

        $id = Solicitud::create($data['nombre'], $data['email'], $data['asunto'], $data['descripcion']);
        Session::flash(self::FLASH_ID, $id);
        // PRG: recargar /gracias no reenvía el formulario.
        Response::redirect('/gracias');
    }

    public function gracias(): void
    {
        // HEAD (prefetch, chequeos de links) no debe gastar la confirmación antes de que la vea el usuario.
        $id = Request::method() === 'HEAD' ? Session::peekFlash(self::FLASH_ID) : Session::pullFlash(self::FLASH_ID);
        if (!is_int($id)) {
            Response::redirect('/');
        }

        Response::html(View::render('solicitudes/gracias', ['title' => 'Solicitud registrada', 'id' => $id]));
    }

    /**
     * @param array<string, string> $old
     * @param array<string, string> $errors
     */
    private function renderForm(array $old, array $errors, int $status): void
    {
        Response::html(View::render('solicitudes/create', [
            'title'  => 'Nueva solicitud',
            'old'    => $old,
            'errors' => $errors,
            'labels' => self::LABELS,
        ]), $status);
    }
}
