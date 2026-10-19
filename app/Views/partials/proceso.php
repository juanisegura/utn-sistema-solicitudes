<?php
/**
 * Recorrido de una solicitud por sus estados. Es una secuencia real, por eso va numerada.
 *
 * @var string|null $actual valor del estado a destacar ('pendiente' tras el alta) o null
 */
$pasos = [
    'pendiente'  => ['Pendiente', 'La solicitud queda registrada con un número propio.'],
    'en_proceso' => ['En proceso', 'Alguien del equipo de soporte la toma y trabaja en ella.'],
    'resuelta'   => ['Resuelta', 'El equipo la cierra cuando el problema está solucionado.'],
];
$actual ??= null;
?>
<ol class="steps">
    <?php foreach ($pasos as $valor => [$titulo, $texto]): ?>
        <li class="step step-<?= e($valor) ?>"<?= $actual === $valor ? ' aria-current="step"' : '' ?>>
            <span class="step-name"><?= e($titulo) ?><?php if ($actual === $valor): ?> <span class="step-now">(ahora)</span><?php endif; ?></span>
            <span class="step-text"><?= e($texto) ?></span>
        </li>
    <?php endforeach; ?>
</ol>
