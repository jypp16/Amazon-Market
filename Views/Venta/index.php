<?php require_once "Views/Header.php"; ?>

<div class="pos-app">
    <!-- CATALOGO -->
    <section class="pos-catalog">
        <div class="pos-toolbar">
            <div class="pos-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="pos_search" placeholder="Buscar por nombre o código..." autocomplete="off">
            </div>
            <div class="pos-cats-wrap">
                <button type="button" class="pos-cats-arrow pos-cats-prev" id="pos_cats_prev"><i class="fa-solid fa-chevron-left"></i></button>
                <div class="pos-cats" id="pos_cats"></div>
                <button type="button" class="pos-cats-arrow pos-cats-next" id="pos_cats_next"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
        <div class="pos-shelf" id="pos_shelf">
            <div class="pos-shelf-empty">
                <i class="fa-solid fa-box-open"></i>
                <p>Cargando productos...</p>
            </div>
        </div>
    </section>

    <!-- CARRITO -->
    <aside class="pos-cart" id="pos_cart">
        <div class="pos-cart-top">
            <div>
                <div class="pos-cart-title">
                    <i class="fa-solid fa-bag-shopping"></i>
                    <span>Carrito</span>
                    <span class="pos-cart-count" id="cart_count">0</span>
                </div>
                <div class="pos-cart-sub"><span id="cart_count_sub">0</span> artículos</div>
            </div>
            <button type="button" class="pos-cart-close" id="pos_cart_close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="pos-cart-body">
            <!-- Cliente -->
            <div class="pos-client" id="pos_client">
                <button type="button" class="pos-client-btn" id="pos_client_box">
                    <span class="pos-client-ico"><i class="fa-solid fa-user"></i></span>
                    <span class="pos-client-txt">
                        <small>Cliente</small>
                        <strong id="pos_client_name">Seleccionar cliente</strong>
                    </span>
                    <i class="fa-solid fa-chevron-down pos-client-chev"></i>
                </button>
                <div class="pos-client-sel" id="pos_client_sel">
                    <span class="pos-client-avatar" id="pos_client_avatar">--</span>
                    <span class="pos-client-meta">
                        <strong id="pos_client_name2">--</strong>
                        <small id="pos_client_doc">--</small>
                    </span>
                    <button type="button" class="pos-client-clear" id="pos_client_clear"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="pos-client-dd" id="pos_client_dd">
                    <div class="pos-dd-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="pos_client_search" placeholder="Buscar nombre o documento..." autocomplete="off">
                    </div>
                    <div class="pos-dd-list" id="pos_client_results"></div>
                    <button type="button" class="pos-dd-new" id="pos_client_new"><i class="fa-solid fa-user-plus"></i> Nuevo cliente</button>
                </div>
                <input type="hidden" id="id_cliente" value="">
            </div>

            <!-- Comprobante / Pago -->
            <div class="pos-paydoc" id="pos_paydoc">
                <button type="button" class="pos-paydoc-btn active" data-target="id_tipo_comprobante" data-key="boleta">Boleta</button>
                <button type="button" class="pos-paydoc-btn" data-target="id_tipo_comprobante" data-key="factura">Factura</button>
                <button type="button" class="pos-paydoc-btn active" data-target="id_metodo_pago" data-key="efectivo">Efectivo</button>
            </div>
            <div class="pos-paydoc-fields">
                <select id="id_tipo_comprobante"></select>
                <select id="id_metodo_pago"></select>
            </div>
            <small id="cliente-hint" class="pos-hint"><i class="fa-solid fa-circle-info"></i> Para factura elija cliente con RUC.</small>

            <!-- Lineas -->
            <div class="pos-lines" id="pos_lines">
                <div class="pos-lines-empty">
                    <i class="fa-solid fa-basket-shopping"></i>
                    <p>Carrito vacío</p>
                    <small>Toca un producto para agregarlo</small>
                </div>
            </div>

            <!-- Totales -->
            <div class="pos-totals">
                <div class="pos-total-taxes">
                    <div>Subtotal <b id="lbl_subtotal">S/. 0.00</b></div>
                    <div>IGV 18% <b id="lbl_igv">S/. 0.00</b></div>
                </div>
                <div class="pos-total-final">
                    <span>TOTAL</span>
                    <span id="lbl_total">S/. 0.00</span>
                </div>
            </div>

            <!-- Efectivo -->
            <div class="pos-cash">
                <div class="pos-cash-row">
                    <label class="pos-cash-label" for="monto_recibido"><i class="fa-solid fa-hand-holding-dollar"></i> Recibido</label>
                    <div class="pos-cash-in">
                        <span>S/.</span>
                        <input type="number" id="monto_recibido" placeholder="0.00" step="0.01" min="0" inputmode="decimal">
                    </div>
                    <button type="button" class="pos-cash-exact" data-cash="exact">Exacto</button>
                </div>
                <div class="pos-change" id="vuelto_box">
                    <span><i class="fa-solid fa-coins"></i> Vuelto</span>
                    <strong id="lbl_vuelto">S/. 0.00</strong>
                </div>
                <div class="pos-cash-chips" id="pos_cash_chips">
                    <button type="button" data-cash="10">S/10</button>
                    <button type="button" data-cash="20">S/20</button>
                    <button type="button" data-cash="50">S/50</button>
                    <button type="button" data-cash="100">S/100</button>
                </div>
            </div>
        </div>

        <div class="pos-cart-foot">
            <button type="button" class="pos-btn-ghost" id="btn_vaciar" disabled><i class="fa-solid fa-trash-can"></i> Vaciar</button>
            <button type="button" class="pos-btn-pay" id="btn_procesar"><i class="fa-solid fa-check"></i> Procesar</button>
        </div>
    </aside>

    <!-- FAB mobile -->
    <button type="button" class="pos-fab" id="pos_fab">
        <i class="fa-solid fa-bag-shopping"></i>
        <span class="pos-fab-badge" id="pos_fab_count">0</span>
        <span class="pos-fab-total" id="pos_fab_total">S/. 0.00</span>
    </button>
