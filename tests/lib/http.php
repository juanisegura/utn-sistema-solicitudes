<?php

declare(strict_types=1);

/*
 * Cliente HTTP mínimo (ext-curl) para los tests de integración contra BASE_URL
 * (default http://localhost:8080). Cada Cliente tiene su propio cookie jar, como un navegador.
 */

function baseUrl(): string
{
    return rtrim(getenv('BASE_URL') ?: 'http://localhost:8080', '/');
}

/** SKIP si el servidor no responde o falta ext-curl. */
function requiereServidor(): void
{
    if (!function_exists('curl_init')) {
        skip('falta ext-curl');
    }
    $c = new Cliente();
    if ($c->get('/')['code'] === 0) {
        skip('el servidor no responde en ' . baseUrl() . ' (BASE_URL)');
    }
}

final class Cliente
{
    private string $jar;

    public function __construct()
    {
        $this->jar = dirTemporal('utn-jar') . '/cookies.txt';
        touch($this->jar);
    }

    /** @return array{code: int, body: string, location: string} */
    public function get(string $path): array
    {
        return self::ejecutar($this->handle('GET', $path));
    }

    /** @param array<string, string> $campos */
    public function post(string $path, array $campos): array
    {
        return self::ejecutar($this->handle('POST', $path, $campos));
    }

    /** Token CSRF del primer formulario de la página. */
    public function token(string $path = '/admin/login'): string
    {
        $r = $this->get($path);
        if (preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $r['body'], $m) !== 1) {
            fail("sin token CSRF en {$path} (HTTP {$r['code']})");
        }

        return $m[1];
    }

    public function login(string $email, string $password): array
    {
        return $this->post('/admin/login', ['csrf_token' => $this->token(), 'email' => $email, 'password' => $password]);
    }

    /** @param array<string, string> $campos */
    public function handle(string $metodo, string $path, array $campos = []): CurlHandle
    {
        $h = curl_init(baseUrl() . $path);
        curl_setopt_array($h, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $metodo,
        ]);
        if ($metodo === 'POST') {
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($campos));
        }

        return $h;
    }

    /** @return array{code: int, body: string, location: string} */
    public static function ejecutar(CurlHandle $h): array
    {
        $body = curl_exec($h);

        return self::resultado($h, is_string($body) ? $body : '');
    }

    /** @return array{code: int, body: string, location: string} */
    public static function resultado(CurlHandle $h, string $body): array
    {
        return [
            'code'     => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE),
            'body'     => $body,
            'location' => (string) (curl_getinfo($h, CURLINFO_REDIRECT_URL) ?: ''),
        ];
    }
}

/**
 * Dispara todos los handles a la vez (curl_multi) y devuelve sus resultados en el mismo orden.
 * La concurrencia real depende del servidor: `php -S` atiende de a una request salvo que se lo
 * levante con PHP_CLI_SERVER_WORKERS=N (así lo hace tests/run-all.sh).
 *
 * @param list<CurlHandle> $handles
 * @return list<array{code: int, body: string, location: string}>
 */
function enParalelo(array $handles): array
{
    $multi = curl_multi_init();
    foreach ($handles as $h) {
        curl_multi_add_handle($multi, $h);
    }
    do {
        $estado = curl_multi_exec($multi, $activos);
        if ($activos > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($activos > 0 && $estado === CURLM_OK);
    $out = [];
    foreach ($handles as $h) {
        $out[] = Cliente::resultado($h, (string) curl_multi_getcontent($h));
        curl_multi_remove_handle($multi, $h);
    }
    curl_multi_close($multi);

    return $out;
}

/**
 * PDO de la app. Desde R-3 (D-58) Database solo se configura por app/bootstrap-cli.php, que
 * tests/integration/run.php carga antes que el runner. Mientras ese archivo no exista se usa la
 * lectura perezosa vieja de Database; si ninguna de las dos está disponible → SKIP explicando
 * por qué (nunca un fatal). El test nunca lee ni imprime la config.
 */
function requiereDb(): PDO
{
    $cli = $GLOBALS['__bootstrap_cli'] ?? null;
    if (is_string($cli) && $cli !== 'ok') {
        skip($cli);
    }
    try {
        return App\Core\Database::pdo();
    } catch (LogicException $e) {
        skip('Database sin configurar y sin app/bootstrap-cli.php (R-3): ' . $e->getMessage());
    } catch (Throwable $e) {
        skip('sin base de datos de prueba: ' . $e->getMessage());
    }
}

function tablaExiste(string $tabla): bool
{
    $st = requiereDb()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute([$tabla]);

    return (int) $st->fetchColumn() > 0;
}

function valor(string $sql, array $params = []): mixed
{
    $st = requiereDb()->prepare($sql);
    $st->execute($params);

    return $st->fetchColumn();
}
