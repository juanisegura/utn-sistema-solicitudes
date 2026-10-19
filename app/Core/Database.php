<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Conexión PDO única (lazy) y helpers que solo aceptan SQL + parámetros: todo pasa por
 * prepare() con preparación real en el servidor (EMULATE_PREPARES=false, ADR-0003).
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /** Sección 'db' de la config. La llaman bootstrap.php y bootstrap-cli.php (D-58); nadie más lee config.php. */
    public static function configure(array $dbConfig): void
    {
        self::$config = $dbConfig;
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$config === null) {
            throw new LogicException(
                'Database no configurada: cargá app/bootstrap.php (web) o app/bootstrap-cli.php (CLI y tests) antes de usar la DB.'
            );
        }

        return self::$pdo ??= self::connect(self::$config);
    }

    /** @return array<string, mixed>|null */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** INSERT/UPDATE/DELETE/DDL. Devuelve filas coincidentes (ver ATTR_FOUND_ROWS en connect). */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    private static function run(string $sql, array $params): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            // Parámetros posicionales empiezan en 1; los nombrados se usan tal cual.
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, self::paramType($value));
        }
        $stmt->execute();

        return $stmt;
    }

    private static function paramType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    private static function connect(array $db): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) $db['port'],
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );

        try {
            $pdo = new PDO($dsn, (string) $db['user'], (string) $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // rowCount() cuenta filas encontradas, no solo modificadas: un UPDATE al
                // mismo estado sigue indicando que la solicitud existe.
                self::foundRowsAttr() => true,
            ]);
            // Modo estricto explícito: sin él, MySQL guarda '' ante un ENUM inválido o trunca en silencio.
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

            return $pdo;
        } catch (PDOException $e) {
            // El mensaje original puede incluir host/usuario: queda en $previous para el log.
            throw new RuntimeException('No se pudo conectar a la base de datos.', 0, $e);
        }
    }

    private static function foundRowsAttr(): int
    {
        // PHP 8.4 agregó Pdo\Mysql y 8.5 depreca PDO::MYSQL_*; se soportan ambos (PHP >= 8.2).
        return defined('Pdo\Mysql::ATTR_FOUND_ROWS')
            ? constant('Pdo\Mysql::ATTR_FOUND_ROWS')
            : constant('PDO::MYSQL_ATTR_FOUND_ROWS');
    }
}
