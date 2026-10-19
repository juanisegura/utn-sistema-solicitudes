<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use Throwable;

final class Solicitud
{
    private const COLUMNS = 'id, nombre, email, asunto, descripcion, estado, created_at, updated_at';
    private const ORDEN = 'ORDER BY created_at DESC, id DESC';

    /** Columnas en las que busca el texto del listado (M-3); son las del índice FULLTEXT ft_busqueda. */
    private const BUSCABLES = ['asunto', 'nombre', 'email', 'descripcion'];

    /**
     * Parámetros del índice FULLTEXT de InnoDB, verificados en MariaDB 11.8 y con los mismos
     * defaults en MySQL 8 (innodb_ft_min_token_size = 3, innodb_ft_max_token_size = 84, lista de
     * stopwords INNODB_FT_DEFAULT_STOPWORD). Si el servidor los cambia, hay que cambiarlos acá.
     */
    private const FT_MIN = 3;
    private const FT_MAX = 84;
    private const FT_STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how',
        'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what',
        'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];

    /** Recibe datos ya validados; devuelve el id generado. */
    public static function create(string $nombre, string $email, string $asunto, string $descripcion): int
    {
        Database::execute(
            'INSERT INTO solicitudes (nombre, email, asunto, descripcion) VALUES (?, ?, ?, ?)',
            [$nombre, $email, $asunto, $descripcion]
        );

        return Database::lastInsertId();
    }

    /** @return list<array<string, mixed>> más recientes primero; filtradas si se pasa estado. */
    public static function all(?Estado $estado = null): array
    {
        return self::buscar($estado, null, PHP_INT_MAX, 0);
    }

