<?php
/**
 * Consulta pública (F-3). El código nunca se re-muestra: el campo vuelve vacío.
 *
 * @var string      $numero número ya normalizado a dígitos ('' si no hay)
 * @var string|null $error  "Número o código incorrectos." (422) o el aviso del límite (429)
 */
use App\Core\Csrf;
?>
<div class="split">
    <section class="split-intro">
        <h1 class="display">Consultá el estado de tu solicitud</h1>
        <p class="lead">Ingresá el número y el código de seguimiento que te mostramos al enviarla.</p>
        <p class="note">Si perdiste el código, el equipo de soporte te puede informar el estado por email.</p>
    </section>

    <section class="split-main split-narrow" aria-labelledby="titulo-seguimiento">
        <h2 id="titulo-seguimiento" class="section-title">Datos de la solicitud</h2>

        <?php if ($error !== null): ?>
            <div class="alert alert-error" role="alert"><p><?= e($error) ?></p></div>
        <?php endif; ?>

        <form method="post" action="/seguimiento" class="form">
            <?= Csrf::field() ?>
            <div class="field">
                <label for="numero">Número de solicitud</label>
                <input id="numero" name="numero" type="text" inputmode="numeric" maxlength="12"
                    value="<?= e($numero) ?>" autocomplete="off" required>
            </div>
            <div class="field">
                <label for="codigo">Código de seguimiento</label>
                <input id="codigo" name="codigo" type="text" maxlength="64" class="codigo-input"
                    autocomplete="off" autocapitalize="characters" spellcheck="false" required
                    aria-describedby="ayuda-codigo">
                <p class="hint" id="ayuda-codigo">Con o sin guiones; mayúsculas o minúsculas da igual.</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Consultar estado</button>
            </div>
        </form>
    </section>
</div>
