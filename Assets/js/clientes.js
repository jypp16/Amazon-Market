document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('tabla_clientes');
    const inputBusqueda = document.getElementById('busqueda_cliente');

    if (!tbody) return;

    let tiposDocumento = [];

    async function cargarClientes(busqueda = '') {
        try {
            tbody.textContent = '';
            const trLoad = document.createElement('tr');
            const tdLoad = document.createElement('td');
            tdLoad.colSpan = 7;
            tdLoad.style.textAlign = 'center';
            tdLoad.style.padding = '20px';
            tdLoad.style.color = '#888';
            const spinner = document.createElement('i');
            spinner.className = 'fa-solid fa-spinner fa-spin';
            tdLoad.appendChild(spinner);
            tdLoad.appendChild(document.createTextNode(' Cargando clientes...'));
            trLoad.appendChild(tdLoad);
            tbody.appendChild(trLoad);

            const endpoint = busqueda ? 'clientes?q=' + encodeURIComponent(busqueda) : 'clientes';
            const inicio = Date.now();
            const resultado = await Api.get(endpoint);
            const elapsed = Date.now() - inicio;
            if (elapsed < 800) await new Promise(r => setTimeout(r, 800 - elapsed));

            if (!resultado || !resultado.ok) {
                mostrarError('No se pudieron cargar los clientes.');
                return;
            }

            renderizarTabla(resultado.data.data || []);
        } catch (error) {
            mostrarError('Error de conexión: ' + error.message);
        }
    }

    function renderizarTabla(clientes) {
        tbody.textContent = '';

        if (clientes.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 7;
            td.textContent = 'No se encontraron clientes.';
            td.style.textAlign = 'center';
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }

        clientes.forEach(cli => {
            const fila = document.createElement('tr');

            const celdaTipo = document.createElement('td');
            const badgeTipo = document.createElement('span');
            badgeTipo.className = 'document-badge';
            badgeTipo.textContent = cli.tipo_documento || '-';
            celdaTipo.appendChild(badgeTipo);

            const celdaDoc = document.createElement('td');
            celdaDoc.className = 'font-semibold';
            celdaDoc.textContent = cli.nro_documento;

            const celdaNombre = document.createElement('td');
            celdaNombre.className = 'font-semibold';
            celdaNombre.textContent = cli.nombre;

            const celdaTel = document.createElement('td');
            celdaTel.textContent = cli.telefono || '-';

            const celdaEmail = document.createElement('td');
            celdaEmail.textContent = cli.email || '-';

            const celdaDir = document.createElement('td');
            celdaDir.textContent = cli.direccion || '-';

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
                btnEditar.addEventListener('click', () => abrirModalEditar(cli));
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
                btnEliminar.addEventListener('click', () => eliminarCliente(cli.id_cliente));
                divAcciones.appendChild(btnEliminar);
            }

            celdaAcciones.appendChild(divAcciones);
            fila.appendChild(celdaTipo);
            fila.appendChild(celdaDoc);
            fila.appendChild(celdaNombre);
            fila.appendChild(celdaTel);
            fila.appendChild(celdaEmail);
            fila.appendChild(celdaDir);
            fila.appendChild(celdaAcciones);
            tbody.appendChild(fila);
        });
    }

    async function cargarDropdowns() {
        try {
            const res = await Api.get('tipos-documento');
            if (res && res.ok) tiposDocumento = res.data.data || [];
        } catch (e) { /* ignore */ }
    }

    function abrirModalCrear() {
        ModalForm.open({
            titulo: 'Registrar Nuevo Cliente',
            endpoint: 'clientes',
            method: 'POST',
            btnText: 'Guardar Cliente',
            onSuccess: () => cargarClientes(),
            campos: [
                { name: 'id_tipo_documento', label: 'Tipo de Documento', type: 'select', col: 'col-6', required: true,
                    options: tiposDocumento.map(t => ({ value: t.id_tipo_documento, label: t.nombre })) },
                { name: 'nro_documento', label: 'Nro. Documento', type: 'text', col: 'col-6', required: true, placeholder: 'DNI: 8 dígitos' },
                { name: 'nombre', label: 'Nombre / Razón Social', type: 'text', col: 'col-6', required: true, placeholder: 'Nombre completo' },
                { name: 'telefono', label: 'Teléfono', type: 'text', col: 'col-6', placeholder: 'Número de celular' },
                { name: 'email', label: 'Correo Electrónico', type: 'email', col: 'col-6', placeholder: 'correo@ejemplo.com' },
                { name: 'direccion', label: 'Dirección', type: 'text', col: 'col-6', placeholder: 'Calle, Av, Jr...' }
            ],
            onMount: (form) => {
                const selTipo = form.querySelector('[name="id_tipo_documento"]');
                const inpDoc = form.querySelector('[name="nro_documento"]');
                if (!selTipo || !inpDoc) return;

                function actualizarHint() {
                    const val = parseInt(selTipo.value);
                    const hints = { 1: { max: 8, ph: 'DNI: 8 dígitos' }, 2: { max: 11, ph: 'RUC: 11 dígitos' }, 3: { max: 20, ph: 'Pasaporte: 5-20 caracteres' }, 4: { max: 20, ph: 'Carnet: 5-20 caracteres' } };
                    const h = hints[val] || { max: 20, ph: 'Número de documento' };
                    inpDoc.maxLength = h.max;
                    inpDoc.placeholder = h.ph;
                    inpDoc.pattern = val <= 2 ? '\\d{' + h.max + '}' : '[A-Za-z0-9]{5,20}';
                }
                actualizarHint();
                selTipo.addEventListener('change', actualizarHint);
            }
        });
    }

    function abrirModalEditar(cli) {
        ModalForm.open({
            titulo: 'Editar Cliente',
            endpoint: 'clientes',
            id: cli.id_cliente,
            method: 'PUT',
            btnText: 'Guardar Cambios',
            onSuccess: () => cargarClientes(),
            campos: [
                { name: 'id_tipo_documento', label: 'Tipo de Documento', type: 'select', col: 'col-6', required: true,
                    options: tiposDocumento.map(t => ({ value: t.id_tipo_documento, label: t.nombre })),
                    value: cli.id_tipo_documento },
                { name: 'nro_documento', label: 'Nro. Documento', type: 'text', col: 'col-6', required: true, value: cli.nro_documento },
                { name: 'nombre', label: 'Nombre / Razón Social', type: 'text', col: 'col-6', required: true, value: cli.nombre },
                { name: 'telefono', label: 'Teléfono', type: 'text', col: 'col-6', value: cli.telefono || '' },
                { name: 'email', label: 'Correo Electrónico', type: 'email', col: 'col-6', value: cli.email || '' },
                { name: 'direccion', label: 'Dirección', type: 'text', col: 'col-6', value: cli.direccion || '' }
            ],
            onMount: (form) => {
                const selTipo = form.querySelector('[name="id_tipo_documento"]');
                const inpDoc = form.querySelector('[name="nro_documento"]');
                if (!selTipo || !inpDoc) return;

                function actualizarHint() {
                    const val = parseInt(selTipo.value);
                    const hints = { 1: { max: 8, ph: 'DNI: 8 dígitos' }, 2: { max: 11, ph: 'RUC: 11 dígitos' }, 3: { max: 20, ph: 'Pasaporte: 5-20 caracteres' }, 4: { max: 20, ph: 'Carnet: 5-20 caracteres' } };
                    const h = hints[val] || { max: 20, ph: 'Número de documento' };
                    inpDoc.maxLength = h.max;
                    inpDoc.placeholder = h.ph;
                }
                actualizarHint();
                selTipo.addEventListener('change', actualizarHint);
            }
        });
    }

    async function eliminarCliente(id) {
        const confirmado = await Modal.confirm('Confirmar Eliminación', '¿Está seguro de dar de baja a este cliente?', 'danger');
        if (!confirmado) return;

        try {
            const resultado = await Api.delete('clientes/' + id);
            if (resultado && resultado.ok) {
                await Modal.success('Eliminado', resultado.data.message || 'Cliente eliminado correctamente.');
                cargarClientes();
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
        td.colSpan = 7;
        td.textContent = mensaje;
        td.style.textAlign = 'center';
        td.style.color = '#dc3545';
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    document.getElementById('btn_crear_cliente')?.addEventListener('click', () => {
        if (tiposDocumento.length === 0) {
            Modal.error('Error', 'Los tipos de documento no se han cargado.');
            return;
        }
        abrirModalCrear();
    });

    if (inputBusqueda) {
        inputBusqueda.addEventListener('input', debounce(() => {
            cargarClientes(inputBusqueda.value);
        }, 350));
    }

    cargarDropdowns();
    cargarClientes();
});
