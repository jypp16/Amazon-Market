let carrito = [];
let productosLista = [];
let clientesLista = [];
let tiposDocumentoLista = [];
let categoriasLista = [];
let chipCategoriaActiva = '';
let clienteSeleccionado = null;
const LIMITE_CONSUMIDOR_FINAL = 700;
const CONSUMIDOR_FINAL = {
    id_cliente: null,
    nombre: 'Consumidor final',
    tipo_documento: '',
    nro_documento: '',
    es_consumidor_final: true
};
let paydocMap = { 'id_tipo_comprobante': {}, 'id_metodo_pago': {} };
let pagosLista = [];
let metodoSeleccionado = '';
let metodosNoDisponibles = {};
let ventaEnProceso = false;
let escaneoEnCurso = false;
let colaEscaneo = [];
let fallosEscaneoSimulados = 0;

document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('pos_shelf')) return;

    Promise.all([
        cargarClientes(),
        cargarComprobantes(),
        cargarPagos(),
        cargarCategorias(),
        cargarTiposDocumento(),
        cargarProductos()
    ]).then(function() {
        initPos();
    });
});

function apiData(r) {
    if (!r) return [];
    if (!r.ok) {
        var msg = (r.data && r.data.message) || 'Error al cargar datos del servidor.';
        console.error('apiData:', msg);
        Modal.error('Error de carga', msg);
        return [];
    }
    return r.data.data || [];
}

async function cargarClientes() {
    try { clientesLista = apiData(await Api.get('clientes')); } catch(e) { console.error('clientes:', e); }
}
async function cargarComprobantes() {
    try {
        var list = apiData(await Api.get('comprobantes'));
        fillSelect('id_tipo_comprobante', list, 'id_tipo_comprobante', 'nombre');
        list.forEach(function(c) {
            var name = (c.nombre || '').toLowerCase();
            if (name.includes('boleta')) paydocMap['id_tipo_comprobante']['boleta'] = String(c.id_tipo_comprobante);
            if (name.includes('factura')) paydocMap['id_tipo_comprobante']['factura'] = String(c.id_tipo_comprobante);
        });
    } catch(e) { console.error(e); }
}
async function cargarPagos() {
    try {
        pagosLista = apiData(await Api.get('pagos'));
        fillSelect('id_metodo_pago', pagosLista, 'id_metodo_pago', 'nombre');
        renderMetodosPago(true);
    } catch(e) { console.error(e); }
}
async function cargarCategorias() {
    try { categoriasLista = apiData(await Api.get('categorias')); renderChips(); }
    catch(e) { console.error(e); }
}
async function cargarTiposDocumento() {
    try {
        tiposDocumentoLista = apiData(await Api.get('tipos-documento'));
        fillSelect('nc_id_tipo_documento', tiposDocumentoLista, 'id_tipo_documento', 'nombre');
    } catch(e) { console.error(e); }
}
async function cargarProductos() {
    try { productosLista = apiData(await Api.get('productos?limit=500')); renderProductos(''); }
    catch(e) { console.error(e); }
}

function fillSelect(id, list, valKey, labelKey) {
    var sel = document.getElementById(id);
    if (!sel) return;
    sel.textContent = '';
    list.forEach(function(item) { sel.appendChild(new Option(item[labelKey], item[valKey])); });
}

function initPos() {
    var search = document.getElementById('pos_search');
    search.addEventListener('input', debounce(function() { renderProductos(search.value.trim()); }, 200));

    search.addEventListener('input', function() { marcarEntradaEscaneo(false); });
    search.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            manejarEntradaEscaneo(search.value.trim());
        } else if (e.key === 'Escape') {
            search.value = '';
            ocultarEstadoEscaneo();
            renderProductos('');
        }
    });

    document.getElementById('pos_client_box').addEventListener('click', toggleClientDD);
    document.getElementById('pos_client_cf').addEventListener('click', function() { selectClient(CONSUMIDOR_FINAL); });
    document.getElementById('pos_client_clear').addEventListener('click', clearClient);
    document.getElementById('pos_client_search').addEventListener('input', debounce(function() {
        renderClientResults(document.getElementById('pos_client_search').value.trim());
    }, 200));
    document.getElementById('pos_client_search').addEventListener('focus', function() {
        renderClientResults(document.getElementById('pos_client_search').value.trim());
    });
    document.getElementById('pos_client_new').addEventListener('click', function() { closeClientDD(); abrirModalCliente(''); });

    document.addEventListener('click', function(e) {
        var root = document.getElementById('pos_client');
        if (root && !root.contains(e.target)) closeClientDD();
    });

    document.getElementById('btn_vaciar').addEventListener('click', vaciarCarrito);
    document.getElementById('btn_procesar').addEventListener('click', procesarVenta);
    document.getElementById('monto_recibido').addEventListener('input', calcularVuelto);

    document.querySelectorAll('#pos_cash_chips button, .pos-cash-exact').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var v = btn.getAttribute('data-cash');
            var inp = document.getElementById('monto_recibido');
            if (v === 'exact') {
                inp.value = totalCarrito().toFixed(2);
            } else {
                var cur = parseFloat(inp.value) || 0;
                inp.value = (cur + parseFloat(v)).toFixed(2);
            }
            calcularVuelto();
            inp.focus();
        });
    });

    document.getElementById('btn_cerrar_modal_cli').addEventListener('click', cerrarModalCliente);
    document.getElementById('btn_cancelar_cli').addEventListener('click', cerrarModalCliente);
    document.getElementById('btn_guardar_cli').addEventListener('click', guardarNuevoCliente);
    document.getElementById('modal_cliente').addEventListener('click', function(e) { if (e.target === this) cerrarModalCliente(); });

    initPaydoc();

    document.getElementById('pos_fab').addEventListener('click', openCartMobile);
    document.getElementById('pos_cart_close').addEventListener('click', closeCartMobile);

    initCatArrows();
    search.focus();
}

