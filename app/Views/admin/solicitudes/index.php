<?php
/**
 * @var list<array<string, mixed>>  $solicitudes ya filtradas, más recientes primero
 * @var \App\Models\Estado|null     $estadoActivo
 * @var array<string, int>          $conteo cantidad por valor de estado (sobre el total)
 * @var int                         $total
 */
use App\Models\Estado;
?>
<h1>Solicitudes</h1>

<nav class="filters" aria-label="Filtrar por estado">
    <a class="btn btn-small" href="/admin"<?= $estadoActivo === null ? ' aria-current="page"' : '' ?>>
        Todas (<?= e($total) ?>)
    </a>
    <?php foreach (Estado::cases() as $estado): ?>
        <a class="btn btn-small" href="/admin?estado=<?= e($estado->value) ?>"<?= $estadoActivo === $estado ? ' aria-current="page"' : '' ?>>
            <?= e($estado->label()) ?> (<?= e($conteo[$estado->value] ?? 0) ?>)
        </a>
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
            <thead>
                <tr>
                    <th scope="col">N.º</th>
                    <th scope="col">Fecha</th>
                    <th scope="col">Solicitante</th>
                    <th scope="col">Asunto</th>
                    <th scope="col">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($solicitudes as $s): ?>
                    <tr>
                        <td data-label="N.º">#<?= e($s['id']) ?></td>
                        <td data-label="Fecha" class="nowrap"><?= e($s['creada']) ?></td>
                        <td data-label="Solicitante"><?= e($s['nombre']) ?><br><span class="muted"><?= e($s['email']) ?></span></td>
                        <td data-label="Asunto"><a href="/admin/solicitudes/<?= e($s['id']) ?>"><?= e($s['asunto']) ?></a></td>
                        <td data-label="Estado"><span class="badge badge-<?= e($s['estado_enum']->value) ?>"><?= e($s['estado_enum']->label()) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
