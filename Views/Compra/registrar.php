<?php require_once "Views/Header.php"; ?>

<?php $esCorreccion = !empty($data['id_compra_correccion']); ?>
<section class="purchase-register">
    <header class="purchase-register-heading">
        <nav class="purchase-breadcrumb" aria-label="Ruta de navegación">
            <a href="<?= BASE_URL ?>/Compra">Compras</a>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page"><?= $esCorreccion ? 'Corregir compra' : 'Registrar compra' ?></span>
        </nav>
        <br>
        <p><?= $esCorreccion
            ? 'Revisa y corrige el documento y sus líneas. El cambio de inventario y la auditoría se aplicarán al confirmar.'
            : 'Registra solo la mercadería que ya revisaste y aceptaste. El stock aumenta una sola vez, cuando confirmas.' ?></p>
    </header>

    <form id="form_registrar_compra" novalidate
        data-correction-id="<?= $esCorreccion ? (int)$data['id_compra_correccion'] : '' ?>">
        <section class="purchase-register-card">
            <h3>1. <?= $esCorreccion ? 'Documento corregido' : 'Documento de respaldo' ?></h3>
            <div class="purchase-document-grid">
                <div class="purchase-register-field purchase-field-provider">
                    <label for="compra_proveedor">Proveedor</label>
                    <select id="compra_proveedor" required>
                        <option value="">Selecciona un proveedor</option>
                        <?php foreach ($data['proveedores'] as $proveedor): ?>
                        <option value="<?= (int)$proveedor['id_proveedor'] ?>">
                            <?= e($proveedor['razon_social']) ?> — <?= e($proveedor['numero_documento']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <a class="purchase-inline-link" href="<?= BASE_URL ?>/Proveedor" target="_blank" rel="noopener">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir proveedores en otra pestaña (conserva esta compra)
                    </a>
                </div>
                <div class="purchase-register-field">
                    <label for="compra_tipo_comprobante">Tipo de documento</label>
                    <select id="compra_tipo_comprobante" required>
                        <option value="">Selecciona el tipo</option>
                        <?php foreach ($data['tipos_comprobante'] as $tipo): ?>
                        <option value="<?= (int)$tipo['id_tipo_comprobante'] ?>"><?= e($tipo['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="purchase-register-field">
                    <label for="compra_serie">Serie</label>
                    <input type="text" id="compra_serie" maxlength="10" required autocomplete="off" placeholder="F001">
                </div>
                <div class="purchase-register-field">
                    <label for="compra_numero">Número</label>
                    <input type="text" id="compra_numero" maxlength="20" required autocomplete="off" placeholder="00001234">
                    <small id="compra_referencia">Referencia: —</small>
                </div>
                <div class="purchase-register-field">
                    <label for="compra_fecha_emision">Fecha de emisión</label>
                    <input type="date" id="compra_fecha_emision" value="<?= e($data['fecha_hoy']) ?>" required>
                    <small>La que está escrita en el documento.</small>
                </div>
                <div class="purchase-register-field">
                    <label for="compra_fecha_recepcion">Fecha de recepción</label>
                    <input type="date" id="compra_fecha_recepcion" value="<?= e($data['fecha_hoy']) ?>" required>
                    <small>Cuándo llegó la mercadería. No puede ser futura.</small>
                </div>
                <div class="purchase-register-field">
                    <label for="compra_total_documento">Total final del documento (S/)</label>
                    <input type="number" id="compra_total_documento" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required>
                    <small>Importe final pagado, con impuestos y descuentos incluidos.</small>
                </div>
                <aside class="purchase-register-note">
                    <strong>Se completa solo al confirmar</strong>
                    <span>Fecha y hora de registro, administrador responsable y código interno de la compra.</span>
                </aside>
            </div>
        </section>

        <section class="purchase-register-card purchase-products-card">
            <div class="purchase-products-heading">
                <div>
                    <h3>2. Productos recibidos</h3>
                    <p>Si un mismo producto llegó con otro lote o vencimiento, añade otra línea. Todas se suman al mismo saldo del producto.</p>
                </div>
            </div>
            <div class="purchase-lines-scroll">
                <table class="purchase-lines-table">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Producto</th>
                            <th scope="col">Presentación</th>
                            <th scope="col">Cant.</th>
                            <th scope="col">Equiv.</th>
                            <th scope="col">Unid. base</th>
                            <th scope="col">Importe final (S/)</th>
                            <th scope="col">Costo x pres.</th>
                            <th scope="col">Lote</th>
                            <th scope="col">Vencimiento</th>
                            <th scope="col"><span class="visually-hidden">Quitar línea</span></th>
                        </tr>
                    </thead>
                    <tbody id="compra_lineas">
                        <tr class="purchase-lines-empty">
                            <td colspan="11">Añade una línea para comenzar.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="purchase-lines-footer">
                <button type="button" id="compra_agregar_linea" class="btn btn-secondary">
                    <i class="fa-solid fa-plus"></i> Añadir línea
                </button>
                <small>Lote: el código impreso en el empaque del fabricante, no el número de factura. Si el producto no vence, registra “No aplica”.</small>
            </footer>
        </section>

        <?php if ($esCorreccion): ?>
        <section class="purchase-register-card purchase-correction-reason">
            <label for="compra_motivo_correccion">Motivo de la corrección <span aria-hidden="true">*</span></label>
            <textarea id="compra_motivo_correccion" rows="3" maxlength="500" required
                placeholder="Indica qué dato se corrige y por qué"></textarea>
        </section>
        <?php endif; ?>

        <div id="compra_error" class="purchase-form-error" role="alert" hidden></div>

        <div class="purchase-review-grid">
            <section class="purchase-register-card purchase-review-card">
                <h3>3. Revisión antes de confirmar</h3>
                <dl>
                    <div><dt>Líneas de la compra</dt><dd id="compra_resumen_lineas">0</dd></div>
                    <div><dt>Unidades que ingresarán al inventario</dt><dd id="compra_resumen_unidades">0</dd></div>
                    <div><dt>Suma del detalle</dt><dd id="compra_resumen_detalle">S/ 0.00</dd></div>
                    <div><dt>Total final del documento</dt><dd id="compra_resumen_documento">S/ 0.00</dd></div>
                    <div class="purchase-review-difference"><dt>Diferencia</dt><dd id="compra_resumen_diferencia">S/ 0.00</dd></div>
                </dl>
                <p>El stock todavía no cambia. Solo aumenta al confirmar la compra.</p>
            </section>

            <section class="purchase-register-card purchase-confirm-card">
                <h3><?= $esCorreccion ? 'Para aplicar la corrección' : 'Para poder confirmar' ?></h3>
                <div id="compra_validaciones" class="purchase-validation-list">
                    Completa el documento y agrega al menos una línea válida.
                </div>
                <p><?= $esCorreccion
                    ? 'Al confirmar se actualiza la compra y se registra el efecto de inventario y la auditoría. Si algo falla, no se aplica ningún cambio.'
                    : 'Al confirmar se guardan juntos la compra, el aumento de stock y el registro en el Kardex. Si algo falla, no se guarda nada y puedes corregirlo.' ?></p>
            </section>
        </div>
        <div class="purchase-confirm-actions">
            <button type="submit" id="compra_confirmar" class="btn btn-gold" disabled>
                <i class="fa-solid fa-circle-check"></i> <?= $esCorreccion ? 'Guardar corrección' : 'Confirmar compra' ?>
            </button>
            <a href="<?= $esCorreccion
                ? BASE_URL . '/Compra/detalle/' . (int)$data['id_compra_correccion']
                : BASE_URL . '/Compra' ?>" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</section>

<script>
window.COMPRA_CATALOGOS = <?= json_encode([
    'productos' => $data['productos'],
    'presentaciones' => $data['presentaciones']
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>

<?php require_once "Views/Footer.php"; ?>