function initCatArrows() {
    var cats = document.getElementById('pos_cats');
    var prev = document.getElementById('pos_cats_prev');
    var next = document.getElementById('pos_cats_next');
    if (!cats || !prev || !next) return;

    function updateArrows() {
        var canScrollLeft = cats.scrollLeft > 1;
        var canScrollRight = cats.scrollLeft < (cats.scrollWidth - cats.clientWidth - 1);
        prev.classList.toggle('show', canScrollLeft);
        next.classList.toggle('show', canScrollRight);
    }

    prev.addEventListener('click', function() { cats.scrollBy({ left: -200, behavior: 'smooth' }); });
    next.addEventListener('click', function() { cats.scrollBy({ left: 200, behavior: 'smooth' }); });
    cats.addEventListener('scroll', updateArrows);
    window.addEventListener('resize', debounce(updateArrows, 100));
    updateArrows();
}

function renderChips() {
    var wrap = document.getElementById('pos_cats');
    wrap.textContent = '';
    var all = document.createElement('button');
    all.type = 'button';
    all.className = 'pos-cat active';
    all.innerHTML = '<i class="fa-solid fa-layer-group"></i> Todas';
    all.addEventListener('click', function() { setChip('', all); });
    wrap.appendChild(all);
    categoriasLista.forEach(function(c) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pos-cat';
        b.textContent = c.nombre;
        b.addEventListener('click', function() { setChip(String(c.id_categoria), b); });
        wrap.appendChild(b);
    });
}

function setChip(id, btn) {
    chipCategoriaActiva = id;
    document.querySelectorAll('.pos-cat').forEach(function(c) { c.classList.remove('active'); });
    btn.classList.add('active');
    renderProductos(document.getElementById('pos_search').value.trim());
}

function renderProductos(query) {
    var grid = document.getElementById('pos_shelf');
    var lista = productosLista.slice();

    if (chipCategoriaActiva) {
        lista = lista.filter(function(p) { return String(p.id_categoria || p.categoria_id || '') === chipCategoriaActiva; });
    }
    if (query) {
        var q = query.toLowerCase();
        lista = lista.filter(function(p) {
            return (p.nombre || '').toLowerCase().includes(q) || (p.codigo_barra || '').toLowerCase().includes(q);
        });
    }

    grid.textContent = '';
    if (!lista.length) {
        grid.innerHTML = '<div class="pos-shelf-empty"><i class="fa-solid fa-magnifying-glass"></i><p>Sin resultados</p></div>';
        return;
    }

    lista.forEach(function(p) {
        var stock = parseInt(p.stock_actual, 10) || 0;
        var minStock = parseInt(p.stock_minimo, 10) || 0;
        var agotado = stock <= 0;
        var bajo = stock <= minStock;
        var safeCode = String(p.codigo_barra || '').replace(/[^a-zA-Z0-9_\-]/g, '_');

        var card = document.createElement('div');
        card.className = 'pos-item' + (agotado ? ' agotado' : '');

        var img = document.createElement('div');
        img.className = 'pos-item-img';
        var pic = document.createElement('img');
        pic.alt = '';
        pic.loading = 'lazy';
        pic.src = BaseUrl + '/Assets/img/productos/' + encodeURIComponent(safeCode) + '.jpg';
        pic.addEventListener('error', function fallback() {
            if (!pic.dataset.t) { pic.dataset.t = '1'; pic.src = BaseUrl + '/Assets/img/productos/' + encodeURIComponent(safeCode) + '.png'; }
            else if (pic.dataset.t === '1') { pic.dataset.t = '2'; pic.src = BaseUrl + '/Assets/img/productos/' + encodeURIComponent(safeCode) + '.webp'; }
            else { pic.remove(); img.classList.add('no-img'); }
        });
        img.appendChild(pic);

        if (agotado) {
            var badge = document.createElement('span');
            badge.className = 'pos-item-stock out';
            badge.textContent = 'Agotado';
            img.appendChild(badge);
        } else if (bajo) {
            var bl = document.createElement('span');
            bl.className = 'pos-item-stock low';
            bl.textContent = 'Bajo';
            img.appendChild(bl);
        }

        var body = document.createElement('div');
        body.className = 'pos-item-body';
        body.innerHTML =
            '<div class="pos-item-name">' + esc(p.nombre) + '</div>' +
            '<div class="pos-item-meta">Stock ' + stock + ' ' + esc(p.unidad || '') + '</div>' +
            '<div class="pos-item-price">S/. ' + (parseFloat(p.precio_venta) || 0).toFixed(2) + '</div>';

        var add = document.createElement('button');
        add.type = 'button';
        add.className = 'pos-item-add';
        add.innerHTML = '<i class="fa-solid fa-plus"></i>';
        add.addEventListener('click', function(e) { e.stopPropagation(); agregarProducto(p); });

        card.appendChild(img);
        card.appendChild(body);
        card.appendChild(add);
        if (!agotado) card.addEventListener('click', function() { agregarProducto(p); });
        grid.appendChild(card);
    });
}

