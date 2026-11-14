<?php
require_once "Views/Header.php";

$tiposEgreso = [
    ['value' => 'cash_out', 'label' => $data['expense_types'][0] ?? 'Salida de caja'],
    ['value' => 'admin_withdrawal', 'label' => $data['expense_types'][1] ?? 'Retiro del administrador'],
    ['value' => 'omitted_withdrawal', 'label' => $data['expense_types'][2] ?? 'Retiro omitido']
];
?>

<section class="cash-movements">
    <div class="cash-movements-loading" id="cash_movements_loading" role="status">Cargando el estado de la caja…</div>

    <section class="cash-movements-notice" id="cash_movements_no_session" role="status" hidden>
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <div>
            <h2>Sin sesión de caja abierta</h2>
            <p>Debes abrir una sesión de caja para registrar movimientos.</p>
            <a class="cash-movements-open-link" href="<?= BASE_URL ?>/Caja/abrir">Abrir caja</a>
        </div>
    </section>

    <section class="cash-movements-load-error" id="cash_movements_load_error" role="alert" hidden>
        <p>No se pudo cargar el estado de caja. Inténtalo de nuevo.</p>
        <button class="btn btn-secondary" type="button" id="cash_movements_retry">Reintentar</button>
    </section>

    <div id="cash_movements_workspace" hidden>
        <header class="cash-movements-heading">
            <h2>Movimientos de caja</h2>
            <p>Registra ingresos y egresos de efectivo de tu sesión abierta.</p>
        </header>

        <div class="cash-movements-tabs" role="tablist" aria-label="Tipo de movimiento">
            <button type="button" class="cash-movements-tab is-active" id="cash_income_tab"
                role="tab" aria-selected="true" aria-controls="cash_income_panel" tabindex="0">
                Ingreso
            </button>
            <button type="button" class="cash-movements-tab" id="cash_expense_tab"
                role="tab" aria-selected="false" aria-controls="cash_expense_panel" tabindex="-1">
                Egreso
            </button>
            <button type="button" class="cash-movements-tab" id="cash_correction_tab"
                role="tab" aria-selected="false" aria-controls="cash_correction_panel" tabindex="-1">
                Corregir registro
            </button>
        </div>

        <div class="cash-movements-layout">
            <div class="cash-movements-form-column">
                <form class="cash-movement-card cash-movement-form" id="cash_income_form"
                    role="tabpanel" aria-labelledby="cash_income_tab" aria-hidden="false" novalidate>
                    <h3>Datos del ingreso</h3>

                    <fieldset class="cash-movement-field cash-movement-field-full cash-type-field"
                        role="radiogroup" aria-labelledby="cash_income_type_label">
                        <legend id="cash_income_type_label">Tipo de ingreso</legend>
                        <div class="cash-type-options cash-type-options-two">
                            <label class="cash-type-option">
                                <input type="radio" name="income_type" value="manual" checked>
                                <span>Ingreso manual</span>
                            </label>
                            <label class="cash-type-option">
                                <input type="radio" name="income_type" value="admin_injection">
                                <span>Inyección del administrador</span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="cash-movement-fields-grid">
                        <div class="cash-movement-field">
                            <label for="cash_income_amount">Monto (S/)</label>
                            <input type="text" id="cash_income_amount" name="amount"
                                inputmode="decimal" autocomplete="off" placeholder="0,00"
                                aria-describedby="cash_income_amount_help cash_income_amount_error">
                            <small id="cash_income_amount_help">Mayor que cero, con hasta dos decimales.</small>
                            <span class="cash-movement-error" id="cash_income_amount_error" aria-live="polite"></span>
                        </div>

                        <div class="cash-movement-field" id="cash_income_admin_group" hidden>
                            <label for="cash_income_admin">Administrador que entregó</label>
                            <select id="cash_income_admin" name="administrator_id"
                                aria-describedby="cash_income_admin_help cash_income_admin_error">
                                <option value="">Selecciona un administrador</option>
                            </select>
                            <small id="cash_income_admin_help">Obligatorio en una inyección.</small>
                            <span class="cash-movement-error" id="cash_income_admin_error" aria-live="polite"></span>
                        </div>
                    </div>

                    <div class="cash-movement-field cash-movement-field-full">
                        <label for="cash_income_reason">Motivo</label>
                        <textarea id="cash_income_reason" name="reason" rows="3"
                            aria-describedby="cash_income_reason_help cash_income_reason_error"></textarea>
                        <small id="cash_income_reason_help">Obligatorio.</small>
                        <span class="cash-movement-error" id="cash_income_reason_error" aria-live="polite"></span>
                    </div>

                    <div class="cash-movement-feedback" id="cash_income_feedback" role="status" aria-live="polite" hidden></div>
                    <div class="cash-movement-actions">
                        <button class="btn btn-secondary" type="reset" id="cash_income_cancel">Cancelar</button>
                        <button class="btn btn-cash-primary" type="submit" id="cash_income_submit">Confirmar ingreso</button>
                    </div>
                </form>

                <form class="cash-movement-card cash-movement-form" id="cash_expense_form"
                    role="tabpanel" aria-labelledby="cash_expense_tab" aria-hidden="true" hidden novalidate>
                    <h3>Datos del egreso</h3>

                    <fieldset class="cash-movement-field cash-movement-field-full cash-type-field"
                        role="radiogroup" aria-labelledby="cash_expense_type_label">
                        <legend id="cash_expense_type_label">Tipo de egreso</legend>
                        <div class="cash-type-options cash-type-options-three">
                            <?php foreach ($tiposEgreso as $tipo): ?>
                            <label class="cash-type-option">
                                <input type="radio" name="expense_type" value="<?= e($tipo['value']) ?>"
                                    <?= $tipo['value'] === 'cash_out' ? 'checked' : '' ?>>
                                <span><?= e($tipo['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="cash-movement-fields-grid">
                        <div class="cash-movement-field">
                            <label for="cash_expense_amount">Monto (S/)</label>
                            <input type="text" id="cash_expense_amount" name="amount"
                                inputmode="decimal" autocomplete="off" placeholder="0,00"
                                aria-describedby="cash_expense_amount_help cash_expense_amount_error">
                            <small id="cash_expense_amount_help">Mayor que cero, con hasta dos decimales.</small>
                            <span class="cash-movement-error" id="cash_expense_amount_error" aria-live="polite"></span>
                        </div>

                        <div class="cash-movement-field" id="cash_expense_admin_group" hidden>
                            <label for="cash_expense_admin">Administrador que recibió</label>
                            <select id="cash_expense_admin" name="administrator_id"
                                aria-describedby="cash_expense_admin_help cash_expense_admin_error">
                                <option value="">Selecciona un administrador</option>
                            </select>
                            <small id="cash_expense_admin_help">Obligatorio en un retiro.</small>
                            <span class="cash-movement-error" id="cash_expense_admin_error" aria-live="polite"></span>
                        </div>

                        <div class="cash-movement-field" id="cash_origin_turn_group" hidden>
                            <label for="cash_origin_turn">Turno de origen</label>
                            <select id="cash_origin_turn" name="origin_turn_id"
                                aria-describedby="cash_origin_turn_help cash_origin_turn_error">
                                <option value="">Selecciona el turno cerrado</option>
                            </select>
                            <small id="cash_origin_turn_help">Turno donde ocurrió el retiro.</small>
                            <span class="cash-movement-error" id="cash_origin_turn_error" aria-live="polite"></span>
                        </div>
                    </div>

                    <div class="cash-movement-field cash-movement-field-full">
                        <label for="cash_expense_reason">Motivo</label>
                        <textarea id="cash_expense_reason" name="reason" rows="3"
                            aria-describedby="cash_expense_reason_help cash_expense_reason_error"></textarea>
                        <small id="cash_expense_reason_help">Obligatorio.</small>
                        <span class="cash-movement-error" id="cash_expense_reason_error" aria-live="polite"></span>
                    </div>

                    <div class="cash-movement-field cash-confirm-omitted" id="cash_confirm_omitted_group" hidden>
                        <label class="cash-confirm-omitted-label" for="cash_confirm_omitted">
                            <input type="checkbox" id="cash_confirm_omitted" name="confirm_previous_open">
                            <span>Confirmo que el retiro ocurrió con la caja anterior abierta.</span>
                        </label>
                        <label class="cash-confirm-omitted-label" for="cash_confirm_reflected"><input type="checkbox" id="cash_confirm_reflected" name="confirmed_reflected_in_opening"> El retiro ya estaba reflejado en el efectivo contado al abrir esta sesión.</label>
                        <span class="cash-movement-error" id="cash_confirm_omitted_error" aria-live="polite"></span>
                    </div>

                    <div class="cash-movement-feedback" id="cash_expense_feedback" role="status" aria-live="polite" hidden></div>
                    <div class="cash-movement-actions">
                        <button class="btn btn-secondary" type="reset" id="cash_expense_cancel">Cancelar</button>
                        <button class="btn btn-cash-primary" type="submit" id="cash_expense_submit">Confirmar egreso</button>
                    </div>
                </form>

                <form class="cash-movement-card cash-movement-form" id="cash_correction_form"
                    role="tabpanel" aria-labelledby="cash_correction_tab" aria-hidden="true" hidden novalidate>
                    <h3>Solicitar corrección de un registro</h3>
                    <p class="cash-correction-warning">
                        Úsalo solo para un error de registro comprobable. Un faltante físico se documenta mediante arqueo;
                        no se crea un egreso para forzar que los saldos coincidan.
                    </p>

                    <div class="cash-movement-field">
                        <label for="cash_correction_original">Registro original</label>
                        <select id="cash_correction_original" name="original_movement_id"
                            aria-describedby="cash_correction_original_help cash_correction_original_error">
                            <option value="">Selecciona el movimiento que se registró mal</option>
                        </select>
                        <small id="cash_correction_original_help">El registro original se conserva y queda vinculado a la corrección.</small>
                        <span class="cash-movement-error" id="cash_correction_original_error" aria-live="polite"></span>
                    </div>
                    <p class="cash-correction-original-detail" id="cash_correction_original_detail" hidden></p>

                    <div class="cash-movement-field">
                        <label for="cash_correction_amount">Importe correcto total (S/)</label>
                        <input type="text" id="cash_correction_amount" name="corrected_amount"
                            inputmode="decimal" autocomplete="off" placeholder="0,00"
                            aria-describedby="cash_correction_amount_help cash_correction_amount_error">
                        <small id="cash_correction_amount_help">Indica el importe que debió tener el registro original (desde 0,00).</small>
                        <span class="cash-movement-error" id="cash_correction_amount_error" aria-live="polite"></span>
                    </div>

                    <div class="cash-movement-field">
                        <label for="cash_correction_reason">Motivo y evidencia del error</label>
                        <textarea id="cash_correction_reason" name="reason" rows="3"
                            aria-describedby="cash_correction_reason_help cash_correction_reason_error"></textarea>
                        <small id="cash_correction_reason_help">Describe qué dato se registró mal y la evidencia del importe correcto.</small>
                        <span class="cash-movement-error" id="cash_correction_reason_error" aria-live="polite"></span>
                    </div>

                    <p class="cash-correction-notice">
                        La corrección se guarda como un registro adicional vinculado. No se reemplaza ni elimina el original;
                        el efecto en el saldo lo confirma el servidor.
                    </p>
                    <div class="cash-movement-feedback" id="cash_correction_feedback" role="status" aria-live="polite" hidden></div>
                    <div class="cash-movement-actions">
                        <button class="btn btn-secondary" type="reset" id="cash_correction_cancel">Cancelar</button>
                        <button class="btn btn-cash-primary" type="submit" id="cash_correction_submit">Solicitar corrección</button>
                    </div>

                    <section class="cash-correction-history" aria-labelledby="cash_correction_history_title">
                        <h4 id="cash_correction_history_title">Originales y correcciones vinculadas</h4>
                        <div class="cash-correction-history-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">Registro</th>
                                        <th scope="col">Referencia</th>
                                        <th scope="col">Importe</th>
                                        <th scope="col">Motivo / efecto</th>
                                    </tr>
                                </thead>
                                <tbody id="cash_correction_history_rows"></tbody>
                            </table>
                        </div>
                    </section>
                </form>
            </div>

            <aside class="cash-movement-card cash-balance-effect" aria-labelledby="cash_effect_title">
                <h3 id="cash_effect_title">Efecto en el saldo</h3>
                <dl>
                    <div><dt>Saldo actual</dt><dd id="cash_effect_current">—</dd></div>
                    <div class="cash-effect-impact">
                        <dt id="cash_effect_label">Ingreso</dt>
                        <dd id="cash_effect_amount">+S/ 0,00</dd>
                    </div>
                    <div class="cash-effect-total">
                        <dt id="cash_effect_total_label">Saldo previsto</dt>
                        <dd id="cash_effect_total">—</dd>
                    </div>
                </dl>
                <p class="cash-omitted-note" id="cash_omitted_note" hidden>
                    El conteo de apertura ya refleja esta salida, por eso el saldo no cambia. El retiro se vincula al turno de origen y su cierre original se conserva.
                </p>
                <section class="cash-origin-close" id="cash_origin_close" aria-label="Cierre del turno de origen" hidden>
                    <h4>Cierre anterior</h4>
                    <dl>
                        <div><dt>Esperaba</dt><dd id="cash_origin_expected">—</dd></div>
                        <div><dt>Contó</dt><dd id="cash_origin_counted">—</dd></div>
                        <div><dt>Faltante</dt><dd id="cash_origin_shortage">—</dd></div>
                    </dl>
                </section>
                <p class="cash-balance-note" id="cash_balance_note">El saldo se actualiza una sola vez, aunque se repita el envío.</p>
            </aside>
        </div>
    </div>

    <div class="cash-movements-notice cash-movements-confirmation" id="cash_movements_confirmation"
        role="status" aria-live="polite" hidden></div>
</section>

<script>
window.CAJA_MOVEMENTS_CONFIG = <?= json_encode([
    'expenseTypes' => $tiposEgreso,
    'sellerUsername' => $_SESSION['username'] ?? $_SESSION['user'] ?? ''
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
