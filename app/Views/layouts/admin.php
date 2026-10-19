<?php
/**
 * Layout del panel. Con $admin muestra quién está logueado y el botón de salir (POST + CSRF);
 * sin $admin (pantalla de login) solo la marca.
 *
 * @var string                    $content HTML ya renderizado por la vista (escapado allí)
 * @var string|null               $title
 * @var array<string, mixed>|null $admin
 */
use App\Core\Csrf;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(isset($title) ? $title . ' · Panel' : 'Panel') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="admin">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="site-header">
        <div class="container container-wide header-row">
            <a class="brand" href="/admin">Mesa de ayuda · Panel</a>
            <?php if (!empty($admin)): ?>
                <div class="session-box">
                    <span class="session-user">Hola, <?= e($admin['nombre']) ?></span>
                    <form method="post" action="/admin/logout" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-small">Cerrar sesión</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </header>
    <main id="contenido" class="container container-wide">
        <?= $content /* ya escapado en la vista; escaparlo de nuevo mostraría el HTML como texto */ ?>
    </main>
    <footer class="site-footer">
        <div class="container container-wide">
            <p>Sistema de gestión de solicitudes · TP integrador UTN</p>
        </div>
    </footer>
</body>
</html>
