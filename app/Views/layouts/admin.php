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

// Hojas en orden de cascada: tokens → reset → global → layout → componentes → páginas (sin @import).
$hojas = [
    'base/tokens', 'base/reset', 'base/global',
    'layout/header', 'layout/page',
    'components/forms', 'components/buttons', 'components/alerts', 'components/badges', 'components/table',
    'pages/auth', 'pages/admin',
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(isset($title) ? $title . ' | Panel de soporte' : 'Panel de soporte') ?></title>
    <?php foreach ($hojas as $hoja): ?>
        <link rel="stylesheet" href="/assets/css/<?= e($hoja) ?>.css">
    <?php endforeach; ?>
</head>
<body class="is-admin">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="site-header">
        <div class="wrap header-row">
            <a class="brand" href="/admin"><?php require __DIR__ . '/../partials/marca.php'; ?>Panel de soporte</a>
            <?php if (!empty($admin)): ?>
                <div class="session-box">
                    <span class="session-user"><?= e($admin['nombre']) ?></span>
                    <form method="post" action="/admin/logout" class="inline-form">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-quiet">Cerrar sesión</button>
                    </form>
                </div>
            <?php else: ?>
                <a class="header-link" href="/">Formulario público</a>
            <?php endif; ?>
        </div>
    </header>
    <main id="contenido" class="wrap page">
        <?= $content /* ya escapado en la vista; escaparlo de nuevo mostraría el HTML como texto */ ?>
    </main>
    <footer class="site-footer">
        <div class="wrap">
            <p>Sistema de gestión de solicitudes, trabajo práctico integrador UTN.</p>
        </div>
    </footer>
</body>
</html>
