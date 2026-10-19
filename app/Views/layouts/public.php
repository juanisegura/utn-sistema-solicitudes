<?php
/**
 * Layout público. No depende de la sesión: también lo usan las páginas de error.
 *
 * @var string      $content HTML ya renderizado por la vista (cada valor fue escapado allí)
 * @var string|null $title
 */

// Hojas en orden de cascada: tokens → reset → global → layout → componentes → páginas (sin @import).
$hojas = [
    'base/tokens', 'base/reset', 'base/global',
    'layout/header', 'layout/page',
    'components/forms', 'components/buttons', 'components/alerts',
    'pages/solicitud', 'pages/errors',
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) ? $title . ' | Mesa de ayuda' : 'Mesa de ayuda') ?></title>
    <?php foreach ($hojas as $hoja): ?>
        <link rel="stylesheet" href="/assets/css/<?= e($hoja) ?>.css">
    <?php endforeach; ?>
</head>
<body>
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="site-header">
        <div class="wrap header-row">
            <a class="brand" href="/"><?php require __DIR__ . '/../partials/marca.php'; ?>Mesa de ayuda</a>
            <a class="header-link" href="/admin/login">Acceso del equipo</a>
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
