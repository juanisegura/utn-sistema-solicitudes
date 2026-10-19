<?php
/**
 * @var list<array<string, mixed>>  $solicitudes página actual, más recientes primero
 * @var \App\Models\Estado|null     $estadoActivo
 * @var string                      $q           búsqueda vigente ('' = ninguna)
 * @var array<string, int>          $conteo      cantidad por estado (respeta la búsqueda, no el filtro)
 * @var int                         $total       suma de $conteo
 * @var int                         $resultados  filas que cumplen filtro + búsqueda
 * @var list<array{label: string, url: string, activo: bool}> $filtros
 * @var array{pagina: int, paginas: int, anterior: ?string, siguiente: ?string} $paginacion
 * @var string                      $limpiarUrl  listado sin búsqueda (conserva el filtro)
 */
use App\Models\Estado;
?>
<div class="page-head">
    <h1 class="page-title">Solicitudes</h1>
    <form method="get" action="/admin" class="search" role="search">
        <?php if ($estadoActivo !== null): ?>
            <input type="hidden" name="estado" value="<?= e($estadoActivo->value) ?>">
        <?php endif; ?>
        <label for="buscar" class="visually-hidden">Buscar por asunto, nombre, email o descripción</label>
        <input id="buscar" name="q" type="search" value="<?= e($q) ?>" maxlength="100"
            placeholder="Buscar por asunto, nombre, email o descripción">
        <button type="submit" class="btn">Buscar</button>
    </form>
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

<div class="list-tools">
    <nav class="segmented" aria-label="Filtrar por estado">
        <?php foreach ($filtros as $filtro): ?>
            <a href="<?= e($filtro['url']) ?>"<?= $filtro['activo'] ? ' aria-current="page"' : '' ?>><?= e($filtro['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($q !== ''): ?>
        <p class="search-status" role="status">
            <?= e($resultados) ?> <?= $resultados === 1 ? 'resultado' : 'resultados' ?> para «<?= e($q) ?>».
            <a href="<?= e($limpiarUrl) ?>">Quitar búsqueda</a>
        </p>
    <?php endif; ?>
</div>

<?php if ($solicitudes === []): ?>
    <?php if ($q !== ''): ?>
        <p class="empty">No hay solicitudes que coincidan.</p>
    <?php else: ?>
        <p class="empty">No hay solicitudes<?= $estadoActivo !== null ? ' en estado «' . e($estadoActivo->label()) . '»' : '' ?>.</p>
    <?php endif; ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden">
                Solicitudes<?= $estadoActivo !== null ? ' en estado ' . e($estadoActivo->label()) : '' ?><?= $q !== '' ? ' que coinciden con «' . e($q) . '»' : '' ?>, más recientes primero, página <?= e($paginacion['pagina']) ?> de <?= e($paginacion['paginas']) ?>
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

    <?php if ($paginacion['paginas'] > 1): ?>
        <nav class="pagination" aria-label="Paginación">
            <?php if ($paginacion['anterior'] !== null): ?>
                <a class="btn" href="<?= e($paginacion['anterior']) ?>" rel="prev">Anterior</a>
            <?php else: ?>
                <span class="btn is-disabled" aria-disabled="true">Anterior</span>
            <?php endif; ?>
            <span class="pagination-status">Página <?= e($paginacion['pagina']) ?> de <?= e($paginacion['paginas']) ?></span>
            <?php if ($paginacion['siguiente'] !== null): ?>
                <a class="btn" href="<?= e($paginacion['siguiente']) ?>" rel="next">Siguiente</a>
            <?php else: ?>
                <span class="btn is-disabled" aria-disabled="true">Siguiente</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
