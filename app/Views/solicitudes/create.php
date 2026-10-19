<?php
/**
 * @var array<string, string> $old    valores previos (re-render tras 422)
 * @var array<string, string> $errors mensajes por campo
 * @var array<string, string> $labels
 */
use App\Core\Csrf;

$campos = [
    'nombre'      => ['type' => 'text',  'max' => 100,  'autocomplete' => 'name'],
    'email'       => ['type' => 'email', 'max' => 254,  'autocomplete' => 'email'],
    'asunto'      => ['type' => 'text',  'max' => 150,  'autocomplete' => 'off'],
    'descripcion' => ['type' => 'textarea', 'max' => 5000, 'autocomplete' => 'off'],
];
?>
<section class="card">
    <h1>Nueva solicitud de soporte</h1>
    <p class="lead">Contanos qué necesitás. Todos los campos son obligatorios.</p>

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

    <form method="post" action="/solicitudes" class="form">
        <?= Csrf::field() ?>

        <?php foreach ($campos as $campo => $def): ?>
            <?php $error = $errors[$campo] ?? null; ?>
            <div class="field<?= $error !== null ? ' has-error' : '' ?>">
                <label for="campo-<?= e($campo) ?>"><?= e($labels[$campo]) ?></label>
                <?php if ($def['type'] === 'textarea'): ?>
                    <textarea id="campo-<?= e($campo) ?>" name="<?= e($campo) ?>" rows="6"
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

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Enviar solicitud</button>
        </div>
    </form>
</section>
