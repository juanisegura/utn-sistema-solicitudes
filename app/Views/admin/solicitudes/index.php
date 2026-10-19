<?php
/**
 * @var list<array<string, mixed>>  $solicitudes ya filtradas, más recientes primero
 * @var \App\Models\Estado|null     $estadoActivo
 * @var array<string, int>          $conteo cantidad por valor de estado (sobre el total)
 * @var int                         $total
 */
use App\Models\Estado;
?>
<div class="page-head">
    <h1 class="page-title">Solicitudes</h1>
</div>

<dl class="stats">
    <div class="stat stat-total">
        <dt>Total</dt>
        <dd><?= e($total) ?></dd>
    </div>
    <?php foreach (Estado::cases() as $estado): ?>
        <div class="stat stat-<?= e($estado->value) ?>">
            <dt><?= e($estado->label()) ?></dt>
            <dd><?= e($conteo[$estado->value] ?? 0) ?></dd>
        </div>
    <?php endforeach; ?>
</dl>

<nav class="segmented" aria-label="Filtrar por estado">
    <a href="/admin"<?= $estadoActivo === null ? ' aria-current="page"' : '' ?>>Todas</a>
    <?php foreach (Estado::cases() as $estado): ?>
        <a href="/admin?estado=<?= e($estado->value) ?>"<?= $estadoActivo === $estado ? ' aria-current="page"' : '' ?>><?= e($estado->label()) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($solicitudes === []): ?>
    <p class="empty">No hay solicitudes<?= $estadoActivo !== null ? ' en estado «' . e($estadoActivo->label()) . '»' : '' ?>.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden">
                Solicitudes<?= $estadoActivo !== null ? ' en estado ' . e($estadoActivo->label()) : '' ?>, más recientes primero
            </caption>
            <colgroup>
                <col class="col-id"><col class="col-asunto"><col class="col-persona"><col class="col-estado"><col class="col-fecha">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">N.º</th>
                    <th scope="col">Asunto</th>
                    <th scope="col">Solicitante</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Recibida</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($solicitudes as $s): ?>
                    <tr>
                        <td data-label="N.º" class="num">#<?= e($s['id']) ?></td>
                        <td data-label="Asunto" class="cell-asunto"><a href="/admin/solicitudes/<?= e($s['id']) ?>"><?= e($s['asunto']) ?></a></td>
                        <td data-label="Solicitante" class="cell-persona"><span class="persona-nombre"><?= e($s['nombre']) ?></span><span class="persona-email"><?= e($s['email']) ?></span></td>
                        <td data-label="Estado"><span class="badge badge-<?= e($s['estado_enum']->value) ?>"><?= e($s['estado_enum']->label()) ?></span></td>
                        <td data-label="Recibida" class="num nowrap"><?= e($s['creada']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
