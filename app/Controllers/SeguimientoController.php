<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CodigoSeguimiento;
use App\Core\FormThrottle;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\View;
use App\Models\Estado;
use App\Models\Solicitud;
use DateTimeImmutable;

/**
 * Seguimiento público (F-3, ADR-0013): número + código → estado e historial, sin datos personales.
 *
 * El resultado se muestra en la respuesta del POST: el código nunca va en una URL ni en la sesión.
 * Id inexistente, sin código (seed) o código incorrecto dan el mismo 422 y hacen el mismo trabajo
 * (siempre se hashea y se compara con hash_equals), para no revelar cuál fue. Los fallidos cuentan
 * en el límite por IP (FormThrottle 'seguimiento'); el acierto libera su reserva.
 */
final class SeguimientoController
{
    private const ERROR = 'Número o código incorrectos.';
    /** Más largo que cualquier forma razonable del código (26 + guiones + espacios); lo demás no coincide. */
    private const CODIGO_MAX = 64;
    /** Contra esto se compara cuando no hay hash guardado, para que ese caso cueste lo mismo. */
    private const SIN_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function create(): void
    {
        $this->renderForm('', null, 200);
    }

    public function show(): void
    {
        $numero = self::numero(Request::string('numero'));

        // Reservar antes de verificar (D-46): ni el código correcto se verifica estando bloqueado.
        [$envio, $espera] = FormThrottle::reservar('seguimiento', Request::ip());
        if ($envio === null) {
            SecurityLog::evento('seguimiento_bloqueado', 'retry=' . $espera);
            $aviso = 'Demasiados intentos. Probá de nuevo en ' . FormThrottle::minutos($espera) . '.';
            $this->renderForm($numero === null ? '' : (string) $numero, $aviso, 429, ['Retry-After' => (string) $espera]);

            return;
        }

        $codigo = Request::string('codigo');
        $hash = CodigoSeguimiento::hash($codigo !== null && strlen($codigo) <= self::CODIGO_MAX ? $codigo : '');
        $fila = $numero === null ? null : Solicitud::paraSeguimiento($numero);
        $guardado = $fila['seguimiento_hash'] ?? null;
        $coincide = hash_equals($guardado ?? self::SIN_HASH, $hash);

        if ($fila === null || $guardado === null || !$coincide) {
            SecurityLog::evento('seguimiento_fallido', $numero === null ? 'numero=invalido' : 'numero=' . $numero);
            $this->renderForm($numero === null ? '' : (string) $numero, self::ERROR, 422);

            return;
        }

        FormThrottle::liberar($envio);
        SecurityLog::evento('seguimiento_ok', 'numero=' . $numero);
        Response::html(View::render('seguimiento/resultado', [
            'title'     => 'Estado de la solicitud #' . $numero,
            'solicitud' => [
                'id'          => (int) $fila['id'],
                'asunto'      => $fila['asunto'],
                'estado'      => Estado::from($fila['estado']),
                'creada'      => self::fecha($fila['created_at']),
                'actualizada' => self::fecha($fila['updated_at']),
            ],
            'eventos'   => array_map(static fn (array $e): array => [
                'fecha'    => self::fecha($e['created_at']),
                'anterior' => Estado::from($e['estado_anterior']),
                'nuevo'    => Estado::from($e['estado_nuevo']),
            ], Solicitud::historialPublico($numero)),
        ]));
    }

    /** Entero positivo de hasta 10 dígitos que entra en INT UNSIGNED; admite "#12" y espacios alrededor. */
    private static function numero(?string $raw): ?int
    {
        if ($raw === null || preg_match('/^\s*#?\s*([1-9][0-9]{0,9})\s*$/D', $raw, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];

        return $n <= 4294967295 ? $n : null;
    }

    /**
     * El número se re-muestra (ya normalizado a dígitos); el código nunca.
     *
     * @param array<string, string> $headers
     */
    private function renderForm(string $numero, ?string $error, int $status, array $headers = []): void
    {
        Response::html(View::render('seguimiento/form', [
            'title'  => 'Consultar una solicitud',
            'numero' => $numero,
            'error'  => $error,
        ]), $status, $headers);
    }

    private static function fecha(string $datetime): string
    {
        return (new DateTimeImmutable($datetime))->format('d/m/Y H:i');
    }
}
