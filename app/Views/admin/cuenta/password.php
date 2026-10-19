<?php
/**
 * Cambio de contraseña (F-5). Nunca se re-muestran contraseñas: los campos vuelven vacíos.
 *
 * @var array<string, string> $errores mensajes por campo (422)
 * @var string|null           $aviso   mensaje general (429)
 */
use App\Core\Csrf;

$campos = [
    'password_actual'       => ['Contraseña actual', 'current-password', null],
    'password_nueva'        => ['Nueva contraseña', 'new-password', 'Al menos 12 caracteres. Tiene que ser distinta de la actual.'],
    'password_confirmacion' => ['Repetir la nueva contraseña', 'new-password', null],
];
?>
<p class="back"><a href="/admin">Volver al listado</a></p>

<div class="split">
    <section class="split-intro">
        <h1 class="display">Cambiar contraseña</h1>
        <p class="lead">Al guardarla, se cierran las demás sesiones abiertas con tu cuenta. Esta sigue activa.</p>
    </section>

    <section class="split-main split-narrow" aria-labelledby="titulo-password">
        <h2 id="titulo-password" class="section-title">Nueva contraseña</h2>

        <?php if ($aviso !== null): ?>
            <div class="alert alert-error" role="alert"><p><?= e($aviso) ?></p></div>
        <?php elseif ($errores !== []): ?>
            <div class="alert alert-error" role="alert">
                <p><strong>Revisá los siguientes campos:</strong></p>
                <ul>
                    <?php foreach ($errores as $campo => $mensaje): ?>
                        <li><a href="#<?= e($campo) ?>"><?= e($mensaje) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/cuenta/password" class="form">
            <?= Csrf::field() ?>
            <?php foreach ($campos as $nombre => [$label, $autocomplete, $ayuda]): ?>
                <?php
                $error = $errores[$nombre] ?? null;
                $describe = array_filter([$error !== null ? 'error-' . $nombre : null, $ayuda !== null ? 'ayuda-' . $nombre : null]);
                ?>
                <div class="field<?= $error !== null ? ' has-error' : '' ?>">
                    <label for="<?= e($nombre) ?>"><?= e($label) ?></label>
                    <input id="<?= e($nombre) ?>" name="<?= e($nombre) ?>" type="password"
                        autocomplete="<?= e($autocomplete) ?>" required
                        <?php if ($describe !== []): ?>aria-describedby="<?= e(implode(' ', $describe)) ?>"<?php endif; ?>
                        <?php if ($error !== null): ?>aria-invalid="true"<?php endif; ?>>
                    <?php if ($ayuda !== null): ?>
                        <p class="hint" id="ayuda-<?= e($nombre) ?>"><?= e($ayuda) ?></p>
                    <?php endif; ?>
                    <?php if ($error !== null): ?>
                        <p class="field-error" id="error-<?= e($nombre) ?>"><?= e($error) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Guardar contraseña</button>
            </div>
        </form>
    </section>
</div>
