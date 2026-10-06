document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('tabla_productos');
    const inputBusqueda = document.getElementById('busqueda_producto');
    const filtroCategoria = document.getElementById('filtro_categoria');
    const paginacionDiv = document.getElementById('paginacion_productos');

    if (!tbody) return;

    let categorias = [];
    let unidades = [];
    let paginaActual = 1;
    const porPagina = 10;
    let ultimaPaginaInfo = { page: 1, per_page: 10, total: 0, total_pages: 1 };

    async function cargarProductos(busqueda, categoria, pagina) {
        if (busqueda === undefined) busqueda = inputBusqueda ? inputBusqueda.value : '';
        if (categoria === undefined) categoria = filtroCategoria ? filtroCategoria.value : '';
        if (pagina === undefined) pagina = 1;
        paginaActual = pagina;

        try {
            tbody.textContent = '';
            const trLoad = document.createElement('tr');
            const tdLoad = document.createElement('td');
            tdLoad.colSpan = 6;
            tdLoad.style.textAlign = 'center';
            tdLoad.style.padding = '20px';
            tdLoad.style.color = '#888';
            const spinner = document.createElement('i');
            spinner.className = 'fa-solid fa-spinner fa-spin';
            tdLoad.appendChild(spinner);
            tdLoad.appendChild(document.createTextNode(' Cargando productos...'));
            trLoad.appendChild(tdLoad);
            tbody.appendChild(trLoad);

            let endpoint = 'productos';
            const params = [];
            if (busqueda) params.push('q=' + encodeURIComponent(busqueda));
            if (categoria) params.push('categoria=' + encodeURIComponent(categoria));
            params.push('page=' + paginaActual);
            params.push('limit=' + porPagina);
            endpoint += '?' + params.join('&');

            const inicio = Date.now();
            const resultado = await Api.get(endpoint);
            const elapsed = Date.now() - inicio;
            if (elapsed < 800) await new Promise(r => setTimeout(r, 800 - elapsed));

            if (!resultado || !resultado.ok) {
                mostrarError('No se pudieron cargar los productos.');
                return;
            }

            ultimaPaginaInfo = resultado.data.pagination || { page: 1, per_page: 10, total: 0, total_pages: 1 };
            renderizarTabla(resultado.data.data || []);
            renderizarPaginacion();
        } catch (error) {
            mostrarError('Error de conexión: ' + error.message);
        }
    }

    function renderizarPaginacion() {
        if (!paginacionDiv) return;
        paginacionDiv.textContent = '';

        const { page, per_page, total, total_pages } = ultimaPaginaInfo;
        if (total_pages <= 1) return;

        const info = document.createElement('span');
        info.className = 'pag-info';
        const inicio = (page - 1) * per_page + 1;
        const fin = Math.min(page * per_page, total);
        info.textContent = 'Mostrando ' + inicio + '-' + fin + ' de ' + total;
        paginacionDiv.appendChild(info);

        const btnGroup = document.createElement('div');
        btnGroup.className = 'pag-btns';

        const btnPrev = document.createElement('button');
        btnPrev.type = 'button';
        btnPrev.className = 'pag-btn';
        btnPrev.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
        btnPrev.disabled = page <= 1;
        btnPrev.addEventListener('click', () => cargarProductos(undefined, undefined, page - 1));
        btnGroup.appendChild(btnPrev);

        const rango = calcularRango(page, total_pages);
        rango.forEach(p => {
            if (p === '...') {
                const dots = document.createElement('span');
                dots.className = 'pag-dots';
                dots.textContent = '...';
                btnGroup.appendChild(dots);
            } else {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pag-btn' + (p === page ? ' pag-active' : '');
                btn.textContent = p;
                btn.addEventListener('click', () => cargarProductos(undefined, undefined, p));
                btnGroup.appendChild(btn);
            }
        });

        const btnNext = document.createElement('button');
        btnNext.type = 'button';
        btnNext.className = 'pag-btn';
        btnNext.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
        btnNext.disabled = page >= total_pages;
        btnNext.addEventListener('click', () => cargarProductos(undefined, undefined, page + 1));
        btnGroup.appendChild(btnNext);

        paginacionDiv.appendChild(btnGroup);
    }

    function calcularRango(actual, total) {
        if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
        const r = [];
        r.push(1);
        if (actual > 3) r.push('...');
        for (let i = Math.max(2, actual - 1); i <= Math.min(total - 1, actual + 1); i++) {
            r.push(i);
        }
        if (actual < total - 2) r.push('...');
        r.push(total);
        return r;
    }

    function renderizarTabla(productos) {
        tbody.textContent = '';

        if (productos.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 6;
            td.textContent = 'No se encontraron productos.';
            td.style.textAlign = 'center';
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }

        productos.forEach(prod => {
            const fila = document.createElement('tr');

            const celdaCodigo = document.createElement('td');
            const badgeCodigo = document.createElement('span');
            badgeCodigo.className = 'barcode-badge';
            badgeCodigo.textContent = prod.codigo_barra;
            celdaCodigo.appendChild(badgeCodigo);

            const celdaNombre = document.createElement('td');
            celdaNombre.className = 'font-semibold';
            celdaNombre.textContent = prod.nombre;

            const celdaCategoria = document.createElement('td');
            const badgeCat = document.createElement('span');
            badgeCat.className = 'badge-neutral';
            badgeCat.textContent = prod.categoria;
            celdaCategoria.appendChild(badgeCat);

            const celdaStock = document.createElement('td');
            const stockBadge = document.createElement('span');
            const stock = parseFloat(prod.stock_actual);
            const minimo = parseFloat(prod.stock_minimo);
            stockBadge.className = stock <= minimo ? 'stock-badge stock-low' : 'stock-badge stock-normal';
            stockBadge.textContent = stock.toFixed(2) + ' ' + prod.unidad + (stock <= minimo ? ' (Bajo)' : '');
            celdaStock.appendChild(stockBadge);

            const celdaPrecio = document.createElement('td');
            celdaPrecio.className = 'price-text';
            celdaPrecio.textContent = 'S/. ' + parseFloat(prod.precio_venta).toFixed(2);

            const celdaAcciones = document.createElement('td');
            const divAcciones = document.createElement('div');
            divAcciones.className = 'actions-group';

            if (typeof canEdit !== 'undefined' && canEdit) {
                const btnEditar = document.createElement('button');
                btnEditar.type = 'button';
                btnEditar.className = 'btn btn-edit';
                btnEditar.title = 'Editar';
                const iconEdit = document.createElement('i');
                iconEdit.className = 'fa-solid fa-pen-to-square';
                btnEditar.appendChild(iconEdit);
                btnEditar.addEventListener('click', () => abrirModalEditar(prod));
                divAcciones.appendChild(btnEditar);
            }

            if (typeof canDelete !== 'undefined' && canDelete) {
                const btnEliminar = document.createElement('button');
                btnEliminar.type = 'button';
                btnEliminar.className = 'btn btn-delete';
                btnEliminar.title = 'Eliminar';
                const iconDel = document.createElement('i');
                iconDel.className = 'fa-solid fa-trash-can';
                btnEliminar.appendChild(iconDel);
                btnEliminar.addEventListener('click', () => eliminarProducto(prod.id_producto));
                divAcciones.appendChild(btnEliminar);
            }

            celdaAcciones.appendChild(divAcciones);
            fila.appendChild(celdaCodigo);
            fila.appendChild(celdaNombre);
            fila.appendChild(celdaCategoria);
            fila.appendChild(celdaStock);
            fila.appendChild(celdaPrecio);
            fila.appendChild(celdaAcciones);
            tbody.appendChild(fila);
        });
    }

    async function cargarDropdowns() {
        try {
            const [catRes, uniRes] = await Promise.all([
                Api.get('categorias'),
                Api.get('unidades')
            ]);
            if (catRes && catRes.ok) categorias = catRes.data.data || [];
            if (uniRes && uniRes.ok) unidades = uniRes.data.data || [];

            if (filtroCategoria && categorias.length) {
                categorias.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id_categoria;
                    opt.textContent = c.nombre;
                    filtroCategoria.appendChild(opt);
                });
            }
        } catch (e) { /* ignore */ }
    }

    function getUnidadesDecimales() {
        return ['KG', 'LTR', 'LT', 'ML', 'G', 'OZ', 'LB', 'GAL', 'M', 'CM'];
    }

    function getUnidadById(id) {
        return unidades.find(u => u.id_unidad == id);
    }

    function unidadesPermitenDecimales(idUnidad) {
        const u = getUnidadById(idUnidad);
        if (!u) return false;
        return getUnidadesDecimales().includes(u.abreviatura.toUpperCase());
    }

    function aplicarRestriccionStock(form) {
        const selUnidad = form.querySelector('[name="id_unidad"]');
        const inpStock = form.querySelector('[name="stock_actual"]');
        const inpMinimo = form.querySelector('[name="stock_minimo"]');
        if (!selUnidad || !inpStock || !inpMinimo) return;

        function actualizar() {
            const decimales = unidadesPermitenDecimales(selUnidad.value);
            inpStock.step = decimales ? '0.01' : '1';
            inpStock.min = '0';
            inpStock.placeholder = decimales ? '0.00' : '0';
            inpMinimo.step = decimales ? '0.01' : '1';
            inpMinimo.min = '0';
            inpMinimo.placeholder = decimales ? '0.00' : '1';
        }

        selUnidad.addEventListener('change', actualizar);
        actualizar();
    }

    function abrirModalCrear() {
        ModalForm.open({
            titulo: 'Registrar Nuevo Producto',
            endpoint: 'productos',
            method: 'POST',
            btnText: 'Guardar Producto',
            width: '650px',
            onSuccess: () => cargarProductos(),
            onMount: aplicarRestriccionStock,
            campos: [
                { name: 'imagen', label: 'Imagen del Producto', type: 'file', col: 'col-12', accept: 'image/jpeg,image/png,image/webp', hint: 'Opcional. JPG, PNG o WEBP (max 2MB). Se guarda con el código de barras.' },
                { name: 'codigo_barra', label: 'Código de Barra', type: 'text', col: 'col-6', required: true, placeholder: 'Escriba o escanee el código' },
                { name: 'nombre', label: 'Nombre del Producto', type: 'text', col: 'col-6', required: true, placeholder: 'Nombre comercial' },
                { name: 'descripcion', label: 'Descripción', type: 'textarea', col: 'col-12', placeholder: 'Detalles del producto...' },
                { name: 'id_categoria', label: 'Categoría', type: 'select', col: 'col-6', required: true,
                    options: categorias.map(c => ({ value: c.id_categoria, label: c.nombre })) },
                { name: 'id_unidad', label: 'Unidad de Medida', type: 'select', col: 'col-6', required: true,
                    options: unidades.map(u => ({ value: u.id_unidad, label: u.nombre + ' (' + u.abreviatura + ')' })) },
                { name: 'precio_venta', label: 'Precio de Venta (S/.)', type: 'number', col: 'col-4', required: true, step: '0.01', min: '0', placeholder: '0.00' },
                { name: 'stock_actual', label: 'Stock Inicial', type: 'number', col: 'col-4', required: true, step: '1', min: '0', placeholder: '0' },
                { name: 'stock_minimo', label: 'Stock Mínimo', type: 'number', col: 'col-4', required: true, step: '1', min: '0', placeholder: '1' }
            ]
        });
    }

    function abrirModalEditar(prod) {
        ModalForm.open({
            titulo: 'Editar Producto',
            endpoint: 'productos',
            id: prod.id_producto,
            method: 'PUT',
            btnText: 'Guardar Cambios',
            width: '650px',
            onSuccess: () => cargarProductos(),
            onMount: aplicarRestriccionStock,
            campos: [
                { name: 'imagen', label: 'Imagen del Producto', type: 'file', col: 'col-12', accept: 'image/jpeg,image/png,image/webp', hint: 'Opcional. Si sube una nueva imagen, reemplazará la actual.' },
                { name: 'codigo_barra', label: 'Código de Barra', type: 'text', col: 'col-6', required: true, value: prod.codigo_barra },
                { name: 'nombre', label: 'Nombre del Producto', type: 'text', col: 'col-6', required: true, value: prod.nombre },
                { name: 'descripcion', label: 'Descripción', type: 'textarea', col: 'col-12', value: prod.descripcion },
                { name: 'id_categoria', label: 'Categoría', type: 'select', col: 'col-6', required: true,
                    options: categorias.map(c => ({ value: c.id_categoria, label: c.nombre })),
                    value: prod.id_categoria },
                { name: 'id_unidad', label: 'Unidad de Medida', type: 'select', col: 'col-6', required: true,
                    options: unidades.map(u => ({ value: u.id_unidad, label: u.nombre + ' (' + u.abreviatura + ')' })),
                    value: prod.id_unidad },
                { name: 'precio_venta', label: 'Precio de Venta (S/.)', type: 'number', col: 'col-4', required: true, step: '0.01', min: '0', value: prod.precio_venta },
                { name: 'stock_actual', label: 'Stock Actual', type: 'number', col: 'col-4', required: true, step: '0.01', min: '0', value: prod.stock_actual },
                { name: 'stock_minimo', label: 'Stock Mínimo', type: 'number', col: 'col-4', required: true, step: '0.01', min: '0', value: prod.stock_minimo }
            ]
        });
    }

    async function eliminarProducto(id) {
        const confirmado = await Modal.confirm('Confirmar Eliminación', '¿Está seguro de eliminar este producto? Esta acción no se puede deshacer.', 'danger');
        if (!confirmado) return;

        try {
            const resultado = await Api.delete('productos/' + id);
            if (resultado && resultado.ok) {
                await Modal.success('Eliminado', resultado.data.message || 'Producto eliminado correctamente.');
                cargarProductos();
            } else {
                await Modal.error('Error', resultado ? resultado.data.message : 'No se pudo eliminar.');
            }
        } catch (error) {
            await Modal.error('Error', 'Error de conexión: ' + error.message);
        }
    }

    function mostrarError(mensaje) {
        tbody.textContent = '';
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 6;
        td.textContent = mensaje;
        td.style.textAlign = 'center';
        td.style.color = '#dc3545';
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    document.getElementById('btn_crear_producto')?.addEventListener('click', () => {
        if (categorias.length === 0 || unidades.length === 0) {
            Modal.error('Error', 'Los datos de categorías y unidades no se han cargado.');
            return;
        }
        abrirModalCrear();
    });

    if (inputBusqueda) {
        inputBusqueda.addEventListener('input', debounce(() => {
            cargarProductos(inputBusqueda.value, filtroCategoria ? filtroCategoria.value : '');
        }, 350));
    }

    if (filtroCategoria) {
        filtroCategoria.addEventListener('change', () => {
            cargarProductos(inputBusqueda ? inputBusqueda.value : '', filtroCategoria.value);
        });
    }

    cargarDropdowns();
    cargarProductos();
});
