<?php
/**
 * @var string      $email valor previo (nunca la password)
 * @var string|null $error mensaje genérico de credenciales
 */
use App\Core\Csrf;
?>
<section class="card card-narrow">
    <h1>Ingreso al panel</h1>

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
