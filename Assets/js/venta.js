let carrito = [];
let productosLista = [];
let clientesLista = [];
let tiposDocumentoLista = [];
let categoriasLista = [];
let chipCategoriaActiva = '';
let clienteSeleccionado = null;
let paydocMap = { 'id_tipo_comprobante': {}, 'id_metodo_pago': {} };

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
        var list = apiData(await Api.get('pagos'));
        fillSelect('id_metodo_pago', list, 'id_metodo_pago', 'nombre');
        list.forEach(function(p) {
            var name = (p.nombre || '').toLowerCase();
            if (name.includes('efectivo')) paydocMap['id_metodo_pago']['efectivo'] = String(p.id_metodo_pago);
        });
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

    document.getElementById('pos_client_box').addEventListener('click', toggleClientDD);
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
        var stock = parseFloat(p.stock_actual) || 0;
        var minStock = parseFloat(p.stock_minimo) || 0;
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
            '<div class="pos-item-meta">Stock ' + stock.toFixed(2) + ' ' + esc(p.unidad || '') + '</div>' +
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

function agregarProducto(p) {
    var stock = parseFloat(p.stock_actual) || 0;
    if (stock <= 0) { Modal.warning('Sin stock', 'No hay stock de "' + p.nombre + '".'); return; }
    var idx = carrito.findIndex(function(i) { return i.id_producto == p.id_producto; });
    if (idx >= 0) {
        if (carrito[idx].cantidad + 1 > stock) { Modal.warning('Stock insuficiente', 'Solo quedan ' + stock.toFixed(2)); return; }
        carrito[idx].cantidad += 1;
        carrito[idx].subtotal = carrito[idx].cantidad * carrito[idx].precio;
    } else {
        var precio = parseFloat(p.precio_venta) || 0;
        carrito.push({ id_producto: p.id_producto, nombre: p.nombre, unidad: p.unidad || '', precio: precio, cantidad: 1, subtotal: precio });
    }
    renderCarrito();
}

function setQty(i, val) {
    var c = parseFloat(val);
    if (isNaN(c) || c <= 0) { removeItem(i); return; }
    var item = carrito[i];
    var prod = productosLista.find(function(p) { return p.id_producto == item.id_producto; });
    var stock = prod ? (parseFloat(prod.stock_actual) || 0) : item.cantidad;
    if (c > stock) { Modal.warning('Stock insuficiente', 'Stock: ' + stock.toFixed(2)); renderCarrito(); return; }
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
        minus.addEventListener('click', function() { setQty(idx, parseFloat(item.cantidad) - 1); });
        var inp = document.createElement('input');
        inp.type = 'number';
        inp.value = item.cantidad;
        var esDecimal = isDecimalUnit(item.unidad);
        inp.min = esDecimal ? '0.01' : '1';
        inp.step = esDecimal ? '0.01' : '1';
        inp.addEventListener('change', function() { setQty(idx, inp.value); });
        var plus = document.createElement('button');
        plus.type = 'button';
        plus.innerHTML = '<i class="fa-solid fa-plus"></i>';
        plus.addEventListener('click', function() { setQty(idx, parseFloat(item.cantidad) + 1); });
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
    var items = query ? clientesLista.filter(function(c) {
        return (c.nombre || '').toLowerCase().includes(query.toLowerCase()) || (c.nro_documento || '').toLowerCase().includes(query.toLowerCase());
    }).slice(0, 12) : clientesLista.slice(0, 12);

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

    if (query && !items.length) {
        dd.innerHTML = '<div class="pos-dd-empty"><i class="fa-solid fa-user-slash"></i> Sin coincidencias</div>';
    }
}