// Agrega una unidad al carrito sin mostrar diálogos; la usan el clic en el catálogo y el escaneo.
function intentarAgregar(p) {
    var stock = parseInt(p.stock_actual, 10) || 0;
    if (stock <= 0) return { ok: false, motivo: 'sin_stock', stock: 0 };
    var idx = carrito.findIndex(function(i) { return i.id_producto == p.id_producto; });
    var resultado;
    if (idx >= 0) {
        if (carrito[idx].cantidad + 1 > stock) return { ok: false, motivo: 'stock_insuficiente', stock: stock };
        carrito[idx].cantidad += 1;
        carrito[idx].subtotal = carrito[idx].cantidad * carrito[idx].precio;
        resultado = { ok: true, nueva: false, cantidad: carrito[idx].cantidad };
    } else {
        var precio = parseFloat(p.precio_venta) || 0;
        carrito.push({ id_producto: p.id_producto, nombre: p.nombre, unidad: p.unidad || '', precio: precio, cantidad: 1, subtotal: precio });
        resultado = { ok: true, nueva: true, cantidad: 1 };
    }
    renderCarrito();
    return resultado;
}

function agregarProducto(p) {
    var r = intentarAgregar(p);
    if (r.motivo === 'sin_stock') Modal.warning('Sin stock', 'No hay stock de "' + p.nombre + '".');
    else if (r.motivo === 'stock_insuficiente') Modal.warning('Stock insuficiente', 'Solo quedan ' + r.stock);
}

function setQty(i, val) {
    var c = parseInt(val, 10);
    if (isNaN(c) || c <= 0) { removeItem(i); return; }
    var item = carrito[i];
    var prod = productosLista.find(function(p) { return p.id_producto == item.id_producto; });
    var stock = prod ? (parseInt(prod.stock_actual, 10) || 0) : item.cantidad;
    if (c > stock) { Modal.warning('Stock insuficiente', 'Stock disponible: ' + stock); renderCarrito(); return; }
    item.cantidad = c;
    item.subtotal = c * item.precio;
    renderCarrito();
}

function removeItem(i) { carrito.splice(i, 1); renderCarrito(); }

function totalCarrito() { return carrito.reduce(function(t, i) { return t + i.subtotal; }, 0); }

function renderCarrito() {
    var wrap = document.getElementById('pos_lines');
    var cnt = document.getElementById('cart_count');
    var cntSub = document.getElementById('cart_count_sub');
    var fabCnt = document.getElementById('pos_fab_count');
    var fabTot = document.getElementById('pos_fab_total');
    var btnV = document.getElementById('btn_vaciar');
    var nItems = carrito.reduce(function(t, i) { return t + i.cantidad; }, 0);
    var total = totalCarrito();

    cnt.textContent = nItems;
    if (cntSub) cntSub.textContent = nItems;
    if (fabCnt) fabCnt.textContent = nItems;
    if (fabTot) fabTot.textContent = 'S/. ' + total.toFixed(2);
    btnV.disabled = !carrito.length;

    wrap.textContent = '';
    if (!carrito.length) {
        wrap.innerHTML = '<div class="pos-lines-empty"><i class="fa-solid fa-basket-shopping"></i><p>Carrito vacío</p><small>Toca un producto para agregarlo</small></div>';
        actualizarTotales();
        return;
    }

    carrito.forEach(function(item, idx) {
        var prodRef = productosLista.find(function(p) { return p.id_producto == item.id_producto; });
        var code = prodRef ? String(prodRef.codigo_barra || '').replace(/[^a-zA-Z0-9_\-]/g, '_') : '';

        var line = document.createElement('div');
        line.className = 'pos-line';

        var thumb = document.createElement('div');
        thumb.className = 'pos-line-thumb';
        thumb.style.background = gradStr(item.nombre);

        var letter = document.createElement('span');
        letter.className = 'pos-line-letter';
        letter.textContent = (item.nombre || '?').charAt(0).toUpperCase();
        thumb.appendChild(letter);

        if (code) {
            var ti = document.createElement('img');
            ti.alt = '';
            ti.src = BaseUrl + '/Assets/img/productos/' + encodeURIComponent(code) + '.jpg';
            ti.addEventListener('load', function() { letter.style.display = 'none'; });
            ti.addEventListener('error', function() {
                if (!ti.dataset.t) { ti.dataset.t = '1'; ti.src = BaseUrl + '/Assets/img/productos/' + encodeURIComponent(code) + '.png'; }
                else if (ti.dataset.t === '1') { ti.dataset.t = '2'; ti.src = BaseUrl + '/Assets/img/productos/' + encodeURIComponent(code) + '.webp'; }
                else { ti.remove(); letter.style.display = ''; }
            });
            thumb.appendChild(ti);
        }

        var info = document.createElement('div');
        info.className = 'pos-line-info';
        info.innerHTML = '<div class="pos-line-name">' + esc(item.nombre) + '</div>' +
            '<div class="pos-line-sub">S/. ' + item.precio.toFixed(2) + ' / ' + esc(item.unidad) + '</div>';

        var step = document.createElement('div');
        step.className = 'pos-stepper';
        var minus = document.createElement('button');
        minus.type = 'button';
        minus.innerHTML = '<i class="fa-solid fa-minus"></i>';
        minus.addEventListener('click', function() { setQty(idx, item.cantidad - 1); });
        var inp = document.createElement('input');
        inp.type = 'number';
        inp.value = item.cantidad;
        inp.min = '1';
        inp.step = '1';
        inp.addEventListener('change', function() { setQty(idx, inp.value); });
        var plus = document.createElement('button');
        plus.type = 'button';
        plus.innerHTML = '<i class="fa-solid fa-plus"></i>';
        plus.addEventListener('click', function() { setQty(idx, item.cantidad + 1); });
        step.appendChild(minus);
        step.appendChild(inp);
        step.appendChild(plus);

        var tot = document.createElement('div');
        tot.className = 'pos-line-total';
        tot.textContent = 'S/. ' + item.subtotal.toFixed(2);

        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'pos-line-rm';
        rm.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        rm.addEventListener('click', function() { removeItem(idx); });

        line.appendChild(thumb);
        line.appendChild(info);
        var ctrls = document.createElement('div');
        ctrls.className = 'pos-line-ctrls';
        ctrls.appendChild(step);
        ctrls.appendChild(tot);
        ctrls.appendChild(rm);
        line.appendChild(ctrls);
        wrap.appendChild(line);
    });
    actualizarTotales();
}