</div>

<!-- Modal nuevo cliente -->
<div id="modal_cliente" class="pos-modal" style="display:none;">
    <div class="pos-modal-card">
        <div class="pos-modal-head">
            <h3><i class="fa-solid fa-user-plus"></i> Nuevo cliente</h3>
            <button type="button" class="pos-modal-x" id="btn_cerrar_modal_cli"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="pos-modal-body">
            <div id="cli_form_error" class="pos-form-error" style="display:none;"></div>
            <div class="pos-form-grid">
                <div class="pos-field">
                    <label>Tipo documento</label>
                    <select id="nc_id_tipo_documento" class="pos-select"></select>
                </div>
                <div class="pos-field">
                    <label>N° documento</label>
                    <input type="text" id="nc_nro_documento" class="pos-input" placeholder="Número">
                </div>
                <div class="pos-field wide">
                    <label>Nombre / Razón social</label>
                    <input type="text" id="nc_nombre" class="pos-input" placeholder="Nombre completo">
                </div>
                <div class="pos-field">
                    <label>Teléfono</label>
                    <input type="text" id="nc_telefono" class="pos-input" placeholder="Opcional">
                </div>
                <div class="pos-field">
                    <label>Dirección</label>
                    <input type="text" id="nc_direccion" class="pos-input" placeholder="Opcional">
                </div>
            </div>
        </div>
        <div class="pos-modal-foot">
            <button type="button" class="pos-btn-ghost" id="btn_cancelar_cli">Cancelar</button>
            <button type="button" class="pos-btn-gold" id="btn_guardar_cli"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
        </div>
    </div>
</div>

<!-- Loader -->
<div id="loader_overlay" class="loader-overlay" style="display:none;">
    <div class="loader-content">
        <i class="fa-solid fa-circle-notch fa-spin fa-3x gold-text"></i>
        <p class="margin-top-15">Procesando venta...</p>
    </div>
</div>

<script>
document.body.classList.add('pos-mode');
var BaseUrl = "<?= BASE_URL ?>";
</script>

<?php require_once "Views/Footer.php"; ?>
