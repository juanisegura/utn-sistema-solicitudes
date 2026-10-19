<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Excepción de control (D-57): corta el flujo con una respuesta HTTP concreta (error con su vista,
 * o redirect con Location) en vez de terminar el proceso con exit. La captura Kernel::handle() y
 * la emite Response::send(). No es un error: no se loguea ni muestra la página 500.
 *
 * Valida en el constructor (único punto, no se puede saltear construyéndola a mano, A2-11):
 * con Location → status 3xx y path local; sin Location → status 4xx/5xx.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers p. ej. ['Allow' => 'POST'] o ['Location' => '/admin'] */
    public function __construct(public readonly int $status, public readonly array $headers = [])
    {
        $location = self::location($headers);
        if ($location !== null) {
            if ($status < 300 || $status > 399) {
                throw new InvalidArgumentException("Un redirect necesita status 3xx (llegó {$status}).");
            }
            self::assertPathLocal($location);
        } elseif ($status < 400 || $status > 599) {
            throw new InvalidArgumentException("Un corte de error necesita status 4xx o 5xx (llegó {$status}).");
        }
        parent::__construct('HTTP ' . $status, $status); // getCode() también devuelve el status
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function isRedirect(): bool
    {
        return self::location($this->headers) !== null;
    }

    public function getLocation(): ?string
    {
        return self::location($this->headers);
    }

    /** @param array<string, string> $headers  Location sin distinguir mayúsculas en el nombre */
    private static function location(array $headers): ?string
    {
        foreach ($headers as $nombre => $valor) {
            if (strcasecmp((string) $nombre, 'Location') === 0) {
                return (string) $valor;
            }
        }

        return null;
    }

    /** Solo paths locales: evita open redirects si algún día el destino sale de la entrada. */
    private static function assertPathLocal(string $path): void
    {
        // "/\evil.com" o con controles (CR/LF) los navegadores lo pueden interpretar como otro host.
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\\\\\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Redirect solo a paths locales.');
        }
    }
}
