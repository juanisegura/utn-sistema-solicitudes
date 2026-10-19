<?php
/**
 * Error interno. $detalle solo llega con app.debug = true (ver app_render_500 en bootstrap).
 *
 * @var string|null $detalle
 */
?>
<section class="card card-error">
    <p class="error-code">500</p>
    <h1>Ocurrió un error interno</h1>
    <p>No pudimos completar la operación. Intentá de nuevo en unos minutos.</p>
    <?php if (isset($detalle) && $detalle !== null): ?>
        <pre class="debug"><?= e($detalle) ?></pre>
    <?php endif; ?>
    <p><a class="btn" href="/">Volver al inicio</a></p>
</section>
