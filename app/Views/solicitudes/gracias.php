<?php
/**
 * @var int|null    $id     número de la solicitud recién creada; null en la confirmación genérica (honeypot, F-1)
 * @var string|null $codigo código de seguimiento (F-3), solo en esta respuesta; null si ya se mostró o es genérica
 */
$actual = 'pendiente';
?>
<div class="split">
    <section class="split-intro">
        <h1 class="display">Recibimos tu solicitud</h1>
        <?php if ($id !== null): ?>
            <p class="lead">Quedó registrada con este número. Tenelo a mano si te comunicás con soporte.</p>
            <p class="ticket"><span class="ticket-label">Solicitud</span> <strong>#<?= e($id) ?></strong></p>
            <?php if ($codigo !== null): ?>
                <div class="codigo-box">
                    <p class="ticket-label" id="titulo-codigo">Código de seguimiento</p>
                    <p><code class="codigo" aria-labelledby="titulo-codigo"><?= e($codigo) ?></code></p>
                    <p class="note">Copialo ahora: no lo vamos a volver a mostrar. Con el número y este código podés
                        <a href="/seguimiento">consultar el estado de tu solicitud</a>.</p>
                </div>
            <?php endif; ?>
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
