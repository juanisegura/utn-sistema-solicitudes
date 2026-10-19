<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\Estado;
use App\Models\Solicitud;
use App\Models\SolicitudEvento;
use DateTimeImmutable;

/** Panel: listado con filtro, búsqueda y paginación, detalle con historial y cambio de estado. Todas las rutas exigen admin. */
final class SolicitudAdminController
{
    private const FLASH_ESTADO = 'estado_actualizado';
    private const Q_MAX = 100;

    public function index(): void
    {
        $estado = $this->filtroEstado();
        $q = $this->filtroBusqueda();
        $pagina = $this->filtroPagina();
        $porPagina = self::porPagina();

        $total = Solicitud::contar($estado, $q);
        $paginas = max(1, (int) ceil($total / $porPagina));
        if ($total > 0 && $pagina > $paginas) {
            Response::abort(404); // M-3: página inexistente para esta búsqueda/filtro
        }
        $solicitudes = array_map(self::presentar(...), Solicitud::buscar($estado, $q, $porPagina, ($pagina - 1) * $porPagina));
        $conteo = Solicitud::countByEstado($q); // M-4: un GROUP BY, respeta q pero no el estado

        $this->render('admin/solicitudes/index', [
            'title'        => 'Solicitudes',
            'solicitudes'  => $solicitudes,
            'estadoActivo' => $estado,
            'q'            => $q ?? '',
            'conteo'       => $conteo,
            'total'        => array_sum($conteo),
            'resultados'   => $total,
            'filtros'      => self::linksFiltro($estado, $q),
            'paginacion'   => self::paginacion($estado, $q, $pagina, $paginas),
            'limpiarUrl'   => self::url($estado, null, 1),
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

        // Transacción con historial en el modelo (M-2, ADR-0010). Auth::user ya está verificado por requireAdmin.
        if (!Solicitud::updateEstado($id, $estado, (int) Auth::user()['id'])) {
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

    /** ?q ausente o vacío → sin búsqueda; array, >100 caracteres, UTF-8 inválido o controles → 400 (M-3). */
    private function filtroBusqueda(): ?string
    {
        if (!Request::has('q', 'query')) {
            return null;
        }
        $raw = Request::string('q', 'query') ?? Response::abort(400);
        // Mismas reglas que el resto de la entrada: encoding, recorte Unicode, controles, largo.
        [$datos, $errores] = Validator::validate(['q' => $raw], ['q' => ['max:' . self::Q_MAX]]);
        if ($errores !== []) {
            Response::abort(400);
        }

        return $datos['q'] === '' ? null : $datos['q'];
    }

    /** ?pagina ausente → 1; debe ser un entero ≥ 1 escrito solo con dígitos ("2abc", "0", "01" → 400). */
    private function filtroPagina(): int
    {
        if (!Request::has('pagina', 'query')) {
            return 1;
        }
        $raw = Request::string('pagina', 'query');
        if ($raw === null || preg_match('/^[1-9][0-9]{0,8}$/D', $raw) !== 1) {
            Response::abort(400);
        }

        return (int) $raw;
    }

    /** URL del listado con los parámetros no vacíos (los links conservan búsqueda y filtro). */
    private static function url(?Estado $estado, ?string $q, int $pagina): string
    {
        $params = array_filter(
            ['estado' => $estado?->value, 'q' => $q, 'pagina' => $pagina > 1 ? $pagina : null],
            static fn ($v): bool => $v !== null && $v !== ''
        );

        return '/admin' . ($params === [] ? '' : '?' . http_build_query($params));
    }

    /** @return list<array{label: string, url: string, activo: bool}> "Todas" + un link por estado; vuelven a la página 1 */
    private static function linksFiltro(?Estado $activo, ?string $q): array
    {
        $links = [['label' => 'Todas', 'url' => self::url(null, $q, 1), 'activo' => $activo === null]];
        foreach (Estado::cases() as $estado) {
            $links[] = ['label' => $estado->label(), 'url' => self::url($estado, $q, 1), 'activo' => $activo === $estado];
        }

        return $links;
    }

    /** @return array{pagina: int, paginas: int, anterior: ?string, siguiente: ?string} */
    private static function paginacion(?Estado $estado, ?string $q, int $pagina, int $paginas): array
    {
        return [
            'pagina'    => $pagina,
            'paginas'   => $paginas,
            'anterior'  => $pagina > 1 ? self::url($estado, $q, $pagina - 1) : null,
            'siguiente' => $pagina < $paginas ? self::url($estado, $q, $pagina + 1) : null,
        ];
    }

    /**
     * app.por_pagina. No hay un servicio de config inyectable (fuera del alcance de M-3): se lee
     * una vez por request de la config ya validada por bootstrap.
     */
    private static function porPagina(): int
    {
        static $n = null;
        if ($n === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $n = max(1, (int) ($config['app']['por_pagina'] ?? 20));
        }

        return $n;
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
            'eventos'   => array_map(self::presentarEvento(...), SolicitudEvento::porSolicitud((int) $solicitud['id'])),
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

    /**
     * @param array<string, mixed> $evento
     * @return array<string, mixed>
     */
    private static function presentarEvento(array $evento): array
    {
        return [
            'fecha'    => self::fecha($evento['created_at']),
            'admin'    => $evento['admin_nombre'] ?? '—',
            'anterior' => Estado::from($evento['estado_anterior']),
            'nuevo'    => Estado::from($evento['estado_nuevo']),
        ];
    }

    private static function fecha(string $datetime): string
    {
        return (new DateTimeImmutable($datetime))->format('d/m/Y H:i');
    }
}
