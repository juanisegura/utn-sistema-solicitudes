<?php
/**
 * @var array<string, string> $old    valores previos (re-render tras 422)
 * @var array<string, string> $errors mensajes por campo
 * @var array<string, string> $labels
 * @var string|null           $aviso  mensaje general (429 por exceso de envíos)
 */
use App\Core\Csrf;

$campos = [
    'nombre'      => ['type' => 'text',  'max' => 100,  'autocomplete' => 'name', 'ancho' => 'half'],
    'email'       => ['type' => 'email', 'max' => 254,  'autocomplete' => 'email', 'ancho' => 'half'],
    'asunto'      => ['type' => 'text',  'max' => 150,  'autocomplete' => 'off', 'ancho' => 'full'],
    'descripcion' => ['type' => 'textarea', 'max' => 5000, 'autocomplete' => 'off', 'ancho' => 'full'],
];
$actual = null;
?>
<div class="split">
    <section class="split-intro">
        <h1 class="display">Pedí ayuda al equipo de soporte</h1>
        <p class="lead">Contanos qué pasa y lo resolvemos. Cada solicitud recorre tres estados:</p>
        <?php require __DIR__ . '/../partials/proceso.php'; ?>
        <p class="note">Si hace falta más información, el equipo te escribe al email que indiques.</p>
    </section>

    <section class="split-main" aria-labelledby="titulo-form">
        <h2 id="titulo-form" class="section-title">Nueva solicitud</h2>
        <p class="hint">Todos los campos son obligatorios.</p>

        <?php if (($aviso ?? null) !== null): ?>
            <div class="alert alert-error" role="alert">
                <p><?= e($aviso) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error" role="alert">
                <p><strong>Revisá los siguientes campos:</strong></p>
                <ul>
                    <?php foreach ($errors as $campo => $mensaje): ?>
                        <li><a href="#campo-<?= e($campo) ?>"><?= e($mensaje) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/solicitudes" class="form form-grid">
            <?= Csrf::field() ?>

            <?php foreach ($campos as $campo => $def): ?>
                <?php $error = $errors[$campo] ?? null; ?>
                <div class="field field-<?= e($def['ancho']) ?><?= $error !== null ? ' has-error' : '' ?>">
                    <label for="campo-<?= e($campo) ?>"><?= e($labels[$campo]) ?></label>
                    <?php if ($def['type'] === 'textarea'): ?>
                        <textarea id="campo-<?= e($campo) ?>" name="<?= e($campo) ?>" rows="7"
                            maxlength="<?= e($def['max']) ?>" required
                            <?php if ($error !== null): ?>aria-invalid="true" aria-describedby="error-<?= e($campo) ?>"<?php endif; ?>
                        ><?= e($old[$campo] ?? '') ?></textarea>
                    <?php else: ?>
                        <input id="campo-<?= e($campo) ?>" name="<?= e($campo) ?>" type="<?= e($def['type']) ?>"
                            value="<?= e($old[$campo] ?? '') ?>" maxlength="<?= e($def['max']) ?>"
                            autocomplete="<?= e($def['autocomplete']) ?>" required
                            <?php if ($error !== null): ?>aria-invalid="true" aria-describedby="error-<?= e($campo) ?>"<?php endif; ?>>
                    <?php endif; ?>
                    <?php if ($error !== null): ?>
                        <p class="field-error" id="error-<?= e($campo) ?>"><?= e($error) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php /* Honeypot (F-1): fuera de la vista y del orden de tabulación; una persona no lo completa. */ ?>
            <div class="hp" aria-hidden="true">
                <label for="campo-sitio-web">No completar este campo</label>
                <input id="campo-sitio-web" name="sitio_web" type="text" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="form-actions field-full">
                <button type="submit" class="btn btn-primary">Enviar solicitud</button>
            </div>
        </form>
    </section>
</div>