function actualizarTotales() {
    var total = totalCarrito();
    var sub = total / 1.18;
    document.getElementById('lbl_subtotal').textContent = 'S/. ' + sub.toFixed(2);
    document.getElementById('lbl_igv').textContent = 'S/. ' + (total - sub).toFixed(2);
    document.getElementById('lbl_total').textContent = 'S/. ' + total.toFixed(2);
    calcularVuelto();
    actualizarEstadoPago();
}

function calcularVuelto() {
    var total = totalCarrito();
    var recv = parseFloat(document.getElementById('monto_recibido').value) || 0;
    var box = document.getElementById('vuelto_box');
    var lbl = document.getElementById('lbl_vuelto');
    var icon = box.querySelector('span');
    var diff = recv - total;
    box.classList.remove('faltante');
    if (recv <= 0 && total > 0) { lbl.textContent = 'S/. 0.00'; icon.innerHTML = '<i class="fa-solid fa-coins"></i> Vuelto'; return; }
    if (diff >= 0) {
        lbl.textContent = 'S/. ' + diff.toFixed(2);
        icon.innerHTML = '<i class="fa-solid fa-coins"></i> Vuelto';
    } else {
        lbl.textContent = 'S/. ' + Math.abs(diff).toFixed(2);
        box.classList.add('faltante');
        icon.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Faltante';
    }
}

function vaciarCarrito() {
    if (!carrito.length) return;
    Modal.confirm('Vaciar carrito', '¿Eliminar todos los productos?', 'warning').then(function(ok) {
        if (ok) { carrito = []; renderCarrito(); }
    });
}

function toggleClientDD() {
    var dd = document.getElementById('pos_client_dd');
    dd.classList.toggle('open');
    if (dd.classList.contains('open')) {
        var s = document.getElementById('pos_client_search');
        s.value = '';
        renderClientResults('');
        setTimeout(function() { s.focus(); }, 50);
    }
}

function closeClientDD() { document.getElementById('pos_client_dd').classList.remove('open'); }

function renderClientResults(query) {
    var dd = document.getElementById('pos_client_results');
    dd.textContent = '';
    var q = (query || '').toLowerCase();
    var items = q ? clientesLista.filter(function(c) {
        return (c.nombre || '').toLowerCase().includes(q) || (c.nro_documento || '').toLowerCase().includes(q);
    }).slice(0, 12) : clientesLista.slice(0, 12);

    var cf = document.getElementById('pos_client_cf');
    var sec = document.getElementById('pos_client_section');
    if (cf) cf.style.display = (!q || 'consumidor final'.includes(q)) ? '' : 'none';
    if (sec) sec.style.display = items.length ? '' : 'none';

    items.forEach(function(c) {
        var el = document.createElement('div');
        el.className = 'pos-dd-item';
        el.innerHTML =
            '<span class="pos-dd-ico"><i class="fa-solid fa-user"></i></span>' +
            '<span class="pos-dd-info"><strong>' + esc(c.nombre) + '</strong>' +
            '<small>' + esc(c.tipo_documento || 'Doc') + ': ' + esc(c.nro_documento || '—') + '</small></span>';
        el.addEventListener('click', function() { selectClient(c); });
        dd.appendChild(el);
    });

    if (q && !items.length && cf && cf.style.display === 'none') {
        dd.innerHTML = '<div class="pos-dd-empty"><i class="fa-solid fa-user-slash"></i> Sin coincidencias</div>';
    }
}

function esConsumidorFinal() {
    return !!(clienteSeleccionado && clienteSeleccionado.es_consumidor_final);
}

function aplicarRestriccionConsumidorFinal() {
    var boletaBtn = document.querySelector('.pos-paydoc-btn[data-target="id_tipo_comprobante"][data-key="boleta"]');
    var facturaBtn = document.querySelector('.pos-paydoc-btn[data-target="id_tipo_comprobante"][data-key="factura"]');
    var cf = esConsumidorFinal();
    if (facturaBtn) {
        facturaBtn.disabled = cf;
        facturaBtn.title = cf ? 'Consumidor final solo admite boleta' : '';
        if (cf && facturaBtn.classList.contains('active') && boletaBtn) boletaBtn.click();
    }
}

function selectClient(c) {
    clienteSeleccionado = c;
    document.getElementById('id_cliente').value = c.id_cliente == null ? '' : c.id_cliente;
    document.getElementById('pos_client_sel').classList.add('active');
    document.getElementById('pos_client_box').classList.add('hidden');
    document.getElementById('pos_client_avatar').textContent = c.es_consumidor_final ? 'CF' : (c.nombre || '?').charAt(0).toUpperCase();
    document.getElementById('pos_client_name2').textContent = c.nombre;
    document.getElementById('pos_client_doc').textContent = c.es_consumidor_final
        ? 'Venta sin datos del comprador'
        : (c.tipo_documento || 'Doc') + ': ' + (c.nro_documento || '—');
    closeClientDD();
    aplicarRestriccionConsumidorFinal();
    validarFacturaRUC();
}

function clearClient() {
    clienteSeleccionado = null;
    document.getElementById('id_cliente').value = '';
    document.getElementById('pos_client_sel').classList.remove('active');
    document.getElementById('pos_client_box').classList.remove('hidden');
    aplicarRestriccionConsumidorFinal();
    validarFacturaRUC();
}

