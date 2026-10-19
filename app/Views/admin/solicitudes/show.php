<?php
/**
 * @var array<string, mixed>       $solicitud con 'estado_enum', 'creada', 'actualizada'
 * @var list<\App\Models\Estado>   $estados
 * @var string|null                $flash confirmación del último cambio (PRG)
 * @var string|null                $error estado inválido (422)
 */
use App\Core\Csrf;
?>
<p><a href="/admin">← Volver al listado</a></p>

<?php if ($flash !== null): ?>
    <div class="alert alert-success" role="status"><p><?= e($flash) ?></p></div>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert"><p><?= e($error) ?></p></div>
<?php endif; ?>

<article class="card">
    <header class="detail-header">
        <h1>Solicitud #<?= e($solicitud['id']) ?></h1>
        <span class="badge badge-<?= e($solicitud['estado_enum']->value) ?>"><?= e($solicitud['estado_enum']->label()) ?></span>
    </header>

    <dl class="detail">
        <dt>Asunto</dt>
        <dd><?= e($solicitud['asunto']) ?></dd>
        <dt>Solicitante</dt>
        <dd><?= e($solicitud['nombre']) ?> · <?= e($solicitud['email']) ?></dd>
        <dt>Creada</dt>
        <dd><?= e($solicitud['creada']) ?></dd>
        <dt>Última actualización</dt>
        <dd><?= e($solicitud['actualizada']) ?></dd>
        <dt>Descripción</dt>
        <dd class="prewrap"><?= e($solicitud['descripcion']) ?></dd>
    </dl>

    <form method="post" action="/admin/solicitudes/<?= e($solicitud['id']) ?>/estado" class="form form-inline">
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
            <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
    </form>
</article>
