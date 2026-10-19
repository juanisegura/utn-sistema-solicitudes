<?php
/**
 * @var array<string, mixed>       $solicitud con 'estado_enum', 'creada', 'actualizada'
 * @var list<\App\Models\Estado>   $estados
 * @var string|null                $flash confirmación del último cambio (PRG)
 * @var string|null                $error estado inválido (422)
 * @var list<array{fecha: string, admin: string, anterior: \App\Models\Estado, nuevo: \App\Models\Estado}> $eventos más reciente primero
 */
use App\Core\Csrf;
?>
<p class="back"><a href="/admin">Volver al listado</a></p>

<?php if ($flash !== null): ?>
    <div class="alert alert-success" role="status"><p><?= e($flash) ?></p></div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><p><?= e($error) ?></p></div>
<?php endif; ?>

<div class="detail">
    <article class="detail-main">
        <p class="detail-ref">Solicitud #<?= e($solicitud['id']) ?></p>
        <h1 class="detail-title"><?= e($solicitud['asunto']) ?></h1>
        <div class="prose prewrap"><?= e($solicitud['descripcion']) ?></div>

        <section class="history" aria-labelledby="titulo-historial">
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
                            <span class="timeline-meta"><span class="num"><?= e($evento['fecha']) ?></span>, <?= e($evento['admin']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <p class="timeline-origin hint">Recibida el <span class="num"><?= e($solicitud['creada']) ?></span> como «Pendiente».</p>
        </section>
    </article>

    <aside class="detail-side" aria-label="Datos y estado de la solicitud">
        <dl class="meta">
            <div><dt>Estado</dt><dd><span class="badge badge-<?= e($solicitud['estado_enum']->value) ?>"><?= e($solicitud['estado_enum']->label()) ?></span></dd></div>
            <div><dt>Solicitante</dt><dd><?= e($solicitud['nombre']) ?></dd></div>
            <div><dt>Email</dt><dd class="break"><?= implode('<wbr>', array_map('e', $solicitud['email_tramos'])) /* cada tramo escapado; <wbr> es markup propio */ ?></dd></div>
            <div><dt>Recibida</dt><dd class="num"><?= e($solicitud['creada']) ?></dd></div>
            <div><dt>Última actualización</dt><dd class="num"><?= e($solicitud['actualizada']) ?></dd></div>
        </dl>

        <form method="post" action="/admin/solicitudes/<?= e($solicitud['id']) ?>/estado" class="form estado-form">
            <?= Csrf::field() ?>
            <div class="field">
                <label for="estado">Cambiar estado</label>
                <select id="estado" name="estado">
                    <?php foreach ($estados as $estado): ?>
                        <option value="<?= e($estado->value) ?>"<?= $estado === $solicitud['estado_enum'] ? ' selected' : '' ?>><?= e($estado->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Guardar estado</button>
            </div>
        </form>
    </aside>
</div>
