<?php
/**
 * Resultado de la consulta pública (F-3). Sin nombre, email ni descripción, y sin el admin de
 * cada cambio (ADR-0013).
 *
 * @var array{id: int, asunto: string, estado: \App\Models\Estado, creada: string, actualizada: string} $solicitud
 * @var list<array{fecha: string, anterior: \App\Models\Estado, nuevo: \App\Models\Estado}> $eventos más reciente primero
 */
?>
<div class="split">
    <section class="split-intro">
        <p class="ticket-label">Solicitud #<?= e($solicitud['id']) ?></p>
        <h1 class="display"><?= e($solicitud['asunto']) ?></h1>
        <dl class="meta">
            <div><dt>Estado</dt><dd><span class="badge badge-<?= e($solicitud['estado']->value) ?>"><?= e($solicitud['estado']->label()) ?></span></dd></div>
            <div><dt>Recibida</dt><dd class="num"><?= e($solicitud['creada']) ?></dd></div>
            <div><dt>Última actualización</dt><dd class="num"><?= e($solicitud['actualizada']) ?></dd></div>
        </dl>
        <p><a class="btn" href="/seguimiento">Consultar otra solicitud</a></p>
    </section>

    <section class="split-main history-public" aria-labelledby="titulo-historial">
        <h2 id="titulo-historial" class="section-title">Historial de estados</h2>
        <?php if ($eventos === []): ?>
            <p class="hint">Sin cambios de estado todavía.</p>
        <?php else: ?>
            <ol class="timeline">
                <?php foreach ($eventos as $evento): ?>
                    <li class="timeline-item timeline-<?= e($evento['nuevo']->value) ?>">
                        <span class="timeline-change">
                            <span class="badge badge-<?= e($evento['anterior']->value) ?>"><?= e($evento['anterior']->label()) ?></span>
                            <span class="timeline-arrow" aria-hidden="true">→</span>
                            <span class="visually-hidden">a</span>
                            <span class="badge badge-<?= e($evento['nuevo']->value) ?>"><?= e($evento['nuevo']->label()) ?></span>
                        </span>
                        <span class="timeline-meta num"><?= e($evento['fecha']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <p class="timeline-origin hint">Recibida el <span class="num"><?= e($solicitud['creada']) ?></span> como «Pendiente».</p>
    </section>
</div>
