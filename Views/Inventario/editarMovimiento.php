<?php require_once "Views/Header.php"; ?>

<section id="mov-edit" class="mov-page" data-id="<?= (int)($data['id_movimiento'] ?? 0) ?>">
    <a id="mov-back" class="mov-back" href="<?= BASE_URL ?>/Inventario/movimientos">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al detalle
    </a>
    <div id="mov-status" class="mov-status" aria-live="polite" hidden></div>

    <div id="mov-form-wrap" hidden>
        <form id="mov-form" novalidate>
            <div class="mov-edit-grid">
                <section class="mov-card">
                    <h3>Datos del movimiento</h3>
                    <div class="mov-product-chip"><strong id="mov-e-producto"></strong><code id="mov-e-codigo"></code></div>

                    <div class="mov-row-3">
                        <div class="mov-field"><label>Número</label><div class="mov-locked"><i class="fa-solid fa-lock" aria-hidden="true"></i><span id="mov-e-numero"></span></div></div>
                        <div class="mov-field"><label>Fecha y hora original</label><div class="mov-locked"><i class="fa-solid fa-lock" aria-hidden="true"></i><span id="mov-e-fecha"></span></div></div>
                        <div class="mov-field"><label>Responsable original</label><div class="mov-locked"><i class="fa-solid fa-lock" aria-hidden="true"></i><span id="mov-e-responsable"></span></div></div>
                    </div>

                    <div class="mov-row-2">
                        <div class="mov-field">
                            <label for="mov-e-tipo">Tipo de movimiento <span class="mov-req">*</span></label>
                            <select id="mov-e-tipo">
                                <option value="baja_danio">Baja por daño</option>
                                <option value="baja_vencimiento">Baja por vencimiento</option>
                                <option value="ajuste_positivo">Ajuste positivo</option>
                                <option value="ajuste_negativo">Ajuste negativo</option>
                            </select>
                        </div>
                        <div class="mov-field">
                            <label for="mov-e-cantidad">Cantidad <span class="mov-req">*</span></label>
                            <input type="number" id="mov-e-cantidad" min="1" step="1" inputmode="numeric">
                            <small>Entero mayor que cero. El tipo define el signo.</small>
                            <p id="mov-err-cantidad" class="mov-field-error" role="alert" hidden></p>
                        </div>
                    </div>

                    <div class="mov-field">
                        <label for="mov-e-justificacion">Justificación <span class="mov-req">*</span></label>
                        <textarea id="mov-e-justificacion" rows="3"></textarea>
                        <p id="mov-err-justificacion" class="mov-field-error" role="alert" hidden></p>
                    </div>

                    <div class="mov-field" id="mov-lote-wrap">
                        <label for="mov-e-lote">Lote (opcional)</label>
                        <input type="text" id="mov-e-lote" placeholder="Código del lote" autocomplete="off">
                        <small>Solo en bajas. Si falta, queda «sin lote».</small>
                    </div>

                    <hr class="mov-sep">

                    <div class="mov-field">
                        <label for="mov-e-motivo">Motivo de la corrección <span class="mov-req">*</span></label>
                        <textarea id="mov-e-motivo" rows="3"></textarea>
                        <small id="mov-motivo-ayuda"></small>
                        <p id="mov-err-motivo" class="mov-field-error" role="alert" hidden></p>
                    </div>
                </section>

                <aside>
                    <section class="mov-card mov-preview">
                        <h3>Vista previa del saldo</h3>
                        <dl class="mov-dl">
                            <div><dt>Saldo actual antes de corregir</dt><dd id="mov-p-actual"></dd></div>
                            <div><dt>Efecto vigente anterior</dt><dd id="mov-p-anterior"></dd></div>
                            <div><dt>Cantidad corregida</dt><dd id="mov-p-nuevo"></dd></div>
                            <div class="mov-total"><dt>Saldo resultante</dt><dd id="mov-p-saldo"></dd></div>
                        </dl>
                        <p id="mov-p-formula" class="mov-formula"></p>
                        <p id="mov-p-negativo" class="mov-field-error" role="alert" hidden></p>
                    </section>
                    <div class="mov-warning" role="note">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <p>Si otro usuario cambia este movimiento o su saldo mientras revisas, se te pedirá revisar la vista previa antes de confirmar.</p>
                    </div>
                </aside>
            </div>

            <div id="mov-e-error" class="mov-status is-error" role="alert" hidden></div>

            <div class="mov-actionbar">
                <p>Se guarda una corrección nueva; el registro original se conserva.</p>
                <div>
                    <a id="mov-cancelar" class="mov-btn" href="<?= BASE_URL ?>/Inventario/movimientos">Cancelar</a>
                    <button type="submit" id="mov-confirmar" class="mov-btn mov-btn-gold"><i class="fa-solid fa-check" aria-hidden="true"></i> Confirmar corrección</button>
                </div>
            </div>
        </form>
    </div>
</section>

<?php require_once "Views/Footer.php"; ?>