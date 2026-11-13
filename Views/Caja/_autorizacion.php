<?php
$auth_warning = $auth_warning ?? '';
$auth_reason_placeholder = $auth_reason_placeholder ?? 'Explica el motivo de la excepción';
$auth_submit_label = $auth_submit_label ?? 'Autorizar';
$auth_help_text = $auth_help_text ?? 'Obligatorio. La autorización vale una sola vez, solo para esta operación y este vendedor.';
?>
<div class="cash-auth-overlay" id="cash_auth_overlay" hidden>
    <section class="cash-auth-dialog" role="dialog" aria-modal="true" aria-labelledby="cash_auth_title">
        <h2 id="cash_auth_title">Autorización del administrador</h2>
        <div class="cash-auth-warning" id="cash_auth_warning"><?= e($auth_warning) ?></div>
        <form id="cash_auth_form" novalidate>
            <div class="cash-auth-field">
                <label for="cash_admin_email">Correo del administrador</label>
                <input type="email" id="cash_admin_email" name="admin_email"
                    autocomplete="off" placeholder="admin@ejemplo.com"
                    aria-describedby="cash_admin_email_error" required>
                <span class="cash-auth-field-error" id="cash_admin_email_error" aria-live="polite"></span>
            </div>
            <div class="cash-auth-field">
                <label for="cash_admin_password">Contraseña</label>
                <input type="password" id="cash_admin_password" name="admin_password"
                    autocomplete="off" placeholder="Contraseña"
                    aria-describedby="cash_admin_password_error" required>
                <span class="cash-auth-field-error" id="cash_admin_password_error" aria-live="polite"></span>
            </div>
            <div class="cash-auth-field">
                <label for="cash_auth_reason">Motivo de la excepción</label>
                <textarea id="cash_auth_reason" name="exception_reason" rows="3"
                    placeholder="<?= e($auth_reason_placeholder) ?>"
                    aria-describedby="cash_auth_reason_error" required></textarea>
                <span class="cash-auth-field-error" id="cash_auth_reason_error" aria-live="polite"></span>
            </div>
            <p class="cash-auth-help"><?= e($auth_help_text) ?></p>
            <p class="cash-auth-feedback" id="cash_auth_feedback" role="alert" aria-live="assertive" hidden></p>
            <div class="cash-auth-actions">
                <button class="btn btn-secondary" type="button" id="cash_auth_cancel">Cancelar</button>
                <button class="btn btn-cash-primary" type="submit" id="cash_auth_submit"><?= e($auth_submit_label) ?></button>
            </div>
        </form>
    </section>
</div>
