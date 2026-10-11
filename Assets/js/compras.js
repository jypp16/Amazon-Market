document.addEventListener('DOMContentLoaded', () => {
    inicializarHistorialCompras();
    inicializarDetalleCompra();

    const formulario = document.getElementById('form_registrar_compra');
    if (!formulario) return;

    const catalogos = window.COMPRA_CATALOGOS || { productos: [], presentaciones: [] };
    const lineas = document.getElementById('compra_lineas');
    const error = document.getElementById('compra_error');
    const botonConfirmar = document.getElementById('compra_confirmar');
    const validaciones = document.getElementById('compra_validaciones');
    const importeDocumento = document.getElementById('compra_total_documento');
    const fechaRecepcion = document.getElementById('compra_fecha_recepcion');
    const idCompraCorreccion = formulario.dataset.correctionId;
    const esCorreccion = Boolean(idCompraCorreccion);
    const motivoCorreccion = document.getElementById('compra_motivo_correccion');
    const formatter = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const camposDocumento = [
        document.getElementById('compra_proveedor'),
        document.getElementById('compra_tipo_comprobante'),
        document.getElementById('compra_serie'),
        document.getElementById('compra_numero'),
        document.getElementById('compra_fecha_emision'),
        fechaRecepcion,
        importeDocumento
    ];

    function dinero(valor) {
        return 'S/ ' + formatter.format(Number(valor) || 0);
    }

    function elemento(tag, clase, texto) {
        const nodo = document.createElement(tag);
        if (clase) nodo.className = clase;
        if (texto !== undefined) nodo.textContent = texto;
        return nodo;
    }

    function crearSelector(ariaLabel, items, idKey, labelFn, placeholder) {
        const select = elemento('select');
        select.setAttribute('aria-label', ariaLabel);
        select.required = true;
        select.add(new Option(placeholder, ''));
        items.forEach(item => select.add(new Option(labelFn(item), item[idKey])));
        return select;
    }

    function crearEntrada(type, ariaLabel, attributes = {}) {
        const input = elemento('input');
        input.type = type;
        input.setAttribute('aria-label', ariaLabel);
        Object.entries(attributes).forEach(([name, value]) => input.setAttribute(name, value));
        return input;
    }

    function obtenerFilas() {
        return Array.from(lineas.querySelectorAll('tr.purchase-line'));
    }

    function actualizarReferencia() {
        const tipo = document.getElementById('compra_tipo_comprobante');
        const nombreTipo = tipo.selectedOptions[0] && tipo.value
            ? tipo.selectedOptions[0].textContent
            : 'Documento';
        const serie = document.getElementById('compra_serie').value.trim();
        const numero = document.getElementById('compra_numero').value.trim();
        const referencia = [serie, numero].filter(Boolean).join('-');
        document.getElementById('compra_referencia').textContent = 'Referencia: ' + (referencia ? nombreTipo + ' ' + referencia : '—');
    }

    function agregarLinea() {
        const empty = lineas.querySelector('.purchase-lines-empty');
        if (empty) empty.remove();

        const row = elemento('tr', 'purchase-line');
        const count = obtenerFilas().length + 1;
        row.dataset.line = String(count);

        row.appendChild(elemento('td', 'purchase-line-number', String(count)));

        const productCell = elemento('td');
        const productSelect = crearSelector(
            'Producto',
            catalogos.productos,
            'id_producto',
            product => product.nombre + (product.codigo_barra ? ' ' + product.codigo_barra : ''),
            'Selecciona producto'
        );
        productSelect.dataset.field = 'producto';
        productCell.appendChild(productSelect);
        row.appendChild(productCell);

        const presentationCell = elemento('td');
        const presentationSelect = crearSelector(
            'Presentación',
            catalogos.presentaciones,
            'id_presentacion',
            item => item.nombre,
            'Presentación'
        );
        catalogos.presentaciones.forEach((item, index) => {
            presentationSelect.options[index + 1].dataset.equivalencia = String(item.equivalencia);
        });
        presentationSelect.dataset.field = 'presentacion';
        presentationSelect.disabled = true;
        presentationCell.appendChild(presentationSelect);
        row.appendChild(presentationCell);

        const quantityCell = elemento('td');
        const quantity = crearEntrada('number', 'Cantidad de presentaciones', { min: '1', step: '1', value: '1' });
        quantity.dataset.field = 'cantidad';
        quantityCell.appendChild(quantity);
        row.appendChild(quantityCell);

        const equivalence = elemento('td', 'purchase-line-equivalence', '—');
        equivalence.dataset.field = 'equivalencia';
        row.appendChild(equivalence);

        const baseUnits = elemento('td', 'purchase-line-base-units', '—');
        baseUnits.dataset.field = 'unidades';
        row.appendChild(baseUnits);

        const amountCell = elemento('td');
        const amount = crearEntrada('number', 'Importe final de la línea en soles', { min: '0.01', step: '0.01', placeholder: '0.00' });
        amount.dataset.field = 'importe';
        amountCell.appendChild(amount);
        row.appendChild(amountCell);

        const cost = elemento('td', 'purchase-line-cost', 'S/ 0.00');
        cost.dataset.field = 'costo';
        row.appendChild(cost);

        const lotCell = elemento('td');
        const lot = crearEntrada('text', 'Lote del fabricante o No aplica', { maxlength: '50', placeholder: 'Opcional' });
        lot.dataset.field = 'lote';
        lotCell.appendChild(lot);
        row.appendChild(lotCell);

        const expirationCell = elemento('td');
        const expiration = crearEntrada('date', 'Fecha de vencimiento');
        expiration.dataset.field = 'vencimiento';
        expirationCell.appendChild(expiration);
        row.appendChild(expirationCell);

        const removeCell = elemento('td');
        const remove = elemento('button', 'purchase-remove-line');
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Quitar línea ' + count);
        remove.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
        remove.addEventListener('click', () => {
            row.remove();
            renumerarLineas();
            actualizarResumen();
        });


        removeCell.appendChild(remove);
        row.appendChild(removeCell);

        row.addEventListener('input', actualizarResumen);
        row.addEventListener('change', actualizarResumen);
        productSelect.addEventListener('change', () => {
            presentationSelect.disabled = !productSelect.value;
            if (!productSelect.value) presentationSelect.value = '';
            actualizarResumen();
        });

        lineas.appendChild(row);
        actualizarResumen();
    }

    function renumerarLineas() {
        const rows = obtenerFilas();
        rows.forEach((row, index) => {
            const number = index + 1;
            row.dataset.line = String(number);
            row.querySelector('.purchase-line-number').textContent = String(number);
            row.querySelector('.purchase-remove-line').setAttribute('aria-label', 'Quitar línea ' + number);
        });
        if (!rows.length) {
            const empty = elemento('tr', 'purchase-lines-empty');
            const cell = elemento('td', '', 'Añade una línea para comenzar.');
            cell.colSpan = 11;
            empty.appendChild(cell);
            lineas.appendChild(empty);
        }
    }

    function datosLinea(row) {
        const producto = row.querySelector('[data-field="producto"]');
        const presentacion = row.querySelector('[data-field="presentacion"]');
        const opcionPresentacion = presentacion.selectedOptions[0];
        const cantidad = Number(row.querySelector('[data-field="cantidad"]').value);
        const equivalencia = Number(opcionPresentacion && opcionPresentacion.dataset.equivalencia) || 0;
        const importe = Number(row.querySelector('[data-field="importe"]').value);
        const vencimiento = row.querySelector('[data-field="vencimiento"]').value;
        const nombreProducto = producto.selectedOptions[0] ? producto.selectedOptions[0].textContent : 'Producto';
        const unidades = cantidad > 0 && equivalencia > 0 ? cantidad * equivalencia : 0;
        const costo = cantidad > 0 && importe >= 0 ? importe / cantidad : 0;

        row.querySelector('[data-field="equivalencia"]').textContent = equivalencia || '—';
        row.querySelector('[data-field="unidades"]').textContent = unidades || '—';
        row.querySelector('[data-field="costo"]').textContent = dinero(costo);
        row.classList.toggle('purchase-line-invalid', Boolean(vencimiento && fechaRecepcion.value && vencimiento <= fechaRecepcion.value));

        const issues = [];
        if (!producto.value) issues.push('Línea ' + row.dataset.line + ': selecciona un producto.');
        if (!presentacion.value) issues.push('Línea ' + row.dataset.line + ': selecciona una presentación.');
        if (!Number.isInteger(cantidad) || cantidad <= 0) issues.push('Línea ' + row.dataset.line + ': la cantidad debe ser un entero mayor a cero.');
        if (!Number.isFinite(importe) || importe <= 0) issues.push('Línea ' + row.dataset.line + ': ingresa un importe mayor a cero.');
        if (vencimiento && fechaRecepcion.value && vencimiento <= fechaRecepcion.value) {
            issues.push('Línea ' + row.dataset.line + ': el vencimiento debe ser posterior a la recepción.');
        }

        return { cantidad, equivalencia, importe, unidades, vencimiento, nombreProducto, issues };
    }

    function actualizarResumen() {
        actualizarReferencia();

        const rows = obtenerFilas();
        const lineData = rows.map(datosLinea);
        const issues = [];
        const fechaEmision = document.getElementById('compra_fecha_emision').value;
        const receptionDate = fechaRecepcion.value;
        const documentTotal = Number(importeDocumento.value);
        const sum = lineData.reduce((total, line) => total + (Number.isFinite(line.importe) ? line.importe : 0), 0);
        const units = lineData.reduce((total, line) => total + line.unidades, 0);
        const difference = documentTotal - sum;

        document.getElementById('compra_resumen_lineas').textContent = String(rows.length);
        document.getElementById('compra_resumen_unidades').textContent = String(units);
        document.getElementById('compra_resumen_detalle').textContent = dinero(sum);
        document.getElementById('compra_resumen_documento').textContent = dinero(documentTotal);

        const diffNode = document.getElementById('compra_resumen_diferencia');
        diffNode.textContent = dinero(difference);
        diffNode.closest('.purchase-review-difference').classList.toggle('is-balanced', Math.abs(difference) < 0.005);

        if (!document.getElementById('compra_proveedor').value) issues.push('Selecciona un proveedor.');
        if (!document.getElementById('compra_tipo_comprobante').value) issues.push('Selecciona el tipo de documento.');
        if (!document.getElementById('compra_serie').value.trim()) issues.push('Ingresa la serie del documento.');
        if (!document.getElementById('compra_numero').value.trim()) issues.push('Ingresa el número del documento.');
        if (!fechaEmision) issues.push('Ingresa la fecha de emisión.');
        if (!receptionDate) issues.push('Ingresa la fecha de recepción.');
        if (fechaEmision && receptionDate && fechaEmision > receptionDate) issues.push('La emisión no puede ser posterior a la recepción.');
        const hoy = new Date();
        const fechaLocal = [
            hoy.getFullYear(),
            String(hoy.getMonth() + 1).padStart(2, '0'),
            String(hoy.getDate()).padStart(2, '0')
        ].join('-');
        if (receptionDate && receptionDate > fechaLocal) issues.push('La fecha de recepción no puede ser futura.');
        if (!Number.isFinite(documentTotal) || documentTotal <= 0) issues.push('El total del documento debe ser mayor a cero.');
        if (esCorreccion && !motivoCorreccion.value.trim()) issues.push('Escribe el motivo de la corrección.');
        if (rows.length === 0) issues.push('Agrega al menos una línea de producto.');
        lineData.forEach(line => issues.push(...line.issues));
        if (rows.length && Math.abs(difference) >= 0.005) issues.push('La suma del detalle no coincide con el total del documento.');

        validaciones.textContent = '';
        if (issues.length === 0) {
            validaciones.className = 'purchase-validation-list is-valid';
            validaciones.textContent = 'Todo está correcto. La compra está lista para confirmarse.';
        } else {
            validaciones.className = 'purchase-validation-list is-invalid';
            const title = elemento('strong', '', 'Corrige estos puntos:');
            const list = elemento('ol');
            issues.forEach(message => list.appendChild(elemento('li', '', message)));
            validaciones.append(title, list);
        }

        botonConfirmar.disabled = issues.length > 0;
        return { issues, lineData, documentTotal, rows };
    }

    camposDocumento.forEach(field => {
        field.addEventListener('input', actualizarResumen);
        field.addEventListener('change', actualizarResumen);
    });
    if (motivoCorreccion) motivoCorreccion.addEventListener('input', actualizarResumen);
    document.getElementById('compra_agregar_linea').addEventListener('click', agregarLinea);

    function asegurarOpcion(select, value, label, equivalencia) {
        const valor = String(value);
        let opcion = Array.from(select.options).find(item => item.value === valor);
        if (!opcion) {
            opcion = new Option(label || ('Opción #' + valor), valor);
            select.add(opcion);
        }
        if (equivalencia !== undefined) opcion.dataset.equivalencia = String(equivalencia);
        select.value = valor;
    }

    async function cargarCompraParaCorreccion() {
        botonConfirmar.disabled = true;
        validaciones.textContent = 'Cargando la compra que se corregirá…';
        try {
            const response = await Api.get('compras/' + encodeURIComponent(idCompraCorreccion));
            const result = response && response.data;
            if (!response || !response.ok || !result || !result.status || !result.data) {
                throw new Error(result ? result.message : 'No se pudo cargar la compra para corregir.');
            }
            const compra = result.data;
            if (compra.estado === 'Anulada') {
                throw new Error('No se puede corregir una compra anulada.');
            }

            asegurarOpcion(
                document.getElementById('compra_proveedor'),
                compra.id_proveedor,
                [compra.razon_social, compra.numero_documento].filter(Boolean).join(' — ')
            );
            asegurarOpcion(document.getElementById('compra_tipo_comprobante'),
                compra.id_tipo_comprobante, compra.tipo_comprobante);
            document.getElementById('compra_serie').value = compra.serie || '';
            document.getElementById('compra_numero').value = compra.numero || '';
            document.getElementById('compra_fecha_emision').value = String(compra.fecha_emision || '').slice(0, 10);
            fechaRecepcion.value = String(compra.fecha_recepcion || '').slice(0, 10);
            importeDocumento.value = Number(compra.total_documento || 0).toFixed(2);

            const detalles = Array.isArray(compra.detalles) ? compra.detalles : [];
            if (!detalles.length) throw new Error('La compra no tiene líneas disponibles para corregir.');
            detalles.forEach(detalle => {
                agregarLinea();
                const filas = obtenerFilas();
                const fila = filas[filas.length - 1];
                const producto = fila.querySelector('[data-field="producto"]');
                const presentacion = fila.querySelector('[data-field="presentacion"]');
                asegurarOpcion(producto, detalle.id_producto, detalle.producto);
                producto.dispatchEvent(new Event('change', { bubbles: true }));
                asegurarOpcion(presentacion, detalle.id_presentacion,
                    detalle.presentacion, detalle.equivalencia_aplicada);
                fila.querySelector('[data-field="cantidad"]').value = detalle.cantidad_presentaciones;
                fila.querySelector('[data-field="importe"]').value = Number(detalle.importe_final_linea || 0).toFixed(2);
                fila.querySelector('[data-field="lote"]').value = detalle.numero_lote || '';
                fila.querySelector('[data-field="vencimiento"]').value = String(detalle.fecha_vencimiento || '').slice(0, 10);
                presentacion.dispatchEvent(new Event('change', { bubbles: true }));
            });
            actualizarResumen();
        } catch (exception) {
            error.textContent = exception.message || 'No se pudo cargar la compra para corregir.';
            error.hidden = false;
            validaciones.textContent = 'No se pudo preparar la corrección.';
        }
    }

    formulario.addEventListener('submit', async event => {
        event.preventDefault();
        error.hidden = true;

        const review = actualizarResumen();
        if (review.issues.length || botonConfirmar.disabled) return;

        const payload = {
            id_proveedor: Number(document.getElementById('compra_proveedor').value),
            id_tipo_comprobante: Number(document.getElementById('compra_tipo_comprobante').value),
            serie: document.getElementById('compra_serie').value.trim(),
            numero: document.getElementById('compra_numero').value.trim(),
            fecha_emision: document.getElementById('compra_fecha_emision').value,
            fecha_recepcion: fechaRecepcion.value,
            total_documento: Number(review.documentTotal.toFixed(2)),
            ...(esCorreccion ? { motivo: motivoCorreccion.value.trim() } : {}),
            detalles: review.rows.map((row, index) => {
                const line = review.lineData[index];
                return {
                    id_producto: Number(row.querySelector('[data-field="producto"]').value),
                    id_presentacion: Number(row.querySelector('[data-field="presentacion"]').value),
                    cantidad_presentaciones: line.cantidad,
                    equivalencia_aplicada: line.equivalencia,
                    importe_final_linea: Number(line.importe.toFixed(2)),
                    numero_lote: row.querySelector('[data-field="lote"]').value.trim() || null,
                    fecha_vencimiento: line.vencimiento || null
                };
            })
        };

        botonConfirmar.disabled = true;
        const textoBoton = esCorreccion ? 'Guardando corrección...' : 'Confirmando...';
        botonConfirmar.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + textoBoton;
        try {
            const response = esCorreccion
                ? await Api.put('compras/' + encodeURIComponent(idCompraCorreccion), payload)
                : await Api.post('compras', payload);
            const result = response && response.data;
            if (!response || !response.ok || !result || !result.status) {
                throw new Error(result ? result.message : 'No se pudo guardar la compra.');
            }
            await Modal.success(
                esCorreccion ? 'Compra corregida' : 'Compra confirmada',
                result.message || (esCorreccion ? 'La corrección se guardó correctamente.' : 'La compra se registró correctamente.')
            );
            window.location.href = esCorreccion
                ? BASE_URL + '/Compra/detalle/' + encodeURIComponent(idCompraCorreccion)
                : BASE_URL + '/Compra';
        } catch (exception) {
            error.textContent = exception.message;
            error.hidden = false;
            botonConfirmar.disabled = false;
            botonConfirmar.innerHTML = '<i class="fa-solid fa-circle-check"></i> '
                + (esCorreccion ? 'Guardar corrección' : 'Confirmar compra');
        }
    });

    if (esCorreccion) cargarCompraParaCorreccion();
    else actualizarResumen();
});

