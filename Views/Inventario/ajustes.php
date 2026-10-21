<?php
require_once "Views/Header.php";
?>

<section class="inventory-adjustments" aria-label="Ajustes de inventario">
    <div class="inventory-adjustment-tabs" role="group" aria-label="Tipo de ajuste">
        <button type="button" class="inventory-adjustment-tab is-active" aria-pressed="true" data-description="Registra productos dañados o vencidos para mantener el inventario y justificar la pérdida." data-panel="writeoff">Registrar baja</button>
        <button type="button" class="inventory-adjustment-tab" aria-pressed="false" data-description="Registra una diferencia por conteo físico como entrada o salida del stock general." data-panel="adjustment">Registrar ajuste de stock</button>
    </div>
    <p class="inventory-adjustment-description" aria-live="polite">Registra productos dañados o vencidos para mantener el inventario y justificar la pérdida.</p>

    <div class="inventory-writeoff" data-inventory-panel="writeoff">
        <div class="inventory-writeoff-grid">
            <section class="inventory-panel inventory-writeoff-form" aria-labelledby="writeoff-form-title">
                <header class="inventory-panel-heading">
                    <h2 id="writeoff-form-title">Datos del producto y del movimiento</h2>
                </header>

                <div class="inventory-field inventory-product-search">
                    <label for="writeoff-product">Producto</label>
                    <div class="inventory-input-with-icon">
                        <input id="writeoff-product" type="search" placeholder="Buscar por código de barra o nombre" aria-describedby="writeoff-product-error">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </div>
                    <span id="writeoff-product-error" class="inventory-validation-error" role="alert" hidden></span>
                    <div id="writeoff-product-results" class="inventory-product-options" role="listbox" aria-label="Resultados de búsqueda" hidden></div>
                </div>

                <div class="inventory-product-summary" aria-live="polite">
                    <div>
                        <span>Nombre</span>
                        <strong id="writeoff-summary-name">---</strong>
                    </div>
                    <div>
                        <span>Categoría</span>
                        <strong id="writeoff-summary-category">---</strong>
                    </div>
                    <div>
                        <span>Subcategoría</span>
                        <strong id="writeoff-summary-subcategory">---</strong>
                    </div>
                    <div>
                        <span>Stock general vigente</span>
                        <strong id="writeoff-summary-stock">---</strong>
                    </div>
                </div>

                <div class="inventory-writeoff-fields">
                    <div class="inventory-field">
                        <label for="writeoff-quantity">Cantidad a dar de baja <span class="inventory-required">*</span></label>
                        <input id="writeoff-quantity" type="number" value="1" min="1" step="1" aria-describedby="writeoff-quantity-error">
                        <span id="writeoff-quantity-error" class="inventory-validation-error" role="alert" hidden></span>
                    </div>
                    <div class="inventory-field">
                        <label for="writeoff-reason">Motivo de la baja <span class="inventory-required">*</span></label>
                        <select id="writeoff-reason">
                            <option selected>Daño</option>
                            <option>Vencimiento</option>
                        </select>
                    </div>
                    <div class="inventory-field inventory-field-full">
                        <label for="writeoff-justification">Justificación <span class="inventory-required">*</span></label>
                        <textarea id="writeoff-justification" rows="3" aria-describedby="writeoff-justification-error"></textarea>
                        <span id="writeoff-justification-error" class="inventory-validation-error" role="alert" hidden></span>
                        <small>Describe el hallazgo</small>
                    </div>
                    <div class="inventory-field inventory-field-full">
                        <label for="writeoff-lot">Lote (Opcional)</label>
                        <input id="writeoff-lot" type="text" placeholder="Código del lote">
                        <small>Si falta o no se lee, la baja se registra igual como «sin lote».</small>
                    </div>
                </div>
            </section>

            <aside class="inventory-writeoff-aside">
                <section class="inventory-panel inventory-balance-preview" aria-labelledby="balance-preview-title">
                    <h2 id="balance-preview-title">Vista previa del saldo</h2>
                    <dl class="inventory-balance-details">
                        <div><dt>Saldo anterior</dt><dd id="writeoff-balance-before">--- unidades</dd></div>
                        <div><dt>Baja</dt><dd id="writeoff-balance-deduction">--- unidades</dd></div>
                    </dl>
                    <div class="inventory-balance-result">
                        <span>Saldo previsto</span>
                        <strong id="writeoff-balance-after">--- unidades</strong>
                    </div>
                </section>

                <section class="inventory-panel inventory-automatic-details" aria-labelledby="automatic-details-title">
                    <h2 id="automatic-details-title">Datos automáticos</h2>
                    <dl>
                        <div><dt>Fecha</dt><dd><?= date('d/m/Y') ?></dd></div>
                        <div><dt>Responsable</dt><dd><?= e($_SESSION['nombre'] ?? 'Operador') ?> · <?= (($_SESSION['rol'] ?? 0) == 1) ? 'Administrador' : 'Vendedor' ?></dd></div>
                    </dl>
                </section>

                <aside class="inventory-kardex-note">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <div>
                        <strong>Un solo registro en Kardex</strong>
                        <p>El movimiento y la actualización del stock se guardan en una sola operación. No se alteran compras ni ventas anteriores.</p>
                    </div>
                </aside>
            </aside>
        </div>

        <footer class="inventory-writeoff-actions">
            <p>Revisa el producto, la cantidad y el saldo antes de continuar.</p>
            <div>
                <button type="button" id="writeoff-cancel-button" class="inventory-cancel-button">Cancelar</button>
                <button type="button" id="writeoff-review-button" class="inventory-review-button"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Revisar baja</button>
            </div>
        </footer>
    </div>

    <div class="inventory-writeoff inventory-stock-adjustment" data-inventory-panel="adjustment" hidden>
        <div class="inventory-writeoff-grid">
            <section class="inventory-panel inventory-writeoff-form" aria-labelledby="stock-adjustment-form-title">
                <header class="inventory-panel-heading">
                    <h2 id="stock-adjustment-form-title">Datos del producto y del movimiento</h2>
                </header>

                <div class="inventory-field inventory-product-search">
                    <label for="stock-adjustment-product">Producto</label>
                    <div class="inventory-input-with-icon">
                        <input id="stock-adjustment-product" type="search" placeholder="Buscar por código de barra o nombre" aria-describedby="stock-adjustment-product-error">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </div>
                    <span id="stock-adjustment-product-error" class="inventory-validation-error" role="alert" hidden></span>
                    <div id="stock-adjustment-product-results" class="inventory-product-options" role="listbox" aria-label="Resultados de búsqueda" hidden></div>
                </div>

                <div class="inventory-product-summary" aria-live="polite">
                    <div><span>Nombre</span><strong id="stock-adjustment-summary-name">---</strong></div>
                    <div><span>Categoría</span><strong id="stock-adjustment-summary-category">---</strong></div>
                    <div><span>Subcategoría</span><strong id="stock-adjustment-summary-subcategory">---</strong></div>
                    <div><span>Stock general vigente</span><strong id="stock-adjustment-summary-stock">---</strong></div>
                </div>

                <div class="inventory-adjustment-fields">
                    <fieldset class="inventory-field inventory-direction-field">
                        <legend>Sentido <span class="inventory-required">*</span></legend>
                        <div class="inventory-direction-options">
                            <label class="inventory-direction-option is-selected">
                                <input type="radio" name="stock_adjustment_direction" value="entry" checked>
                                <span class="inventory-direction-radio" aria-hidden="true"></span>
                                <span>Entrada (+)</span>
                            </label>
                            <label class="inventory-direction-option">
                                <input type="radio" name="stock_adjustment_direction" value="exit">
                                <span class="inventory-direction-radio" aria-hidden="true"></span>
                                <span>Salida (−)</span>
                            </label>
                        </div>
                    </fieldset>
                    <div class="inventory-field inventory-adjustment-quantity">
                        <label for="stock-adjustment-quantity">Cantidad <span class="inventory-required">*</span></label>
                        <input id="stock-adjustment-quantity" type="number" value="1" min="1" step="1" aria-describedby="stock-adjustment-quantity-help stock-adjustment-quantity-error">
                        <small id="stock-adjustment-quantity-help">Entero mayor que cero; una salida no puede dejar saldo negativo.</small>
                        <span id="stock-adjustment-quantity-error" class="inventory-validation-error" role="alert" hidden></span>
                    </div>
                    <div class="inventory-field inventory-field-full">
                        <label for="stock-adjustment-justification">Motivo <span class="inventory-required">*</span></label>
                        <textarea id="stock-adjustment-justification" rows="3" aria-describedby="stock-adjustment-justification-error"></textarea>
                        <span id="stock-adjustment-justification-error" class="inventory-validation-error" role="alert" hidden></span>
                        <small>Describe la diferencia verificada en el conteo físico.</small>
                    </div>
                </div>
            </section>

            <aside class="inventory-writeoff-aside">
                <section class="inventory-panel inventory-balance-preview" aria-labelledby="stock-adjustment-balance-title">
                    <h2 id="stock-adjustment-balance-title">Vista previa del saldo</h2>
                    <dl class="inventory-balance-details">
                        <div><dt>Saldo anterior</dt><dd id="stock-adjustment-balance-before">--- unidades</dd></div>
                        <div><dt id="stock-adjustment-balance-change-label">Entrada</dt><dd id="stock-adjustment-balance-change">--- unidades</dd></div>
                    </dl>
                    <div class="inventory-balance-result">
                        <span>Saldo previsto</span>
                        <strong id="stock-adjustment-balance-after">--- unidades</strong>
                    </div>
                </section>

                <aside class="inventory-adjustment-stock-note">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <p>Si el stock cambia mientras preparas el ajuste, se mostrará el nuevo saldo y tendrás que revisarlo antes de confirmar.</p>
                </aside>

                <section class="inventory-panel inventory-automatic-details" aria-labelledby="stock-adjustment-automatic-title">
                    <h2 id="stock-adjustment-automatic-title">Datos automáticos</h2>
                    <dl>
                        <div><dt>Fecha</dt><dd><?= date('d/m/Y') ?></dd></div>
                        <div><dt>Responsable</dt><dd><?= e($_SESSION['nombre'] ?? 'Operador') ?> · <?= (($_SESSION['rol'] ?? 0) == 1) ? 'Administrador' : 'Vendedor' ?></dd></div>
                    </dl>
                </section>

                <aside class="inventory-kardex-note">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <div>
                        <strong>Un solo registro en Kardex</strong>
                        <p>El ajuste y la actualización del stock se registran como un único movimiento.</p>
                    </div>
                </aside>
            </aside>
        </div>

        <footer class="inventory-writeoff-actions">
            <p>Verifica el producto, el sentido y la cantidad antes de continuar.</p>
            <div>
                <button type="button" id="stock-adjustment-cancel-button" class="inventory-cancel-button">Cancelar</button>
                <button type="button" id="stock-adjustment-review-button" class="inventory-review-button"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Revisar ajuste</button>
            </div>
        </footer>
    </div>
