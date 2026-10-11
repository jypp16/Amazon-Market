<?php require_once "Views/Header.php"; ?>

<section class="purchase-detail" id="detalle_compra" data-compra-id="<?= (int)$data['id_compra'] ?>">
    <header class="purchase-detail-header">
        <div>
            <nav class="purchase-breadcrumb" aria-label="Ruta de navegación">
                <a href="<?= BASE_URL ?>/Compra">Compras</a>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                <a href="<?= BASE_URL ?>/Compra">Historial de compras</a>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                <span aria-current="page">Detalle</span>
            </nav>
            <div class="purchase-detail-title">
                <h2 id="detalle_compra_titulo">Cargando compra…</h2>
                <span id="detalle_compra_estado" class="purchase-status" hidden></span>
            </div>
        </div>
        <div class="purchase-detail-header-actions">
            <?php if ((int)$rol === 1 || in_array('compras.editar', $slugs, true)): ?>
            <a id="detalle_compra_corregir" href="<?= BASE_URL ?>/Compra/corregir/<?= (int)$data['id_compra'] ?>"
                class="btn btn-secondary" hidden>
                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Corregir compra
            </a>
            <?php endif; ?>
            <?php if ((int)$rol === 1): ?>
            <button id="detalle_compra_anular" type="button" class="btn btn-danger" hidden>
                <i class="fa-solid fa-ban" aria-hidden="true"></i> Anular compra
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/Compra" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al historial
            </a>
        </div>
    </header>

    <div id="detalle_compra_error" class="purchase-detail-error" role="alert" hidden></div>
    <div id="detalle_compra_cargando" class="purchase-detail-loading" role="status">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Cargando detalle de la compra…
    </div>

    <div id="detalle_compra_contenido" hidden>
        <section class="purchase-detail-summary" aria-label="Resumen de la compra">
            <div class="purchase-detail-summary-item">
                <span>Proveedor</span>
                <strong id="detalle_compra_proveedor">—</strong>
                <small id="detalle_compra_documento_proveedor"></small>
            </div>
            <div class="purchase-detail-summary-item">
                <span>Fecha de emisión</span>
                <strong id="detalle_compra_fecha_emision">—</strong>
            </div>
            <div class="purchase-detail-summary-item">
                <span>Fecha de recepción</span>
                <strong id="detalle_compra_fecha_recepcion">—</strong>
            </div>
            <div class="purchase-detail-summary-item">
                <span>Registró</span>
                <strong id="detalle_compra_usuario">—</strong>
                <small id="detalle_compra_fecha_registro"></small>
            </div>
            <div class="purchase-detail-summary-item">
                <span>Total del documento</span>
                <strong id="detalle_compra_total">S/ 0.00</strong>
            </div>
            <div class="purchase-detail-summary-item">
                <span>Suma de líneas</span>
                <strong id="detalle_compra_suma">S/ 0.00</strong>
            </div>
            <div class="purchase-detail-summary-item">
                <span>Conciliación</span>
                <strong id="detalle_compra_conciliacion" class="purchase-detail-reconciliation">—</strong>
            </div>
            <div class="purchase-detail-summary-item">
                <span>Ingreso registrado</span>
                <strong id="detalle_compra_unidades">0 unidades en 0 líneas</strong>
            </div>
        </section>

        <section class="purchase-detail-card">
            <div class="purchase-detail-card-heading">
                <h3>Productos de la compra</h3>
                <div class="purchase-detail-versions" role="group" aria-label="Versión de los datos">
                    <button type="button" class="purchase-detail-version is-active" data-version="vigente" aria-pressed="true">
                        Vigente
                    </button>
                    <button type="button" class="purchase-detail-version" data-version="original" aria-pressed="false">
                        Original
                    </button>
                </div>
            </div>
            <p id="detalle_compra_version_aviso" class="purchase-detail-version-note" hidden></p>
            <div class="purchase-detail-table-scroll">
                <table class="purchase-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">Producto</th>
                            <th scope="col">Presentación</th>
                            <th scope="col">Cantidad</th>
                            <th scope="col">Equivalencia</th>
                            <th scope="col">Unidades base</th>
                            <th scope="col">Lote</th>
                            <th scope="col">Vencimiento</th>
                            <th scope="col">Costo / presentación</th>
                            <th scope="col">Importe</th>
                        </tr>
                    </thead>
                    <tbody id="detalle_compra_lineas">
                        <tr><td colspan="9" class="purchase-detail-empty">Cargando líneas…</td></tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" scope="row">Total</th>
                            <td id="detalle_compra_total_unidades">0</td>
                            <td colspan="3"></td>
                            <td id="detalle_compra_total_lineas">S/ 0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section class="purchase-detail-card purchase-detail-history">
            <div class="purchase-detail-card-heading">
                <h3>Historial y auditoría</h3>
            </div>
            <div id="detalle_compra_historial" class="purchase-detail-history-entry"></div>
        </section>
    </div>
</section>

<?php if ((int)$rol === 1): ?>
<dialog id="modal_anular_compra" class="purchase-action-dialog" aria-labelledby="modal_anular_compra_titulo">
    <form id="form_anular_compra" method="dialog">
        <h3 id="modal_anular_compra_titulo">Anular compra <span id="compra_documento_anulacion"></span></h3>
        <p class="purchase-cancellation-warning">
            Anula solo un registro que no debía existir. Si fue una devolución al proveedor o mercadería dañada,
            no uses esta opción. La compra no se borra: queda en el historial como anulada.
        </p>
        <section class="purchase-cancellation-stock" aria-labelledby="compra_efecto_inventario_titulo">
            <h4 id="compra_efecto_inventario_titulo">Efecto en el inventario</h4>
            <div class="purchase-detail-table-scroll">
                <table class="purchase-detail-table purchase-cancellation-table">
                    <thead>
                        <tr>
                            <th scope="col">Producto</th>
                            <th scope="col">A retirar</th>
                            <th scope="col">Saldo actual</th>
                            <th scope="col">Saldo después</th>
                        </tr>
                    </thead>
                    <tbody id="compra_efecto_inventario">
                        <tr><td colspan="4" class="purchase-detail-empty">Calculando efecto…</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
        <p id="compra_validacion_inventario" class="purchase-cancellation-result" role="status"></p>
        <label for="compra_motivo_anulacion">Motivo de la anulación <span aria-hidden="true">*</span></label>
        <textarea id="compra_motivo_anulacion" rows="4" maxlength="500" required
            placeholder="Explica por qué se anula esta compra"></textarea>
        <p class="purchase-cancellation-note">
            Se guardan el motivo, la fecha y el administrador responsable. La anulación se aplica una sola vez;
            las ventas ya registradas se conservan.
        </p>
        <p id="compra_error_anulacion" class="purchase-form-error" role="alert" hidden></p>
        <div class="purchase-action-dialog-buttons">
            <button type="button" id="compra_cancelar_anulacion" class="btn btn-secondary">Cancelar</button>
            <button type="submit" id="compra_confirmar_anulacion" class="btn btn-danger">
                <i class="fa-solid fa-ban" aria-hidden="true"></i> Confirmar anulación
            </button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php require_once "Views/Footer.php"; ?>