function inicializarHistorialCompras() {
    const formulario = document.querySelector('.purchase-filters');
    const tbody = document.getElementById('tabla_compras');
    if (!formulario || !tbody) return;

    const paginacion = document.getElementById('paginacion_compras');
    const limit = 10;
    let pagina = 1;
    let paginas = 1;

    function formatoFecha(value) {
        if (!value) return '—';
        const parts = String(value).slice(0, 10).split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : value;
    }

    function formatoFechaHora(value) {
        if (!value) return '';
        const parts = String(value).replace('T', ' ').split(' ');
        return formatoFecha(parts[0]) + (parts[1] ? ' ' + parts[1].slice(0, 5) : '');
    }

    function agregarCelda(row, value, className = '') {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value == null || value === '' ? '—' : String(value);
        row.appendChild(cell);
        return cell;
    }

    function mostrarCarga() {
        tbody.textContent = '';
        const row = tbody.insertRow();
        const cell = row.insertCell();
        cell.colSpan = 8;
        cell.className = 'table-empty';
        cell.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Cargando compras...';
    }

    function mostrarError(message) {
        tbody.textContent = '';
        const row = tbody.insertRow();
        const cell = row.insertCell();
        cell.colSpan = 8;
        cell.className = 'table-empty';
        cell.textContent = 'No se pudo cargar el historial: ' + message;
        paginacion.textContent = '';
    }

    function renderizarFilas(compras) {
        tbody.textContent = '';
        if (!compras.length) {
            const row = tbody.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 8;
            cell.className = 'table-empty';
            cell.textContent = 'No hay compras para mostrar.';
            return;
        }

        compras.forEach(compra => {
            const row = tbody.insertRow();
            if (compra.estado === 'Anulada') row.className = 'purchase-row-annulled';
            const documento = [compra.tipo_comprobante, [compra.serie, compra.numero].filter(Boolean).join('-')]
                .filter(Boolean).join(' ');
            agregarCelda(row, documento, 'purchase-document-cell');

            const proveedor = agregarCelda(row, compra.razon_social || '—');
            if (compra.numero_documento) {
                const documentoProveedor = document.createElement('small');
                documentoProveedor.className = 'purchase-table-secondary';
                documentoProveedor.textContent = (compra.nombre_tipo_documento || 'Doc.') + ' ' + compra.numero_documento;
                proveedor.appendChild(document.createElement('br'));
                proveedor.appendChild(documentoProveedor);
            }

            agregarCelda(row, formatoFecha(compra.fecha_emision));
            agregarCelda(row, formatoFecha(compra.fecha_recepcion));

            const estadoCell = row.insertCell();
            const estado = document.createElement('span');
            const statusClass = {
                Confirmada: 'confirmed',
                Corregida: 'corrected',
                Anulada: 'annulled'
            }[compra.estado] || 'default';
            estado.className = 'purchase-status purchase-status-' + statusClass;
            estado.textContent = compra.estado || '—';
            estadoCell.appendChild(estado);

            const importeCell = row.insertCell();
            importeCell.className = 'purchase-amount-cell';
            const amount = document.createElement('strong');
            amount.textContent = 'S/ ' + Number(compra.total_documento || 0).toLocaleString('es-PE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            if (compra.estado === 'Anulada') amount.className = 'purchase-amount-annulled';
            importeCell.appendChild(amount);
            if (compra.estado === 'Anulada') {
                const excludedNote = document.createElement('small');
                excludedNote.className = 'purchase-table-secondary purchase-amount-note';
                excludedNote.textContent = 'No suma en totales';
                importeCell.appendChild(excludedNote);
            } else if (compra.estado === 'Corregida' && compra.total_original != null) {
                const originalAmount = document.createElement('small');
                originalAmount.className = 'purchase-table-secondary purchase-amount-note';
                originalAmount.textContent = 'Original S/ ' + Number(compra.total_original).toLocaleString('es-PE', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                importeCell.appendChild(originalAmount);
            }

            const registradoCell = agregarCelda(row, compra.nombre_usuario || '—', 'purchase-registered-cell');
            if (compra.created_at) {
                const created = document.createElement('small');
                created.className = 'purchase-table-secondary';
                created.textContent = formatoFechaHora(compra.created_at);
                registradoCell.appendChild(document.createElement('br'));
                registradoCell.appendChild(created);
            }

            const actionsCell = row.insertCell();
            const detailsLink = document.createElement('a');
            detailsLink.className = 'purchase-detail-button';
            detailsLink.href = BASE_URL + '/Compra/detalle/' + encodeURIComponent(compra.id_compra);
            detailsLink.textContent = 'Ver detalle';
            detailsLink.setAttribute('aria-label', 'Ver detalle de ' + documento);
            actionsCell.appendChild(detailsLink);
        });
    }

    function crearBoton(label, disabled, onClick, active = false) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pag-btn' + (active ? ' pag-active' : '');
        button.textContent = label;
        button.disabled = disabled;
        button.addEventListener('click', onClick);
        return button;
    }

    function renderizarPaginacion() {
        paginacion.textContent = '';
        const previous = crearBoton('Anterior', pagina <= 1, () => cargar(pagina - 1));
        paginacion.appendChild(previous);
        for (let number = 1; number <= paginas; number++) {
            if (paginas > 7 && number !== 1 && number !== paginas && Math.abs(number - pagina) > 1) {
                if (number === 2 || number === paginas - 1) {
                    const dots = document.createElement('span');
                    dots.className = 'pag-dots';
                    dots.textContent = '...';
                    paginacion.appendChild(dots);
                }
                continue;
            }
            paginacion.appendChild(crearBoton(String(number), number === pagina, () => cargar(number), number === pagina));
        }
        paginacion.appendChild(crearBoton('Siguiente', pagina >= paginas, () => cargar(pagina + 1)));
    }

    async function cargar(page = 1) {
        pagina = page;
        mostrarCarga();
        const formData = new FormData(formulario);
        const params = new URLSearchParams({
            page: String(pagina),
            limit: String(limit)
        });
        ['proveedor', 'fecha_desde', 'fecha_hasta', 'estado'].forEach(key => {
            const value = formData.get(key);
            if (value) params.set(key, value);
        });

        try {
            const response = await Api.get('compras?' + params.toString());
            const result = response && response.data;
            if (!response || !response.ok || !result || !result.status) {
                throw new Error(result ? result.message : 'Respuesta inválida del servidor.');
            }

            const purchases = Array.isArray(result.data) ? result.data : [];
            const summary = result.resumen || {};
            pagina = Number(result.pagina_actual) || 1;
            paginas = Math.max(1, Number(result.paginas) || 1);

            renderizarFilas(purchases);
            document.getElementById('compras_total_valido').textContent = 'S/ ' + Number(summary.total_validas || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('compras_cantidad_valida').textContent = String(summary.compras_validas || 0);
            document.getElementById('compras_cantidad_anulada').textContent = String(summary.compras_anuladas || 0);

            const totalValidPage = purchases.reduce((sum, purchase) => {
                return sum + (purchase.estado === 'Anulada' ? 0 : Number(purchase.total_documento || 0));
            }, 0);
            const firstItem = purchases.length ? ((pagina - 1) * limit) + 1 : 0;
            const lastItem = (pagina - 1) * limit + purchases.length;
            document.getElementById('compras_mostrando').textContent = 'Mostrando ' + firstItem + '–' + lastItem + ' de ' + (Number(result.total) || 0) + ' compras';
            document.getElementById('compras_total_pagina').textContent = 'Total de esta página (válidas): S/ ' + totalValidPage.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            renderizarPaginacion();
        } catch (exception) {
            mostrarError(exception.message);
        }
    }

    formulario.addEventListener('submit', event => {
        event.preventDefault();
        cargar(1);
    });
    cargar();
}

function inicializarDetalleCompra() {
    const pagina = document.getElementById('detalle_compra');
    if (!pagina) return;

    const idCompra = pagina.dataset.compraId;
    const cargando = document.getElementById('detalle_compra_cargando');
    const contenido = document.getElementById('detalle_compra_contenido');
    const error = document.getElementById('detalle_compra_error');
    const lineas = document.getElementById('detalle_compra_lineas');
    const historial = document.getElementById('detalle_compra_historial');
    const botonesVersion = Array.from(document.querySelectorAll('.purchase-detail-version'));
    const avisoVersion = document.getElementById('detalle_compra_version_aviso');
    const botonCorregir = document.getElementById('detalle_compra_corregir');
    const botonAnular = document.getElementById('detalle_compra_anular');
    const dialogoAnulacion = document.getElementById('modal_anular_compra');
    let compraActual = null;
    let estadoVersion = 'vigente';
    let detallesOriginales = [];
    let haySnapshotOriginal = false;
    let snapshotCompraOriginal = null;
    let tituloDocumentoActual = 'Detalle de compra';
    const formatoMoneda = new Intl.NumberFormat('es-PE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    const formatoCantidad = new Intl.NumberFormat('es-PE', {
        maximumFractionDigits: 2
    });

    function dinero(valor) {
        return 'S/ ' + formatoMoneda.format(Number(valor) || 0);
    }

    function fecha(valor) {
        if (!valor) return '—';
        const partes = String(valor).slice(0, 10).split('-');
        return partes.length === 3 ? partes[2] + '/' + partes[1] + '/' + partes[0] : String(valor);
    }

    function fechaHora(valor) {
        if (!valor) return '—';
        const partes = String(valor).replace('T', ' ').split(' ');
        return fecha(partes[0]) + (partes[1] ? ' ' + partes[1].slice(0, 5) : '');
    }

    function texto(id, valor) {
        const elemento = document.getElementById(id);
        elemento.textContent = valor == null || valor === '' ? '—' : String(valor);
    }

    function agregarCelda(fila, valor, clase = '') {
        const celda = fila.insertCell();
        celda.textContent = valor == null || valor === '' ? '—' : String(valor);
        if (clase) celda.className = clase;
        return celda;
    }

    function mostrarError(mensaje) {
        cargando.hidden = true;
        contenido.hidden = true;
        texto('detalle_compra_titulo', 'Detalle de compra');
        document.getElementById('detalle_compra_estado').hidden = true;
        error.textContent = mensaje;
        error.hidden = false;
    }

    function obtenerCantidadBase(detalle) {
        return Number(detalle.cantidad_base)
            || Number(detalle.cantidad_presentaciones || 0) * Number(detalle.equivalencia_aplicada || 0);
    }

    function renderizarValorCelda(fila, valor, valorAnterior, cambio, clase = '') {
        const celda = fila.insertCell();
        if (clase) celda.className = clase;
        const actual = document.createElement('span');
        actual.textContent = valor == null || valor === '' ? '—' : String(valor);
        celda.appendChild(actual);
        if (cambio && valorAnterior !== undefined) {
            const anterior = document.createElement('small');
            anterior.className = 'purchase-detail-old-value';
            anterior.textContent = 'Antes: ' + (valorAnterior == null || valorAnterior === '' ? '—' : String(valorAnterior));
            celda.appendChild(anterior);
        }
        return celda;
    }

    function renderizarDetalles(detalles, compararCon = []) {
        lineas.textContent = '';
        if (!detalles.length) {
            const fila = lineas.insertRow();
            const celda = fila.insertCell();
            celda.colSpan = 9;
            celda.className = 'purchase-detail-empty';
            celda.textContent = 'Esta compra no tiene líneas de detalle registradas.';
            return { suma: 0, unidades: 0 };
        }

        let suma = 0;
        let unidades = 0;
        detalles.forEach((detalle, indice) => {
            const fila = lineas.insertRow();
            const anterior = compararCon[indice];
            const cantidad = Number(detalle.cantidad_presentaciones || 0);
            const equivalente = Number(detalle.equivalencia_aplicada || 0);
            const cantidadBase = obtenerCantidadBase(detalle);
            const importe = Number(detalle.importe_final_linea || 0);
            const costo = cantidad ? importe / cantidad : importe;
            suma += importe;
            unidades += cantidadBase;

            const valores = [
                detalle.producto || '—',
                detalle.presentacion || '—',
                formatoCantidad.format(cantidad),
                formatoCantidad.format(equivalente),
                formatoCantidad.format(cantidadBase),
                detalle.numero_lote || '—',
                fecha(detalle.fecha_vencimiento),
                dinero(costo),
                dinero(importe)
            ];
            const valoresAnteriores = anterior ? [
                anterior.producto || '—',
                anterior.presentacion || '—',
                formatoCantidad.format(Number(anterior.cantidad_presentaciones || 0)),
                formatoCantidad.format(Number(anterior.equivalencia_aplicada || 0)),
                formatoCantidad.format(obtenerCantidadBase(anterior)),
                anterior.numero_lote || '—',
                fecha(anterior.fecha_vencimiento),
                dinero(Number(anterior.cantidad_presentaciones || 0)
                    ? Number(anterior.importe_final_linea || 0) / Number(anterior.cantidad_presentaciones)
                    : Number(anterior.importe_final_linea || 0)),
                dinero(anterior.importe_final_linea)
            ] : [];
            const cambioFila = anterior && valores.some((valor, indiceValor) => valor !== valoresAnteriores[indiceValor]);
            if (cambioFila) fila.classList.add('purchase-detail-row-changed');

            valores.forEach((valor, indiceValor) => {
                const celda = renderizarValorCelda(
                    fila,
                    valor,
                    valoresAnteriores[indiceValor],
                    Boolean(cambioFila && valor !== valoresAnteriores[indiceValor]),
                    [2, 3, 4, 7, 8].includes(indiceValor) ? 'purchase-detail-number' : ''
                );
                if (indiceValor === 0 && cambioFila) {
                    const etiqueta = document.createElement('small');
                    etiqueta.className = 'purchase-detail-row-note';
                    etiqueta.textContent = 'Línea corregida';
                    celda.appendChild(etiqueta);
                }
            });
        });

        return { suma, unidades };
    }

    function obtenerSnapshot(entrada) {
        try {
            const anterior = typeof entrada.datos_anteriores === 'string'
                ? JSON.parse(entrada.datos_anteriores)
                : entrada.datos_anteriores;
            return anterior && typeof anterior === 'object' ? anterior : null;
        } catch (exception) {
            return null;
        }
    }

    function obtenerCabeceraSnapshot(snapshot, compra) {
        if (snapshot && snapshot.cabecera) return snapshot.cabecera;
        return {
            fecha_emision: compra.fecha_emision,
            fecha_recepcion: compra.fecha_recepcion,
            total_documento: compra.total_documento,
            estado: compra.estado
        };
    }

    function resumirSnapshot(snapshot, compra) {
        const detalles = snapshot && Array.isArray(snapshot.detalles)
            ? snapshot.detalles
            : (Array.isArray(compra.detalles) ? compra.detalles : []);
        const cabecera = obtenerCabeceraSnapshot(snapshot, compra);
        const suma = detalles.reduce((total, detalle) => total + Number(detalle.importe_final_linea || 0), 0);
        const unidades = detalles.reduce((total, detalle) => total + obtenerCantidadBase(detalle), 0);
        return {
            detalles,
            suma,
            unidades,
            total: Number(cabecera.total_documento ?? compra.total_documento ?? 0)
        };
    }

    function obtenerEfectoInventario(antes, despues) {
        const cantidades = new Map();
        [antes, despues].forEach((detalle, indice) => {
            (detalle || []).forEach(linea => {
                const id = String(linea.id_producto || linea.producto || 'sin-id');
                const item = cantidades.get(id) || {
                    nombre: linea.producto || 'Producto',
                    delta: 0
                };
                item.delta += (indice === 0 ? -1 : 1) * obtenerCantidadBase(linea);
                cantidades.set(id, item);
            });
        });
        const cambios = Array.from(cantidades.values()).filter(item => Math.abs(item.delta) > 0.0001);
        if (!cambios.length) return 'Efecto en inventario: ninguno (cambio informativo o sin variación neta de cantidades).';
        return 'Efecto neto en inventario: ' + cambios.map(item => {
            const signo = item.delta > 0 ? '+' : '';
            return item.nombre + ' ' + signo + formatoCantidad.format(item.delta) + ' unidades';
        }).join('; ') + '.';
    }

    function agregarResumenCambio(contenedor, etiqueta, resumen) {
        const linea = document.createElement('p');
        linea.className = 'purchase-detail-history-change';
        linea.textContent = etiqueta + ': ' + resumen.detalles.length + ' líneas, '
            + formatoCantidad.format(resumen.unidades) + ' unidades, total del documento '
            + dinero(resumen.total) + '.';
        contenedor.appendChild(linea);
    }

    function agregarDiferenciasCabecera(contenedor, antes, despues) {
        const cabeceraAntes = antes && antes.cabecera ? antes.cabecera : {};
        const cabeceraDespues = despues && despues.cabecera
            ? despues.cabecera
            : obtenerCabeceraSnapshot(null, compraActual);
        const campos = [
            ['Proveedor', 'id_proveedor'],
            ['Tipo de comprobante', 'id_tipo_comprobante'],
            ['Serie', 'serie'],
            ['Número', 'numero'],
            ['Fecha de emisión', 'fecha_emision'],
            ['Fecha de recepción', 'fecha_recepcion'],
            ['Total del documento', 'total_documento']
        ];
        const cambios = campos.filter(([, clave]) => {
            return String(cabeceraAntes[clave] ?? '') !== String(cabeceraDespues[clave] ?? '');
        });
        if (!cambios.length) return;

        const bloque = document.createElement('div');
        bloque.className = 'purchase-detail-history-differences';
        const titulo = document.createElement('h5');
        titulo.textContent = 'Cambios en la cabecera';
        bloque.appendChild(titulo);
        cambios.forEach(([etiqueta, clave]) => {
            const linea = document.createElement('p');
            const valorAntes = clave === 'total_documento'
                ? dinero(cabeceraAntes[clave])
                : (cabeceraAntes[clave] || '—');
            const valorDespues = clave === 'total_documento'
                ? dinero(cabeceraDespues[clave])
                : (cabeceraDespues[clave] || '—');
            linea.textContent = etiqueta + ': ' + valorAntes + ' → ' + valorDespues;
            bloque.appendChild(linea);
        });
        contenedor.appendChild(bloque);
    }

    function agregarDiferenciasDetalles(contenedor, antes, despues) {
        const lineasAntes = antes && Array.isArray(antes.detalles) ? antes.detalles : [];
        const lineasDespues = despues && Array.isArray(despues.detalles)
            ? despues.detalles
            : (Array.isArray(compraActual.detalles) ? compraActual.detalles : []);
        const cantidadLineas = Math.max(lineasAntes.length, lineasDespues.length);
        const cambios = [];
        for (let indice = 0; indice < cantidadLineas; indice += 1) {
            const anterior = lineasAntes[indice];
            const actual = lineasDespues[indice];
            const firma = detalle => detalle ? [
                detalle.id_producto,
                detalle.id_presentacion,
                detalle.cantidad_presentaciones,
                detalle.equivalencia_aplicada,
                detalle.cantidad_base,
                detalle.importe_final_linea,
                detalle.numero_lote,
                detalle.fecha_vencimiento
            ].map(valor => String(valor ?? '')).join('|') : '';
            if (firma(anterior) === firma(actual)) continue;
            cambios.push({ indice, anterior, actual });
        }
        if (!cambios.length) return;

        const bloque = document.createElement('div');
        bloque.className = 'purchase-detail-history-differences';
        const titulo = document.createElement('h5');
        titulo.textContent = 'Cambios en las líneas';
        bloque.appendChild(titulo);
        cambios.forEach(({ indice, anterior, actual }) => {
            const linea = document.createElement('p');
            const describir = detalle => {
                if (!detalle) return 'Línea retirada';
                const producto = detalle.producto || ('Producto #' + (detalle.id_producto || '—'));
                return producto + ' · ' + formatoCantidad.format(obtenerCantidadBase(detalle))
                    + ' unidades · ' + dinero(detalle.importe_final_linea)
                    + (detalle.numero_lote ? ' · Lote ' + detalle.numero_lote : '');
            };
            linea.textContent = 'Línea ' + (indice + 1) + ': '
                + describir(anterior) + ' → ' + describir(actual);
            bloque.appendChild(linea);
        });
        contenedor.appendChild(bloque);
    }

    function renderizarHistorial(compra, entradas, snapshots, snapshotOriginal) {
        historial.textContent = '';
        const registro = document.createElement('article');
        registro.className = 'purchase-detail-history-item';
        const tituloRegistro = document.createElement('h4');
        tituloRegistro.textContent = 'Registro original';
        const metaRegistro = document.createElement('p');
        metaRegistro.textContent = fechaHora(compra.created_at) + ' · ' + (compra.nombre_usuario || 'Usuario desconocido');
        const descripcionRegistro = document.createElement('p');
        descripcionRegistro.className = 'purchase-detail-history-change';
        if (snapshotOriginal) {
            const inicial = resumirSnapshot(snapshotOriginal, compra);
            descripcionRegistro.textContent = 'Compra confirmada con ' + inicial.detalles.length + ' líneas, '
                + formatoCantidad.format(inicial.unidades) + ' unidades ingresadas a inventario y total '
                + dinero(inicial.total) + '. Estos valores se conservan como evidencia.';
        } else if (compra.estado === 'Corregida') {
            descripcionRegistro.textContent = 'No se conservó una captura de la versión original de la compra.';
        } else {
            const inicial = resumirSnapshot(null, compra);
            descripcionRegistro.textContent = 'Compra confirmada con ' + inicial.detalles.length + ' líneas, '
                + formatoCantidad.format(inicial.unidades) + ' unidades ingresadas a inventario y total '
                + dinero(inicial.total) + '.';
        }
        registro.append(tituloRegistro, metaRegistro, descripcionRegistro);
        historial.appendChild(registro);

        entradas.forEach((entrada, indice) => {
            const item = document.createElement('article');
            item.className = 'purchase-detail-history-item';
            const titulo = document.createElement('h4');
            const esAnulacion = entrada.tipo_accion === 'Anulacion';
            titulo.textContent = esAnulacion ? 'Anulación' : 'Corrección';
            const responsable = entrada.nombre || entrada.usuario || 'Usuario desconocido';
            const meta = document.createElement('p');
            meta.textContent = fechaHora(entrada.created_at) + ' · ' + responsable;
            const motivo = document.createElement('p');
            motivo.className = 'purchase-detail-history-reason';
            motivo.textContent = 'Motivo: ' + (entrada.motivo || 'No registrado');
            item.append(titulo, meta, motivo);

            if (!esAnulacion) {
                const snapshotAntes = snapshots[indice];
                const snapshotDespues = snapshots[indice + 1] || null;
                const antes = resumirSnapshot(snapshotAntes, compra);
                const despues = snapshotDespues
                    ? resumirSnapshot(snapshotDespues, compra)
                    : resumirSnapshot(null, compra);
                agregarResumenCambio(item, 'Antes', antes);
                agregarResumenCambio(item, 'Después', despues);
                agregarDiferenciasCabecera(item, snapshotAntes, snapshotDespues);
                agregarDiferenciasDetalles(item, snapshotAntes, snapshotDespues);
                const efecto = document.createElement('p');
                efecto.className = 'purchase-detail-history-effect';
                efecto.textContent = obtenerEfectoInventario(antes.detalles, despues.detalles);
                item.appendChild(efecto);
            } else {
                const snapshotAnulacion = snapshots[indice];
                const estadoAnterior = snapshotAnulacion && snapshotAnulacion.cabecera
                    ? snapshotAnulacion.cabecera.estado
                    : (snapshotAnulacion && snapshotAnulacion.estado_anterior) || 'Vigente';
                const resultadoAnulacion = document.createElement('p');
                resultadoAnulacion.className = 'purchase-detail-history-effect';
                resultadoAnulacion.textContent = 'Estado anterior: ' + (estadoAnterior || 'Vigente')
                    + '. La compra quedó anulada.';
                item.appendChild(resultadoAnulacion);
                const efecto = document.createElement('p');
                efecto.className = 'purchase-detail-history-effect';
                efecto.textContent = snapshots[indice] && Array.isArray(snapshots[indice].detalles)
                    ? obtenerEfectoInventario(snapshots[indice].detalles, [])
                    : 'No hay líneas en la captura para calcular el efecto en inventario.';
                item.appendChild(efecto);
            }
            historial.appendChild(item);
        });

        if (compra.estado === 'Anulada' && !entradas.some(entrada => entrada.tipo_accion === 'Anulacion')) {
            const anulacion = document.createElement('article');
            anulacion.className = 'purchase-detail-history-item';
            const titulo = document.createElement('h4');
            titulo.textContent = 'Anulación';
            const meta = document.createElement('p');
            meta.textContent = fechaHora(compra.fecha_anulacion) + ' · Responsable no disponible';
            const motivo = document.createElement('p');
            motivo.className = 'purchase-detail-history-reason';
            motivo.textContent = 'Motivo: ' + (compra.motivo_anulacion || 'No registrado');
            anulacion.append(titulo, meta, motivo);
            historial.appendChild(anulacion);
        }
    }

    function renderizarVersion() {
        if (!compraActual) return;
        const detallesVigentes = Array.isArray(compraActual.detalles) ? compraActual.detalles : [];
        const enOriginal = estadoVersion === 'original';
        const cabeceraOriginal = snapshotCompraOriginal && snapshotCompraOriginal.cabecera
            ? snapshotCompraOriginal.cabecera
            : null;
        const detalles = enOriginal ? detallesOriginales : detallesVigentes;
        const referencia = !enOriginal ? detallesOriginales : [];
        const resumen = renderizarDetalles(detalles, referencia);
        texto('detalle_compra_suma', dinero(resumen.suma));
        texto('detalle_compra_unidades', formatoCantidad.format(resumen.unidades)
            + ' unidades en ' + detalles.length + ' líneas');
        texto('detalle_compra_total_unidades', formatoCantidad.format(resumen.unidades));
        texto('detalle_compra_total_lineas', dinero(resumen.suma));
        const conciliacion = document.getElementById('detalle_compra_conciliacion');
        const totalDocumento = enOriginal && cabeceraOriginal
            ? Number(cabeceraOriginal.total_documento ?? compraActual.total_documento) || 0
            : Number(compraActual.total_documento || 0);
        texto('detalle_compra_total', dinero(totalDocumento));
        const coincide = Math.abs(totalDocumento - resumen.suma) < 0.005;
        conciliacion.className = 'purchase-detail-reconciliation '
            + (coincide ? 'is-balanced' : 'is-unbalanced');
        conciliacion.textContent = coincide
            ? 'Totales coinciden'
            : 'Diferencia: ' + dinero(totalDocumento - resumen.suma);
        const etiqueta = document.querySelector('.purchase-detail-card-heading h3');
        if (etiqueta) etiqueta.textContent = enOriginal ? 'Productos recibidos (original)' : 'Productos recibidos';
        const numeroOriginal = cabeceraOriginal
            ? [cabeceraOriginal.serie, cabeceraOriginal.numero].filter(Boolean).join('-')
            : '';
        const tipoOriginal = cabeceraOriginal
            && String(cabeceraOriginal.id_tipo_comprobante) !== String(compraActual.id_tipo_comprobante)
            ? 'Tipo #' + cabeceraOriginal.id_tipo_comprobante
            : compraActual.tipo_comprobante;
        const identificadorOriginal = [tipoOriginal, numeroOriginal].filter(Boolean).join(' ');
        texto('detalle_compra_titulo', enOriginal && identificadorOriginal
            ? 'Versión original · ' + identificadorOriginal
            : tituloDocumentoActual);
        texto('detalle_compra_fecha_emision', enOriginal && cabeceraOriginal
            ? fecha(cabeceraOriginal.fecha_emision)
            : compraActual.fecha_emision);
        texto('detalle_compra_fecha_recepcion', enOriginal && cabeceraOriginal
            ? fecha(cabeceraOriginal.fecha_recepcion)
            : compraActual.fecha_recepcion);
        if (enOriginal && cabeceraOriginal
            && String(cabeceraOriginal.id_proveedor) !== String(compraActual.id_proveedor)) {
            texto('detalle_compra_proveedor', 'Proveedor #' + cabeceraOriginal.id_proveedor);
            texto('detalle_compra_documento_proveedor', 'Nombre histórico no conservado');
        } else {
            texto('detalle_compra_proveedor', compraActual.razon_social);
            texto('detalle_compra_documento_proveedor',
                [compraActual.nombre_tipo_documento, compraActual.numero_documento].filter(Boolean).join(' '));
        }
        const estadoCompra = enOriginal && cabeceraOriginal
            ? cabeceraOriginal.estado
            : (enOriginal && snapshotCompraOriginal && snapshotCompraOriginal.estado_anterior)
                || compraActual.estado;
        const etiquetaEstado = document.getElementById('detalle_compra_estado');
        etiquetaEstado.className = 'purchase-status purchase-status-' + ({
            Confirmada: 'confirmed',
            Corregida: 'corrected',
            Anulada: 'annulled'
        }[estadoCompra] || 'default');
        etiquetaEstado.textContent = estadoCompra || '—';
        avisoVersion.hidden = haySnapshotOriginal;
        avisoVersion.textContent = haySnapshotOriginal
            ? ''
            : 'No hay una captura histórica original disponible; se muestran los datos vigentes.';
        botonesVersion.forEach(boton => {
            const activo = boton.dataset.version === estadoVersion;
            boton.classList.toggle('is-active', activo);
            boton.setAttribute('aria-pressed', String(activo));
            if (boton.dataset.version === 'original') {
                boton.disabled = !haySnapshotOriginal;
            }
        });
    }

    async function cargarDetalle() {
        if (!/^[1-9]\d*$/.test(idCompra)) {
            mostrarError('El identificador de compra no es válido.');
            return;
        }

        error.hidden = true;
        contenido.hidden = true;
        cargando.hidden = false;
        try {
            const respuesta = await Api.get('compras/' + encodeURIComponent(idCompra));
            const resultado = respuesta && respuesta.data;
            if (!respuesta || !respuesta.ok || !resultado || !resultado.status || !resultado.data) {
                throw new Error(resultado ? resultado.message : 'No se pudo cargar el detalle de la compra.');
            }

            const compra = resultado.data;
            const documento = [compra.tipo_comprobante, [compra.serie, compra.numero].filter(Boolean).join('-')]
                .filter(Boolean).join(' ');
            tituloDocumentoActual = documento || 'Detalle de compra';
            texto('detalle_compra_titulo', tituloDocumentoActual);
            const estado = document.getElementById('detalle_compra_estado');
            estado.className = 'purchase-status purchase-status-' + ({
                Confirmada: 'confirmed',
                Corregida: 'corrected',
                Anulada: 'annulled'
            }[compra.estado] || 'default');
            estado.textContent = compra.estado || '—';
            estado.hidden = false;
            const compraActiva = compra.estado !== 'Anulada';
            if (botonCorregir) botonCorregir.hidden = !compraActiva;
            if (botonAnular) botonAnular.hidden = !compraActiva;

            texto('detalle_compra_proveedor', compra.razon_social);
            texto(
                'detalle_compra_documento_proveedor',
                [compra.nombre_tipo_documento, compra.numero_documento].filter(Boolean).join(' ')
            );
            texto('detalle_compra_fecha_emision', fecha(compra.fecha_emision));
            texto('detalle_compra_fecha_recepcion', fecha(compra.fecha_recepcion));
            texto('detalle_compra_usuario', compra.nombre_usuario);
            texto('detalle_compra_fecha_registro', fechaHora(compra.created_at));

            const detalles = Array.isArray(compra.detalles) ? compra.detalles : [];
            const entradas = Array.isArray(compra.historial) ? compra.historial : [];
            const snapshots = entradas.map(obtenerSnapshot);
            const indiceOriginal = entradas.findIndex(entrada => entrada.tipo_accion === 'Correccion');
            const indiceAnulacion = entradas.findIndex(entrada => entrada.tipo_accion === 'Anulacion');
            const snapshotOriginal = snapshots[indiceOriginal >= 0 ? indiceOriginal : indiceAnulacion];
            snapshotCompraOriginal = snapshotOriginal;
            haySnapshotOriginal = Boolean(snapshotOriginal && Array.isArray(snapshotOriginal.detalles));
            if (haySnapshotOriginal) {
                detallesOriginales = snapshotOriginal.detalles;
            } else {
                detallesOriginales = detalles;
                haySnapshotOriginal = compra.estado !== 'Corregida';
            }
            compraActual = compra;
            estadoVersion = 'vigente';
            renderizarVersion();
            renderizarHistorial(compra, entradas, snapshots, snapshotOriginal);
            cargando.hidden = true;
            contenido.hidden = false;
        } catch (exception) {
            mostrarError(exception.message || 'No se pudo cargar el detalle de la compra.');
        }
    }

    botonesVersion.forEach(boton => {
        boton.addEventListener('click', () => {
            estadoVersion = boton.dataset.version === 'original' ? 'original' : 'vigente';
            renderizarVersion();
        });
    });

    if (botonAnular && dialogoAnulacion) {
        const formularioAnulacion = document.getElementById('form_anular_compra');
        const motivoAnulacion = document.getElementById('compra_motivo_anulacion');
        const errorAnulacion = document.getElementById('compra_error_anulacion');
        const botonConfirmarAnulacion = document.getElementById('compra_confirmar_anulacion');
        const tablaEfecto = document.getElementById('compra_efecto_inventario');
        const estadoInventario = document.getElementById('compra_validacion_inventario');
        let efectoCalculado = false;
        let inventarioValido = false;

        function renderizarEfectoInventario(detalles) {
            tablaEfecto.textContent = '';
            const productos = new Map();
            detalles.forEach(detalle => {
                const id = String(detalle.id_producto);
                const producto = productos.get(id) || {
                    nombre: detalle.producto || 'Producto #' + id,
                    cantidad: 0,
                    stock: detalle.stock_actual == null ? null : Number(detalle.stock_actual)
                };
                producto.cantidad += obtenerCantidadBase(detalle);
                productos.set(id, producto);
            });

            if (!productos.size) {
                const fila = tablaEfecto.insertRow();
                const celda = fila.insertCell();
                celda.colSpan = 4;
                celda.className = 'purchase-detail-empty';
                celda.textContent = 'La compra no tiene productos que retirar.';
                efectoCalculado = true;
                inventarioValido = false;
                return;
            }

            inventarioValido = true;
            productos.forEach(producto => {
                const fila = tablaEfecto.insertRow();
                agregarCelda(fila, producto.nombre);
                agregarCelda(fila, formatoCantidad.format(producto.cantidad), 'purchase-detail-number');
                if (!Number.isFinite(producto.stock)) {
                    agregarCelda(fila, 'No disponible', 'purchase-detail-number');
                    agregarCelda(fila, 'No calculable', 'purchase-detail-number purchase-cancellation-stock-invalid');
                    inventarioValido = false;
                    return;
                }
                const saldoDespues = producto.stock - producto.cantidad;
                agregarCelda(fila, formatoCantidad.format(producto.stock), 'purchase-detail-number');
                agregarCelda(
                    fila,
                    formatoCantidad.format(saldoDespues) + (saldoDespues < 0 ? ' (no permitido)' : ''),
                    'purchase-detail-number' + (saldoDespues < 0 ? ' purchase-cancellation-stock-invalid' : '')
                );
                if (saldoDespues < 0) {
                    fila.classList.add('purchase-cancellation-row-invalid');
                    inventarioValido = false;
                }
            });

            efectoCalculado = true;
        }

        function actualizarEstadoConfirmacion() {
            const motivoValido = Boolean(motivoAnulacion.value.trim());
            botonConfirmarAnulacion.disabled = !efectoCalculado || !inventarioValido || !motivoValido;
        }

        async function abrirDialogoAnulacion() {
            motivoAnulacion.value = '';
            errorAnulacion.hidden = true;
            estadoInventario.className = 'purchase-cancellation-result';
            estadoInventario.textContent = 'Consultando el saldo actual del inventario…';
            tablaEfecto.textContent = '';
            const filaCarga = tablaEfecto.insertRow();
            const celdaCarga = filaCarga.insertCell();
            celdaCarga.colSpan = 4;
            celdaCarga.className = 'purchase-detail-empty';
            celdaCarga.textContent = 'Calculando efecto…';
            efectoCalculado = false;
            inventarioValido = false;
            actualizarEstadoConfirmacion();
            dialogoAnulacion.showModal();
            motivoAnulacion.focus();
            try {
                const respuesta = await Api.get('compras/' + encodeURIComponent(idCompra));
                const resultado = respuesta && respuesta.data;
                if (!respuesta || !respuesta.ok || !resultado || !resultado.status || !resultado.data) {
                    throw new Error(resultado ? resultado.message : 'No se pudo consultar el inventario.');
                }
                const compra = resultado.data;
                if (compra.estado === 'Anulada') {
                    throw new Error('Esta compra ya está anulada.');
                }
                document.getElementById('compra_documento_anulacion').textContent = [
                    compra.tipo_comprobante,
                    [compra.serie, compra.numero].filter(Boolean).join('-')
                ].filter(Boolean).join(' ');
                renderizarEfectoInventario(Array.isArray(compra.detalles) ? compra.detalles : []);
                if (inventarioValido) {
                    estadoInventario.classList.add('is-valid');
                    estadoInventario.textContent = 'La reversión es posible: ningún saldo queda negativo. Las ventas ya registradas se conservan.';
                } else {
                    estadoInventario.classList.add('is-invalid');
                    estadoInventario.textContent = 'No se puede anular directamente: retirar estas unidades dejaría al menos un saldo negativo o no fue posible calcularlo.';
                }
            } catch (exception) {
                efectoCalculado = true;
                inventarioValido = false;
                tablaEfecto.textContent = '';
                const fila = tablaEfecto.insertRow();
                const celda = fila.insertCell();
                celda.colSpan = 4;
                celda.className = 'purchase-detail-empty';
                celda.textContent = 'No se pudo calcular el efecto en inventario.';
                estadoInventario.classList.add('is-invalid');
                estadoInventario.textContent = exception.message || 'No se pudo consultar el inventario.';
            }
            actualizarEstadoConfirmacion();
        }

        botonAnular.addEventListener('click', abrirDialogoAnulacion);
        motivoAnulacion.addEventListener('input', actualizarEstadoConfirmacion);
        document.getElementById('compra_cancelar_anulacion').addEventListener('click', () => {
            dialogoAnulacion.close();
        });
        formularioAnulacion.addEventListener('submit', async event => {
            event.preventDefault();
            const motivo = motivoAnulacion.value.trim();
            if (!motivo) {
                errorAnulacion.textContent = 'El motivo de anulación es obligatorio.';
                errorAnulacion.hidden = false;
                motivoAnulacion.focus();
                return;
            }
            if (!efectoCalculado || !inventarioValido) {
                errorAnulacion.textContent = 'No se puede confirmar: la reversión dejaría un saldo negativo o no se pudo validar el inventario.';
                errorAnulacion.hidden = false;
                return;
            }
            botonConfirmarAnulacion.disabled = true;
            botonConfirmarAnulacion.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Anulando...';
            try {
                const response = await Api.request('compras/' + encodeURIComponent(idCompra), {
                    method: 'DELETE',
                    body: { motivo }
                });
                const result = response && response.data;
                if (!response || !response.ok || !result || !result.status) {
                    throw new Error(result ? result.message : 'No se pudo anular la compra.');
                }
                dialogoAnulacion.close();
                await Modal.success('Compra anulada', result.message || 'La compra fue anulada correctamente.');
                window.location.reload();
            } catch (exception) {
                errorAnulacion.textContent = exception.message || 'No se pudo anular la compra.';
                errorAnulacion.hidden = false;
            } finally {
                botonConfirmarAnulacion.disabled = false;
                botonConfirmarAnulacion.innerHTML = '<i class="fa-solid fa-ban" aria-hidden="true"></i> Confirmar anulación';
            }
        });
    }

    cargarDetalle();
}
