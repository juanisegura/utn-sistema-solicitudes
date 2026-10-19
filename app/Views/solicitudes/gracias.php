<?php
/** @var int $id número de la solicitud recién creada */
?>
<section class="card card-success">
    <h1>¡Gracias! Recibimos tu solicitud</h1>
    <p>Tu solicitud quedó registrada con el número <strong class="ticket">#<?= e($id) ?></strong>.</p>
    <p>El equipo de soporte la va a revisar y actualizar su estado.</p>
    <p><a class="btn" href="/">Enviar otra solicitud</a></p>
</section>