</section>

<div id="writeoff-confirmation" class="inventory-confirmation-overlay" hidden>
    <section class="inventory-confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="writeoff-confirmation-title">
        <header class="inventory-confirmation-header">
            <h2 id="writeoff-confirmation-title">¿Confirmar esta baja?</h2>
            <button type="button" class="inventory-confirmation-close" aria-label="Cerrar">×</button>
        </header>
        <p class="inventory-confirmation-intro">Revisa el movimiento. El stock se verificará nuevamente antes de registrar la operación.</p>
        <dl class="inventory-confirmation-details">
            <div><dt>Producto</dt><dd id="writeoff-confirm-product">---</dd></div>
            <div><dt>Código de barras</dt><dd id="writeoff-confirm-barcode">---</dd></div>
            <div><dt id="writeoff-confirm-movement-label">Cantidad / motivo</dt><dd id="writeoff-confirm-quantity-reason">---</dd></div>
            <div id="writeoff-confirm-lot-row"><dt>Lote informativo</dt><dd id="writeoff-confirm-lot">---</dd></div>
        </dl>
        <div class="inventory-confirmation-total">
            <strong id="writeoff-confirm-equation">---</strong>
            <span id="writeoff-confirm-summary">---</span>
        </div>
        <dl class="inventory-confirmation-details inventory-confirmation-secondary">
            <div class="inventory-confirm-justification">
                <dt id="writeoff-confirm-justification-label">Justificación</dt>
                <dd id="writeoff-confirm-justification">---</dd>
            </div>
            <div><dt>Fecha / responsable</dt><dd><span id="writeoff-confirm-date"><?= date('d/m/Y') ?></span> · <span id="writeoff-confirm-user"><?= e($_SESSION['nombre'] ?? 'Operador') ?></span></dd></div>
        </dl>
        <aside class="inventory-confirmation-note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <div>
                <strong>Una confirmación, un movimiento</strong>
                <p id="writeoff-confirm-note">Se guardarán la baja, el nuevo stock y un único registro en Kardex. Durante el procesamiento, confirmar queda bloqueado para evitar reenvíos. No se alteran compras ni ventas anteriores.</p>
            </div>
        </aside>
        <footer class="inventory-confirmation-actions">
            <button type="button" class="inventory-cancel-button inventory-confirmation-back">Volver al formulario</button>
            <button type="button" class="inventory-review-button inventory-confirmation-submit">Confirmar baja</button>
        </footer>
    </section>
</div>

<?php require_once "Views/Footer.php"; ?>
