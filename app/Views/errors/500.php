<?php
/**
 * Error interno. $detalle solo llega con app.debug = true (ver app_render_500 en bootstrap).
 *
 * @var string|null $detalle
 */
?>
<div class="split split-error">
    <p class="error-code" aria-hidden="true">500</p>
    <section class="split-main">
        <h1 class="display">Ocurrió un error interno</h1>
        <p class="lead">No pudimos completar la operación. Intentá de nuevo en unos minutos.</p>
        <?php if (isset($detalle) && $detalle !== null): ?>
            <pre class="debug"><?= e($detalle) ?></pre>
        <?php endif; ?>
        <p><a class="btn" href="/">Ir al inicio</a></p>
    </section>
</div>