function initPaydoc() {
    var wrap = document.getElementById('pos_paydoc');
    if (!wrap) return;

    wrap.querySelectorAll('.pos-paydoc-btn.active').forEach(function(btn) {
        var target = btn.getAttribute('data-target');
        var key = btn.getAttribute('data-key');
        var sel = document.getElementById(target);
        if (sel && paydocMap[target][key]) sel.value = paydocMap[target][key];
    });

    wrap.addEventListener('click', function(e) {
        var btn = e.target.closest('.pos-paydoc-btn');
        if (!btn) return;
        var target = btn.getAttribute('data-target');
        var key = btn.getAttribute('data-key');
        var val = paydocMap[target][key];
        if (!val) return;

        var sel = document.getElementById(target);
        if (sel) sel.value = val;

        if (target === 'id_tipo_comprobante') {
            wrap.querySelectorAll('.pos-paydoc-btn[data-target="id_tipo_comprobante"]').forEach(function(b) { b.classList.remove('active'); });
        }
        btn.classList.add('active');
        validarFacturaRUC();
    });

    validarFacturaRUC();
}

function validarFacturaRUC() {
    var facturaBtn = document.querySelector('.pos-paydoc-btn[data-target="id_tipo_comprobante"][data-key="factura"]');
    var hint = document.getElementById('cliente-hint');
    if (!hint) return;
    var esFactura = facturaBtn && facturaBtn.classList.contains('active');
    hint.style.display = esFactura ? 'flex' : 'none';
}

function openCartMobile() { document.getElementById('pos_cart').classList.add('open'); }
function closeCartMobile() { document.getElementById('pos_cart').classList.remove('open'); }

function abrirModalCliente(nombre) {
    document.getElementById('modal_cliente').style.display = 'flex';
    if (nombre) document.getElementById('nc_nombre').value = nombre;
    (nombre ? document.getElementById('nc_nro_documento') : document.getElementById('nc_nombre')).focus();
}

function cerrarModalCliente() {
    document.getElementById('modal_cliente').style.display = 'none';
    document.getElementById('cli_form_error').style.display = 'none';
    ['nc_nro_documento','nc_nombre','nc_telefono','nc_direccion'].forEach(function(id) { document.getElementById(id).value = ''; });
}

async function guardarNuevoCliente() {
    var err = document.getElementById('cli_form_error');
    err.style.display = 'none';
    var payload = {
        id_tipo_documento: parseInt(document.getElementById('nc_id_tipo_documento').value, 10) || 0,
        nro_documento: document.getElementById('nc_nro_documento').value.trim(),
        nombre: document.getElementById('nc_nombre').value.trim(),
        telefono: document.getElementById('nc_telefono').value.trim(),
        direccion: document.getElementById('nc_direccion').value.trim()
    };
    if (!payload.id_tipo_documento || !payload.nro_documento || !payload.nombre) {
        err.textContent = 'Complete: tipo documento, número y nombre.';
        err.style.display = 'block';
        return;
    }
    try {
        var r = await Api.post('clientes', payload);
        if (!r || !r.ok) {
            err.textContent = (r && r.data && r.data.message) || 'No se pudo registrar.';
            err.style.display = 'block';
            return;
        }
        await cargarClientes();
        var tdId = parseInt(document.getElementById('nc_id_tipo_documento').value, 10) || 0;
        var td = tiposDocumentoLista.find(function(t) { return String(t.id_tipo_documento) === String(tdId); });
        selectClient({
            id_cliente: r.data.data.id_cliente,
            nombre: payload.nombre,
            nro_documento: payload.nro_documento,
            id_tipo_documento: tdId,
            tipo_documento: td ? td.nombre : ''
        });
        cerrarModalCliente();
        Modal.success('Cliente registrado', 'Se agregó correctamente.');
    } catch(e) {
        err.textContent = 'Error: ' + e.message;
        err.style.display = 'block';
    }
}