function selectClient(c) {
    clienteSeleccionado = c;
    document.getElementById('id_cliente').value = c.id_cliente;
    document.getElementById('pos_client_sel').classList.add('active');
    document.getElementById('pos_client_box').classList.add('hidden');
    document.getElementById('pos_client_avatar').textContent = (c.nombre || '?').charAt(0).toUpperCase();
    document.getElementById('pos_client_name2').textContent = c.nombre;
    document.getElementById('pos_client_doc').textContent = (c.tipo_documento || 'Doc') + ': ' + (c.nro_documento || '—');
    closeClientDD();
    validarFacturaRUC();
}

function clearClient() {
    clienteSeleccionado = null;
    document.getElementById('id_cliente').value = '';
    document.getElementById('pos_client_sel').classList.remove('active');
    document.getElementById('pos_client_box').classList.remove('hidden');
    validarFacturaRUC();
}

function initPaydoc() {
    var wrap = document.getElementById('pos_paydoc');
    if (!wrap) return;

    // Set default select values based on active buttons
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

        // For comprobante, only one active between boleta/factura
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
        id_tipo_documento: parseInt(document.getElementById('nc_id_tipo_documento').value) || 0,
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
        var tdId = parseInt(document.getElementById('nc_id_tipo_documento').value) || 0;
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
    var id_mp = document.getElementById('id_metodo_pago').value;
    if (!id_cliente) { Modal.warning('Falta cliente', 'Seleccione o registre un cliente.'); return; }
    if (!id_tc) { Modal.warning('Falta comprobante', 'Seleccione un tipo de comprobante.'); return; }
    if (!id_mp) { Modal.warning('Falta pago', 'Seleccione método de pago.'); return; }
    if (!carrito.length) { Modal.warning('Carrito vacío', 'Agregue al menos un producto.'); return; }

    var ok = await Modal.confirm('Procesar venta', '¿Confirmar venta por S/. ' + totalCarrito().toFixed(2) + '?', 'warning');
    if (!ok) return;

    document.getElementById('loader_overlay').style.display = 'flex';
    btn.disabled = true;
    try {
        var res = await Api.post('ventas', {
            id_cliente: parseInt(id_cliente),
            id_tipo_comprobante: parseInt(id_tc),
            id_metodo_pago: parseInt(id_mp),
            productos: carrito.map(function(i) { return { id_producto: i.id_producto, cantidad: i.cantidad }; })
        });
        document.getElementById('loader_overlay').style.display = 'none';
        if (res && res.ok && res.data.status) {
            var d = res.data.data;
            var tipoComprobante = document.getElementById('id_tipo_comprobante');
            var tipoNombre = tipoComprobante.options[tipoComprobante.selectedIndex] ? tipoComprobante.options[tipoComprobante.selectedIndex].text : 'Boleta';
            var metodoPago = document.getElementById('id_metodo_pago');
            var mpNombre = metodoPago.options[metodoPago.selectedIndex] ? metodoPago.options[metodoPago.selectedIndex].text : '';
            imprimirTicket(
                { serie: d.serie, numero: d.numero, total: d.total, tipo: tipoNombre },
                carrito,
                clienteSeleccionado ? clienteSeleccionado.nombre : 'Cliente general',
                mpNombre
            );
            await Modal.success('Venta registrada',
                'Serie: ' + d.serie + '\nNúmero: ' + d.numero + '\nTotal: S/. ' + parseFloat(d.total).toFixed(2));
            await cargarProductos();
            carrito = [];
            clearClient();
            document.getElementById('monto_recibido').value = '';
            calcularVuelto();
            renderCarrito();
        } else {
            await Modal.error('Error', (res && res.data && res.data.message) || 'Error desconocido.');
        }
    } catch(e) {
        document.getElementById('loader_overlay').style.display = 'none';
        await Modal.error('Error', 'No se pudo procesar: ' + e.message);
    } finally {
        btn.disabled = false;
    }
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
            '<td class="r">' + parseFloat(it.cantidad).toFixed(2) + '</td>' +
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
        '<div>Vendedor: ' + esc(VENDEDOR_NOMBRE) + '</div>' +
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
