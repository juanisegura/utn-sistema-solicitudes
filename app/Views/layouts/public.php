<?php
/**
 * Layout público. No depende de la sesión: también lo usan las páginas de error.
 *
 * @var string      $content HTML ya renderizado por la vista (cada valor fue escapado allí)
 * @var string|null $title
 */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) ? $title . ' · Mesa de ayuda' : 'Mesa de ayuda') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="site-header">
        <div class="container">
            <a class="brand" href="/">Mesa de ayuda</a>
        </div>
    </header>
    <main id="contenido" class="container">
        <?= $content /* ya escapado en la vista; escaparlo de nuevo mostraría el HTML como texto */ ?>
    </main>
    <footer class="site-footer">
        <div class="container">
            <p>Sistema de gestión de solicitudes · TP integrador UTN</p>
        </div>
    </footer>
</body>
</html>
