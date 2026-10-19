<?php
/**
 * @var array<string, mixed>       $solicitud con 'estado_enum', 'creada', 'actualizada'
 * @var list<\App\Models\Estado>   $estados
 * @var string|null                $flash confirmación del último cambio (PRG)
 * @var string|null                $error estado inválido (422)
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
    </article>

    <aside class="detail-side" aria-label="Datos y estado de la solicitud">
        <dl class="meta">
            <div><dt>Estado</dt><dd><span class="badge badge-<?= e($solicitud['estado_enum']->value) ?>"><?= e($solicitud['estado_enum']->label()) ?></span></dd></div>
            <div><dt>Solicitante</dt><dd><?= e($solicitud['nombre']) ?></dd></div>
            <div><dt>Email</dt><dd class="break"><?= e($solicitud['email']) ?></dd></div>
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
