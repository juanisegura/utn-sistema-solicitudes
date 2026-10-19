<?php
/**
 * @var string      $email valor previo (nunca la password)
 * @var string|null $error mensaje genérico de credenciales o de bloqueo (429)
 */
use App\Core\Csrf;
?>
<div class="split">
    <section class="split-intro">
        <h1 class="display">Panel de solicitudes</h1>
        <p class="lead">Acceso para el equipo de soporte: revisá las solicitudes que llegan, filtralas por estado y registrá su avance.</p>
    </section>

    <section class="split-main split-narrow" aria-labelledby="titulo-login">
        <h2 id="titulo-login" class="section-title">Ingresar</h2>

        <?php if ($error !== null): ?>
            <div class="alert alert-error" role="alert">
                <p><?= e($error) ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/login" class="form">
            <?= Csrf::field() ?>
            <div class="field">
                <label for="login-email">Email</label>
                <input id="login-email" name="email" type="email" value="<?= e($email) ?>"
                    autocomplete="username" maxlength="254" required>
            </div>
            <div class="field">
                <label for="login-password">Contraseña</label>
                <input id="login-password" name="password" type="password"
                    autocomplete="current-password" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Ingresar</button>
            </div>
        </form>
    </section>
</div>