    /**
     * Página del listado, más recientes primero (D-20). $q ya validado (o null = sin búsqueda).
     *
     * @return list<array<string, mixed>>
     */
    public static function buscar(?Estado $estado, ?string $q, int $limite, int $desde): array
    {
        [$where, $params] = self::filtros($estado, $q);
        $params[] = $limite;   // ints: Database los bindea como PARAM_INT (LIMIT no admite strings)
        $params[] = $desde;

        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . " FROM solicitudes {$where} " . self::ORDEN . ' LIMIT ? OFFSET ?',
            $params
        );
    }

    public static function contar(?Estado $estado, ?string $q): int
    {
        [$where, $params] = self::filtros($estado, $q);

        return (int) Database::fetchOne("SELECT COUNT(*) AS n FROM solicitudes {$where}", $params)['n'];
    }

    /**
     * Cantidad por estado en una sola consulta (M-4). Respeta la búsqueda pero no el filtro de
     * estado (los contadores sirven para elegir el filtro). Estados sin filas → 0.
     *
     * @return array<string, int> valor del estado => cantidad, en el orden de Estado::cases()
     */
    public static function countByEstado(?string $q = null): array
    {
        [$where, $params] = self::filtros(null, $q);
        $filas = Database::fetchAll("SELECT estado, COUNT(*) AS n FROM solicitudes {$where} GROUP BY estado", $params);
        $conteo = array_fill_keys(array_map(static fn (Estado $e): string => $e->value, Estado::cases()), 0);
        foreach ($filas as $fila) {
            $conteo[$fila['estado']] = (int) $fila['n'];
        }

        return $conteo;
    }

    /**
     * Expresión AGAINST (BOOLEAN MODE) con las palabras indexables de $q, o null si no hay ninguna
     * (ADR-0011). Función pura. Cada palabra va como "+palabra*" (obligatoria, por prefijo) y solo
     * se toman secuencias de letras, marcas y dígitos, así los operadores booleanos del usuario
     * (+ - < > ( ) ~ * " @) nunca llegan a la expresión.
     * Si $q tiene "_" o un apóstrofe (' o ’) devuelve null y la búsqueda queda solo con el LIKE
     * (D-59): InnoDB los puede tratar como parte de la palabra ("foo_bar" es un solo token), así que
     * partir ahí daría palabras que el índice no tiene. Del resto se descartan las palabras que el
     * índice no puede contener tal cual:
     * - menos de 3 o más de 84 caracteres;
     * - prefijos de una stopword ("abo" no está indexado dentro de "about", aunque LIKE lo encuentre).
     * Así el prefiltro nunca excluye una fila que el LIKE encontraría desde el inicio de una palabra.
     */
    public static function fulltextQuery(string $q): ?string
    {
        if (preg_match('/[_\'\x{2019}]/u', $q) === 1) {
            return null;
        }
        if (preg_match_all('/[\p{L}\p{M}\p{N}_]+/u', $q, $m) === false) {
            return null; // UTF-8 inválido: el Validator ya lo rechaza antes de llegar acá
        }
        $terminos = [];
        foreach ($m[0] as $palabra) {
            $largo = mb_strlen($palabra, 'UTF-8');
            if ($largo < self::FT_MIN || $largo > self::FT_MAX || self::esPrefijoDeStopword($palabra)) {
                continue;
            }
            $terminos['+' . $palabra . '*'] = true;
        }

        return $terminos === [] ? null : implode(' ', array_keys($terminos));
    }

    private static function esPrefijoDeStopword(string $palabra): bool
    {
        $palabra = mb_strtolower($palabra, 'UTF-8');
        foreach (self::FT_STOPWORDS as $stopword) {
            if (str_starts_with($stopword, $palabra)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Escapa los comodines de LIKE para que el texto se busque literal: "100%_ok" no debe
     * coincidir con cualquier cosa. Se usa con `ESCAPE ?` ("\" bindeado) en la consulta. Función pura.
     */
    public static function escapeLike(string $texto): string
    {
        return strtr($texto, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT ' . self::COLUMNS . ' FROM solicitudes WHERE id = ?', [$id]);
    }

    /**
     * Cambia el estado y registra el evento en una transacción (M-2, ADR-0010). FOR UPDATE bloquea
     * la fila: dos admins cambiando la misma solicitud a la vez quedan en serie y cada evento
     * registra el estado anterior real. Mismo estado → sin UPDATE ni evento.
     *
     * @return bool true si la solicitud existe (haya cambiado o no), false si el id no existe
     */
    public static function updateEstado(int $id, Estado $estado, int $adminId): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $fila = Database::fetchOne('SELECT estado FROM solicitudes WHERE id = ? FOR UPDATE', [$id]);
            if ($fila === null) {
                $pdo->rollBack();

                return false;
            }
            $anterior = Estado::from($fila['estado']);
            if ($anterior !== $estado) {
                Database::execute('UPDATE solicitudes SET estado = ? WHERE id = ?', [$estado->value, $id]);
                SolicitudEvento::registrar($id, $adminId, $anterior, $estado);
            }
            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * WHERE común a listado, conteo y contadores. Los nombres de columna son constantes; los
     * valores van siempre como parámetros.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function filtros(?Estado $estado, ?string $q): array
    {
        $condiciones = [];
        $params = [];
        if ($estado !== null) {
            $condiciones[] = 'estado = ?';
            $params[] = $estado->value;
        }
        if ($q !== null && $q !== '') {
            // Híbrido (ADR-0011): si hay palabras indexables, el índice FULLTEXT achica el conjunto y
            // el LIKE de abajo confirma la subcadena exacta (contrato de M-3: "%" y "_" literales).
            $against = self::fulltextQuery($q);
            if ($against !== null) {
                $condiciones[] = 'MATCH(' . implode(', ', self::BUSCABLES) . ') AGAINST(? IN BOOLEAN MODE)';
                $params[] = $against;
            }
            $patron = '%' . self::escapeLike($q) . '%';
            $partes = [];
            foreach (self::BUSCABLES as $columna) {
                // ESCAPE bindeado (D-53): un literal '\\' cambia de significado si el sql_mode de la
                // sesión incluye NO_BACKSLASH_ESCAPES; como parámetro siempre es un solo "\".
                $partes[] = "{$columna} LIKE ? ESCAPE ?";
                $params[] = $patron;
                $params[] = '\\';
            }
            $condiciones[] = '(' . implode(' OR ', $partes) . ')';
        }

        return [$condiciones === [] ? '' : 'WHERE ' . implode(' AND ', $condiciones), $params];
    }
}
