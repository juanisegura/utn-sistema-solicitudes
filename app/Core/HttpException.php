<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Excepción de control (D-57): corta el flujo con una respuesta HTTP concreta (error con su vista,
 * o redirect con Location) en vez de terminar el proceso con exit. La captura Kernel::handle() y
 * la emite Response::send(). No es un error: no se loguea ni muestra la página 500.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers p. ej. ['Allow' => 'POST'] o ['Location' => '/admin'] */
    public function __construct(public readonly int $status, public readonly array $headers = [])
    {
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
        return isset($this->headers['Location']);
    }
}