async function procesarVenta() {
    var btn = document.getElementById('btn_procesar');
    if (btn.disabled) return;
    var id_cliente = document.getElementById('id_cliente').value;
    var id_tc = document.getElementById('id_tipo_comprobante').value;
    var id_mp = metodoSeleccionado;
    if (!id_cliente && !esConsumidorFinal()) { Modal.warning('Falta cliente', 'Seleccione o registre un cliente.'); return; }
    if (!id_tc) { Modal.warning('Falta comprobante', 'Seleccione un tipo de comprobante.'); return; }
        if (!id_mp) { actualizarEstadoPago(); return; }
    if (!carrito.length) { Modal.warning('Carrito vacío', 'Agregue al menos un producto.'); return; }
    if (esConsumidorFinal() && totalCarrito() > LIMITE_CONSUMIDOR_FINAL) {
        Modal.warning('Supera el límite', 'Consumidor final solo admite ventas hasta S/. 700.00. Seleccione un cliente registrado.');
        return;
    }
    // El servidor vuelve a validar el método al confirmar; esta consulta evita enviar uno ya desactivado.
    if (!(await verificarMetodoDisponible(id_mp))) { marcarMetodoNoDisponible(id_mp); return; }

    var ok = await Modal.confirm('Procesar venta', '¿Confirmar venta por S/. ' + totalCarrito().toFixed(2) + '?', 'warning');
    if (!ok || btn.disabled) return;

    document.getElementById('loader_overlay').style.display = 'flex';
    ventaEnProceso = true;
    btn.disabled = true;
    try {
        var payload = {
            id_cliente: parseInt(id_cliente, 10),
            id_tipo_comprobante: parseInt(id_tc, 10),
            id_metodo_pago: parseInt(id_mp, 10),
            productos: carrito.map(function(i) { return { id_cliente: esConsumidorFinal() ? null : parseInt(id_cliente, 10),consumidor_final: esConsumidorFinal(), cantidad: parseInt(i.cantidad, 10) }; })
        };
        payload.referencia_solicitud = await Api.referenciaSolicitud('venta-procesar', payload);
        var res = await Api.post('ventas', payload);
        document.getElementById('loader_overlay').style.display = 'none';
        if (res && res.ok && res.data.status) {
            var d = res.data.data;
            // La operación ya está confirmada: liberar el carrito antes de
            // imprimir o recargar catálogos (esos pasos también pueden fallar).
            var itemsConfirmados = carrito;
            carrito = [];
            Api.confirmarSolicitud('venta-procesar');
            renderCarrito();
            var tipoComprobante = document.getElementById('id_tipo_comprobante');
            var tipoNombre = tipoComprobante.options[tipoComprobante.selectedIndex] ? tipoComprobante.options[tipoComprobante.selectedIndex].text : 'Boleta';
            var metodoPago = document.getElementById('id_metodo_pago');
            var mpNombre = metodoPago.options[metodoPago.selectedIndex] ? metodoPago.options[metodoPago.selectedIndex].text : '';
            var clienteNombre = clienteSeleccionado ? clienteSeleccionado.nombre : 'Cliente general';
            var datosTicket = { serie: d.serie, numero: d.numero, total: d.total, tipo: tipoNombre };

            // Dejar la pantalla lista antes de avisar al vendedor.
            clearClient();
            document.getElementById('monto_recibido').value = '';
            calcularVuelto();
            await cargarProductos();

            await Modal.success('Venta registrada',
                'Serie: ' + d.serie + '\nNúmero: ' + d.numero + '\nTotal: S/. ' + parseFloat(d.total).toFixed(2));

            // El ticket se abre después de pulsar Aceptar: así el diálogo de impresión no bloquea el modal.
            try {
                imprimirTicket(datosTicket, itemsConfirmados, clienteNombre, mpNombre);
            } catch (e) {
                console.error('No se pudo abrir el ticket:', e);
            }
            document.getElementById('pos_search').focus();
        } else {
            var errCode = res && res.data ? res.data.code : '';
            var errCampos = res && res.data && res.data.errors ? res.data.errors : {};
            if (errCampos.id_metodo_pago) {
                // Método desactivado o inexistente: se conservan los productos y se pide elegir otro.
                marcarMetodoNoDisponible(id_mp);
                return;
            }
            var errMsg = (res && res.data && res.data.message) || 'Error desconocido.';
            if (errCode === 'CASH_SESSION_CLOSED' || errCode === 'NO_OPEN_SESSION') {
                await Modal.error('Caja cerrada o sin sesión abierta', errMsg + '\n\nVe a Caja → Abrir caja para iniciar tu turno antes de vender.');
            } else {
                await Modal.error('Error', errMsg);
            }
        }
    } catch(e) {
        document.getElementById('loader_overlay').style.display = 'none';
        await Modal.error('Error', 'No se pudo procesar: ' + e.message);
    } finally {
        ventaEnProceso = false;
        actualizarEstadoPago();
    }
}

/* ---------- Método de pago: un único método por venta (HU-26) ---------- */
function claseMetodo(nombre) {
    var n = String(nombre || '').toLowerCase();
    if (n.includes('efectivo')) return 'efectivo';
    if (n.includes('tarjeta')) return 'tarjeta';
    if (n.includes('yape') || n.includes('plin')) return 'yape';
    return 'otro';
}

function iconoMetodo(nombre) {
    var c = claseMetodo(nombre);
    if (c === 'efectivo') return 'fa-money-bill-wave';
    if (c === 'tarjeta') return 'fa-credit-card';
    if (c === 'yape') return 'fa-mobile-screen-button';
    return 'fa-wallet';
}

function metodoPorId(id) {
    return pagosLista.find(function(p) { return String(p.id_metodo_pago) === String(id); }) || null;
}

function metodoEsEfectivo(id) {
    var m = metodoPorId(id);
    return !!m && claseMetodo(m.nombre) === 'efectivo';
}

function renderMetodosPago(preseleccionar) {
    var cont = document.getElementById('pos_pay_methods');
    if (!cont) return;
    if (preseleccionar && !metodoSeleccionado) {
        var ef = pagosLista.find(function(p) { return claseMetodo(p.nombre) === 'efectivo'; });
        metodoSeleccionado = ef ? String(ef.id_metodo_pago) : '';
    }
    var sel = document.getElementById('id_metodo_pago');
    if (sel) sel.value = metodoSeleccionado;

    cont.textContent = '';
    pagosLista.forEach(function(p) {
        var id = String(p.id_metodo_pago);
        var off = !!metodosNoDisponibles[id];
        var activo = !off && id === metodoSeleccionado;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pos-pay-btn' + (activo ? ' active' : '') + (off ? ' is-off' : '');
        b.setAttribute('data-id', id);
        b.setAttribute('aria-pressed', activo ? 'true' : 'false');
        if (off) { b.disabled = true; b.title = 'Este método ya no está disponible'; }
        var ico = document.createElement('i');
        ico.className = 'fa-solid ' + iconoMetodo(p.nombre);
        ico.setAttribute('aria-hidden', 'true');
        var txt = document.createElement('span');
        txt.textContent = p.nombre;
        b.appendChild(ico);
        b.appendChild(txt);
        b.addEventListener('click', function() { seleccionarMetodo(id); });
        cont.appendChild(b);
    });
    actualizarEstadoPago();
}

function seleccionarMetodo(id) {
    id = String(id);
    if (metodosNoDisponibles[id] || !metodoPorId(id)) return;
    metodoSeleccionado = id;
    var alerta = document.getElementById('pos_pay_alert');
    if (alerta) alerta.hidden = true;
    renderMetodosPago(false);
}

