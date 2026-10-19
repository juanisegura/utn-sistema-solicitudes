<?php
/** @var int|null $id número de la solicitud recién creada; null en la confirmación genérica (honeypot, F-1) */
$actual = 'pendiente';
?>
<div class="split">
    <section class="split-intro">
        <h1 class="display">Recibimos tu solicitud</h1>
        <?php if ($id !== null): ?>
            <p class="lead">Quedó registrada con este número. Tenelo a mano si te comunicás con soporte.</p>
            <p class="ticket"><span class="ticket-label">Solicitud</span> <strong>#<?= e($id) ?></strong></p>
        <?php else: ?>
            <p class="lead">Quedó registrada. El equipo de soporte la va a revisar.</p>
        <?php endif; ?>
        <p><a class="btn" href="/">Enviar otra solicitud</a></p>
    </section>

    <section class="split-main" aria-labelledby="titulo-sigue">
        <h2 id="titulo-sigue" class="section-title">Qué sigue</h2>
        <?php require __DIR__ . '/../partials/proceso.php'; ?>
    </section>
</div>
