<?php
/** @var int $id número de la solicitud recién creada */
$actual = 'pendiente';
?>
<div class="split">
    <section class="split-intro">
        <h1 class="display">Recibimos tu solicitud</h1>
        <p class="lead">Quedó registrada con este número. Tenelo a mano si te comunicás con soporte.</p>
        <p class="ticket"><span class="ticket-label">Solicitud</span> <strong>#<?= e($id) ?></strong></p>
        <p><a class="btn" href="/">Enviar otra solicitud</a></p>
    </section>

    <section class="split-main" aria-labelledby="titulo-sigue">
        <h2 id="titulo-sigue" class="section-title">Qué sigue</h2>
        <?php require __DIR__ . '/../partials/proceso.php'; ?>
    </section>
</div>