function marcarMetodoNoDisponible(id) {
    id = String(id);
    var m = metodoPorId(id);
    metodosNoDisponibles[id] = true;
    if (metodoSeleccionado === id) metodoSeleccionado = '';
    var alerta = document.getElementById('pos_pay_alert');
    if (alerta) {
        alerta.textContent = '';
        var ico = document.createElement('i');
        ico.className = 'fa-solid fa-triangle-exclamation';
        ico.setAttribute('aria-hidden', 'true');
        var fuerte = document.createElement('strong');
        fuerte.textContent = (m ? m.nombre : 'El método elegido') + ' ya no está disponible.';
        alerta.appendChild(ico);
        alerta.appendChild(fuerte);
        alerta.appendChild(document.createTextNode(' Elige otro método para continuar; tus productos se conservan.'));
        alerta.hidden = false;
    }
    renderMetodosPago(false);
}

function actualizarEstadoPago() {
    var valido = !!metodoSeleccionado && !metodosNoDisponibles[metodoSeleccionado];
    var btn = document.getElementById('btn_procesar');
    if (btn) btn.disabled = !valido || ventaEnProceso;
    var req = document.getElementById('pos_pay_required');
    if (req) req.hidden = valido || !pagosLista.length;
    var caja = document.getElementById('pos_cash');
    if (caja) {
        var esEf = valido && metodoEsEfectivo(metodoSeleccionado);
        caja.hidden = !esEf;
        if (!esEf) {
            // Solo el efectivo usa recibido y vuelto; así no queda un monto de otra selección.
            document.getElementById('monto_recibido').value = '';
        }
    }
}

async function verificarMetodoDisponible(id) {
    // Simulación para probar el diseño sin backend: ?mockMetodoOff=3 trata ese método como desactivado al confirmar.
    var simulado = new URLSearchParams(window.location.search).get('mockMetodoOff');
    if (simulado && String(simulado) === String(id)) return false;
    try {
        var r = await Api.get('pagos');
        if (!r || !r.ok) return true; // si la consulta falla, decide el servidor al confirmar
        var lista = (r.data && r.data.data) || [];
        return lista.some(function(p) { return String(p.id_metodo_pago) === String(id); });
    } catch (e) {
        return true;
    }
}

/* ---------- Escaneo de código de barras (HU-21) ---------- */
function mostrarEstadoEscaneo(tipo, partes) {
    var el = document.getElementById('pos_scan_status');
    if (!el) return;
    el.textContent = '';
    el.className = 'pos-scan-status is-' + tipo;
    var iconos = { ok: 'fa-check', error: 'fa-triangle-exclamation', warn: 'fa-circle-exclamation', loading: 'fa-circle-notch fa-spin' };
    var ico = document.createElement('i');
    ico.className = 'fa-solid ' + iconos[tipo];
    ico.setAttribute('aria-hidden', 'true');
    var cuerpo = document.createElement('span');
    partes.forEach(function(parte) {
        if (typeof parte === 'string') { cuerpo.appendChild(document.createTextNode(parte)); return; }
        var n = document.createElement(parte.tag);
        n.textContent = parte.text;
        cuerpo.appendChild(n);
    });
    el.appendChild(ico);
    el.appendChild(cuerpo);
    el.hidden = false;
}

function ocultarEstadoEscaneo() {
    var el = document.getElementById('pos_scan_status');
    if (el) el.hidden = true;
    marcarEntradaEscaneo(false);
}

function marcarEntradaEscaneo(invalida) {
    var input = document.getElementById('pos_search');
    if (!input) return;
    input.classList.toggle('is-invalid', !!invalida);
    if (invalida) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
}

function manejarEntradaEscaneo(texto) {
    // Un texto con espacios es una búsqueda por nombre: el filtro del catálogo ya la atiende.
    if (!texto || /\s/.test(texto)) return;
    colaEscaneo.push(texto);
    procesarColaEscaneo();
}

async function procesarColaEscaneo() {
    if (escaneoEnCurso) return;
    escaneoEnCurso = true;
    try {
        while (colaEscaneo.length) await resolverCodigo(colaEscaneo.shift());
    } finally {
        escaneoEnCurso = false;
    }
}

async function buscarProductoPorCodigo(codigo) {
    // Simulación para probar el diseño sin backend: ?mockScan=error provoca un fallo de conexión en la primera lectura.
    if (new URLSearchParams(window.location.search).get('mockScan') === 'error' && fallosEscaneoSimulados === 0) {
        fallosEscaneoSimulados++;
        await new Promise(function(r) { setTimeout(r, 400); });
        throw new Error('Conexión simulada');
    }
    // TODO backend: confirmar un endpoint por código que distinga inexistente de inactivo (hoy solo se lista lo activo).
    var res = await Api.get('productos?q=' + encodeURIComponent(codigo) + '&limit=10');
    if (!res || !res.ok) throw new Error('Consulta fallida');
    var lista = (res.data && res.data.data) || [];
    var p = lista.find(function(x) { return String(x.codigo_barra || '').trim() === codigo; }) || null;
    if (p) {
        var i = productosLista.findIndex(function(x) { return x.id_producto == p.id_producto; });
        if (i >= 0) productosLista[i] = Object.assign({}, productosLista[i], p); else productosLista.push(p);
    }
    return p;
}

