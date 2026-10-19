<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Estado;
use App\Models\Solicitud;
use DateTimeImmutable;

/** Panel: listado con filtro, detalle y cambio de estado (F5–F8). Todas las rutas exigen admin. */
final class SolicitudAdminController
{
    private const FLASH_ESTADO = 'estado_actualizado';

    public function index(): void
    {
        $estado = $this->filtroEstado();
        $solicitudes = array_map(self::presentar(...), Solicitud::all($estado));

        // Contadores sobre el total (no sobre el filtro) para los links de filtro.
        $conteo = array_count_values(array_column(Solicitud::all(), 'estado'));

        $this->render('admin/solicitudes/index', [
            'title'        => 'Solicitudes',
            'solicitudes'  => $solicitudes,
            'estadoActivo' => $estado,
            'conteo'       => $conteo,
            'total'        => array_sum($conteo),
        ]);
    }

    public function show(int $id): void
    {
        $solicitud = Solicitud::find($id) ?? Response::abort(404);

        $this->renderDetalle($solicitud, null, 200);
    }

    public function updateEstado(int $id): void
    {
        $solicitud = Solicitud::find($id) ?? Response::abort(404);

        $raw = Request::string('estado');
        $estado = $raw === null ? null : Estado::fromInput($raw);
        if ($estado === null) {
            $this->renderDetalle($solicitud, 'Elegí un estado válido.', 422);

            return;
        }

        if (!Solicitud::updateEstado($id, $estado)) {
            Response::abort(404); // borrada entre el find y el update
        }
        Session::flash(self::FLASH_ESTADO, 'Estado actualizado: ' . $estado->label() . '.');
        Response::redirect('/admin/solicitudes/' . $id); // PRG (D-19)
    }

    /** ?estado ausente o vacío → todas; presente pero inválido o array → 400 (D-18). */
    private function filtroEstado(): ?Estado
    {
        if (!Request::has('estado', 'query')) {
            return null;
        }
        $raw = Request::string('estado', 'query');
        if ($raw === '') {
            return null;
        }

        return ($raw === null ? null : Estado::fromInput($raw)) ?? Response::abort(400);
    }

    /** @param array<string, mixed> $solicitud */
    private function renderDetalle(array $solicitud, ?string $error, int $status): void
    {
        $this->render('admin/solicitudes/show', [
            'title'     => 'Solicitud #' . $solicitud['id'],
            'solicitud' => self::presentar($solicitud),
            'estados'   => Estado::cases(),
            'flash'     => Session::pullFlash(self::FLASH_ESTADO),
            'error'     => $error,
        ], $status);
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data, int $status = 200): void
    {
        Response::html(View::render($view, $data + ['admin' => Auth::user()], 'layouts/admin'), $status);
    }

    /**
     * Agrega lo que la vista necesita ya resuelto (Estado y fechas legibles) para que no tenga lógica.
     *
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private static function presentar(array $fila): array
    {
        $fila['estado_enum'] = Estado::from($fila['estado']);
        $fila['creada'] = self::fecha($fila['created_at']);
        $fila['actualizada'] = self::fecha($fila['updated_at']);

        return $fila;
    }

    private static function fecha(string $datetime): string
    {
        return (new DateTimeImmutable($datetime))->format('d/m/Y H:i');
    }
}