async function resolverCodigo(codigo) {
    var input = document.getElementById('pos_search');
    marcarEntradaEscaneo(false);
    mostrarEstadoEscaneo('loading', ['Buscando producto…']);

    var p;
    try {
        p = await buscarProductoPorCodigo(codigo);
    } catch (e) {
        // La venta no cambia y el código queda en el campo para reintentar o corregirlo.
        mostrarEstadoEscaneo('error', [
            { tag: 'strong', text: 'No se pudo consultar la información.' },
            ' No se agregó nada a la venta. Vuelve a escanear o digita el código.'
        ]);
        marcarEntradaEscaneo(true);
        return;
    }

    if (!p) {
        if (/^\d+$/.test(codigo)) {
            mostrarEstadoEscaneo('error', [
                { tag: 'strong', text: 'No se pudo identificar el código ' },
                { tag: 'code', text: codigo },
                '. No se agregó nada a la venta. Digítalo de nuevo o busca el producto por nombre.'
            ]);
            marcarEntradaEscaneo(true);
        } else {
            ocultarEstadoEscaneo();
        }
        return;
    }

    if (p.estado !== undefined && Number(p.estado) !== 1) {
        mostrarEstadoEscaneo('warn', [{ tag: 'strong', text: p.nombre }, ' está inactivo. No se agregó a la venta.']);
        return;
    }

    var r = intentarAgregar(p);
    if (!r.ok) {
        mostrarEstadoEscaneo('warn', r.motivo === 'sin_stock'
            ? [{ tag: 'strong', text: p.nombre }, ' no tiene stock. No se agregó a la venta.']
            : [{ tag: 'strong', text: p.nombre }, ' · solo quedan ' + r.stock + ' en stock y ya están en el carrito.']);
        return;
    }

    mostrarEstadoEscaneo('ok', r.nueva
        ? ['Leído: ', { tag: 'strong', text: p.nombre }, ' · agregado al carrito.']
        : ['Leído: ', { tag: 'strong', text: p.nombre }, ' · ya hay ' + r.cantidad + ' unidades en el carrito.']);
    input.value = '';
    renderProductos('');
}

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function isDecimalUnit(unit) {
    var u = (unit || '').toLowerCase();
    var decimals = ['kg', 'lt', 'lb', 'gal', 'm', 'cm', 'ml', 'g', 'oz'];
    for (var i = 0; i < decimals.length; i++) {
        if (u.indexOf(decimals[i]) !== -1) return true;
    }
    return false;
}

function imprimirTicket(venta, items, cliente, metodoPago) {
    var now = new Date();
    var fecha = now.toLocaleDateString('es-PE') + ' ' + now.toLocaleTimeString('es-PE');
    var filas = items.map(function(it) {
        return '<tr>' +
            '<td>' + esc(it.nombre) + '</td>' +
            '<td class="r">' + parseInt(it.cantidad, 10) + '</td>' +
            '<td class="r">S/. ' + parseFloat(it.precio).toFixed(2) + '</td>' +
            '<td class="r">S/. ' + parseFloat(it.subtotal).toFixed(2) + '</td>' +
            '</tr>';
    }).join('');

    var html = '<!DOCTYPE html><html><head><meta charset="utf-8">' +
        '<title>Ticket ' + venta.serie + '-' + venta.numero + '</title>' +
        '<style>' +
        'body{font-family:"Courier New",monospace;font-size:12px;width:80mm;margin:0 auto;padding:5mm 0;}' +
        '.c{text-align:center}' +
        '.b{font-weight:700}' +
        '.hr{border-top:1px dashed #000;margin:4px 0}' +
        'table{width:100%;border-collapse:collapse}' +
        'td{padding:2px 0}' +
        '.r{text-align:right}' +
        '.tot{font-size:14px;font-weight:700;border-top:2px solid #000;padding-top:4px;margin-top:4px}' +
        '@media print{body{margin:0;padding:0;width:80mm}' +
        '@page{size:80mm auto;margin:0}}' +
        '</style></head><body>' +
        '<div class="c b">AMAZON MARKET</div>' +
        '<div class="c">RUC: 20611091380</div>' +
        '<div class="c">JR. CAJAMARCA NRO. SN</div>' +
        '<div class="c">(CAJAMARCA CDRA 5 - COSTADO DE OLANO)</div>' +
        '<div class="c">AMAZONAS - BAGUA - BAGUA</div>' +
        '<div class="hr"></div>' +
        '<div class="b">' + venta.tipo + '</div>' +
        '<div>Serie: ' + venta.serie + ' | N°: ' + venta.numero + '</div>' +
        '<div>Fecha: ' + fecha + '</div>' +
        '<div>Cliente: ' + esc(cliente) + '</div>' +
        '<div>Vendedor: ' + esc(typeof VENDEDOR_NOMBRE !== 'undefined' ? VENDEDOR_NOMBRE : 'Vendedor') + '</div>' +
        '<div>Pago: ' + esc(metodoPago) + '</div>' +
        '<div class="hr"></div>' +
        '<table><thead><tr><th>Producto</th><th class="r">Cant</th><th class="r">P.U.</th><th class="r">Subt.</th></tr></thead>' +
        '<tbody>' + filas + '</tbody></table>' +
        '<div class="hr"></div>' +
        '<div class="tot c">TOTAL: S/. ' + parseFloat(venta.total).toFixed(2) + '</div>' +
        '<div class="hr"></div>' +
        '<div class="c">¡Gracias por su compra!</div>' +
        '</body></html>';

    var win = window.open('', '_blank', 'width=300,height=600');
    if (win) {
        win.document.write(html);
        win.document.close();
        setTimeout(function() { win.print(); }, 500);
    }
}

function debounce(fn, ms) {
    var t;
    return function() { clearTimeout(t); var a = arguments; var ctx = this; t = setTimeout(function() { fn.apply(ctx, a); }, ms); };
}

function gradStr(s) {
    var h = 0;
    for (var i = 0; i < (s || '').length; i++) h = s.charCodeAt(i) + ((h << 5) - h);
    var hue = Math.abs(h) % 360;
    return 'linear-gradient(135deg, hsl(' + hue + ',65%,52%) 0%, hsl(' + ((hue + 40) % 360) + ',55%,42%) 100%)';
}
