document.addEventListener('DOMContentLoaded', () => {
    cargarProveedores();

    const inputBusqueda = document.getElementById('busqueda_proveedor');
    if (inputBusqueda) {
        const debounceFn = (typeof debounce === 'function') 
            ? debounce 
            : (fn, ms) => {
                let t;
                return (...args) => {
                    clearTimeout(t);
                    t = setTimeout(() => fn(...args), ms);
                };
            };

        inputBusqueda.addEventListener('input', debounceFn((e) => {
            const query = e.target.value.trim();
            cargarProveedores(1, query);
        }, 350));
    }

    const botonCrear = document.getElementById('btn_crear_proveedor');

    botonCrear?.addEventListener('click', async () => {
        try {
            const respuesta = await fetch(BASE_URL + '/Proveedor/crear', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!respuesta.ok) {
                throw new Error('No se pudo cargar el formulario de proveedor.');
            }

            const contenido = await respuesta.text();
            ModalForm.open({
                titulo: 'Registrar Nuevo Proveedor',
                width: '700px',
                contentHtml: contenido,
                onMount: () => inicializarFormularioProveedor()
            });
        } catch (error) {
            await Modal.error('Error', error.message);
        }
    });

    document.getElementById('tabla_proveedores')?.addEventListener('click', event => {
        const botonEditar = event.target.closest('.btn-edit');
        const fila = botonEditar?.closest('tr');
        if (fila?.dataset.proveedorId) {
            abrirModalEditarProveedor(fila.dataset.proveedorId);
        }
    });

    async function abrirModalEditarProveedor(id) {
        try {
            const [respuestaFormulario, respuestaProveedor] = await Promise.all([
                fetch(BASE_URL + '/Proveedor/crear', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }),
                Api.get('proveedores/' + id)
            ]);

            if (!respuestaFormulario.ok) {
                throw new Error('No se pudo cargar el formulario de proveedor.');
            }

            const proveedorJson = respuestaProveedor && respuestaProveedor.data;
            if (!respuestaProveedor || !respuestaProveedor.ok || !proveedorJson || !proveedorJson.status) {
                throw new Error(proveedorJson ? proveedorJson.message : 'No se pudieron cargar los datos del proveedor.');
            }

            const contenido = await respuestaFormulario.text();
            ModalForm.open({
                titulo: 'Editar Proveedor',
                width: '700px',
                contentHtml: contenido,
                onMount: () => inicializarFormularioProveedor(proveedorJson.data)
            });
        } catch (error) {
            await Modal.error('Error', error.message);
        }
    }

    function inicializarFormularioProveedor(proveedor = null) {
        const formulario = document.getElementById('form_nuevo_proveedor');
        if (!formulario) return;

        const tipoDocumento = formulario.elements.id_tipo_documento;
        const numeroDocumento = formulario.elements.nro_documento;
        const nombre = formulario.elements.nombre;
        const estado = formulario.elements.estado;
        const telefono = formulario.elements.telefono;
        if (proveedor) {
            formulario.dataset.proveedorId = proveedor.id_proveedor;
            tipoDocumento.value = String(proveedor.id_tipo_documento);
            numeroDocumento.value = proveedor.numero_documento;
            nombre.value = proveedor.razon_social;
            estado.value = String(proveedor.estado);
            telefono.value = proveedor.telefono;
            formulario.querySelector('.btn-gold').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Guardar Cambios';
        }

        let documentoTouched = false;
        let nombreTouched = false;
        let telefonoTouched = false;

        function actualizarValidacionDocumento() {
            const esDni = tipoDocumento.value === '1';
            const digitos = esDni ? 8 : 11;

            numeroDocumento.maxLength = digitos;
            numeroDocumento.pattern = '[0-9]{' + digitos + '}';
            numeroDocumento.placeholder = digitos + ' dígitos';
            if (documentoTouched) validarDocumento();
        }

        function mostrarError(campo, mensaje) {
            const error = document.getElementById(
                campo === numeroDocumento ? 'error_proveedor_documento' :
                campo === nombre ? 'error_proveedor_nombre' :
                'error_proveedor_telefono'
            );
            error.textContent = mensaje;
            campo.classList.toggle('is-invalid', Boolean(mensaje));
            campo.setAttribute('aria-invalid', mensaje ? 'true' : 'false');
        }

        function validarDocumento() {
            const digitos = tipoDocumento.value === '1' ? 8 : 11;
            const tipo = tipoDocumento.value === '1' ? 'DNI' : 'RUC';
            const mensaje = !numeroDocumento.value.trim()
                ? 'Ingresa el número de ' + tipo + '.'
                : !new RegExp('^[0-9]{' + digitos + '}$').test(numeroDocumento.value.trim())
                    ? 'El ' + tipo + ' debe contener exactamente ' + digitos + ' dígitos.'
                    : '';
            mostrarError(numeroDocumento, mensaje);
            return !mensaje;
        }

        function validarNombre() {
            const valor = nombre.value.trim();
            const mensaje = !valor
                ? 'Ingresa el nombre o razón social.'
                : valor.length < 2
                    ? 'Debe contener al menos 2 caracteres.'
                    : valor.length > 150
                        ? 'No puede superar los 150 caracteres.'
                        : '';
            mostrarError(nombre, mensaje);
            return !mensaje;
        }

        function validarTelefono() {
            const cantidadDigitos = telefono.value.replace(/\D/g, '').length;
            const soloCaracteresTelefono = /^[0-9+()\s.-]+$/.test(telefono.value);
            const mensaje = !telefono.value.trim()
                ? 'Ingresa el teléfono.'
                : !soloCaracteresTelefono
                    ? 'El teléfono solo puede contener números y los símbolos + ( ) - .'
                    : cantidadDigitos < 7 || cantidadDigitos > 15
                        ? 'El teléfono debe contener entre 7 y 15 dígitos.'
                        : '';
            mostrarError(telefono, mensaje);
            return !mensaje;
        }

        formulario.querySelectorAll('[data-custom-select]').forEach(crearSelectPersonalizado);
        tipoDocumento.addEventListener('change', () => {
            actualizarValidacionDocumento();
            evaluarEstadoBoton();
        });
        estado.addEventListener('change', evaluarEstadoBoton);
        numeroDocumento.addEventListener('input', () => {
            if (documentoTouched) validarDocumento();
            evaluarEstadoBoton();
        });
        numeroDocumento.addEventListener('blur', () => {
            documentoTouched = true;
            validarDocumento();
            evaluarEstadoBoton();
        });
        nombre.addEventListener('input', () => {
            if (nombreTouched) validarNombre();
            evaluarEstadoBoton();
        });
        nombre.addEventListener('blur', () => {
            nombreTouched = true;
            validarNombre();
            evaluarEstadoBoton();
        });
        telefono.addEventListener('input', () => {
            if (telefonoTouched) validarTelefono();
            evaluarEstadoBoton();
        });
        telefono.addEventListener('blur', () => {
            telefonoTouched = true;
            validarTelefono();
            evaluarEstadoBoton();
        });
        tipoDocumento.value = tipoDocumento.value || '2';
        actualizarValidacionDocumento();

        function evaluarEstadoBoton() {
            const btnGuardar = formulario.querySelector('.btn-gold');
            if (!btnGuardar) return;

            const digitos = tipoDocumento.value === '1' ? 8 : 11;
            const docValido = new RegExp('^[0-9]{' + digitos + '}$').test(numeroDocumento.value.trim());
            const nomValido = nombre.value.trim().length >= 2 && nombre.value.trim().length <= 150;
            
            const cantidadDigitos = telefono.value.replace(/\D/g, '').length;
            const soloCaracteresTelefono = /^[0-9+()\s.-]+$/.test(telefono.value);
            const telValido = telefono.value.trim() && soloCaracteresTelefono && cantidadDigitos >= 7 && cantidadDigitos <= 15;

            btnGuardar.disabled = !(docValido && nomValido && telValido);
        }

        evaluarEstadoBoton();

        const btnGuardar = formulario.querySelector('.btn-gold');
        if (btnGuardar) {
            btnGuardar.addEventListener('click', async () => {
                btnGuardar.disabled = true;
                const originalHtml = btnGuardar.innerHTML;
                btnGuardar.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
                
                try {
                    const formData = new FormData(formulario);
                    const data = Object.fromEntries(formData.entries());

                    const proveedorId = formulario.dataset.proveedorId;
                    const endpoint = proveedorId ? 'proveedores/' + proveedorId : 'proveedores';
                    const respuesta = await Api.request(endpoint, {
                        method: proveedorId ? 'PUT' : 'POST',
                        body: data
                    });
                    const json = respuesta && respuesta.data;
                    if (!respuesta || !respuesta.ok || !json || !json.status) {
                        throw new Error(json ? json.message : 'Error al guardar el proveedor.');
                    }
                    
                    document.querySelector('.modal-close-btn').click();
                    if (typeof Modal !== 'undefined' && Modal.success) {
                        await Modal.success(
                            '¡Éxito!',
                            proveedorId ? 'Proveedor actualizado correctamente.' : 'Proveedor registrado correctamente.'
                        );
                    } else {
                        alert(proveedorId ? 'Proveedor actualizado correctamente.' : 'Proveedor registrado correctamente.');
                    }
                    if (typeof cargarProveedores === 'function') cargarProveedores();
                } catch (error) {
                    if (typeof Modal !== 'undefined' && Modal.error) {
                        await Modal.error('Error', error.message);
                    } else {
                        alert('Error: ' + error.message);
                    }
                    btnGuardar.disabled = false;
                    btnGuardar.innerHTML = originalHtml;
                }
            });
        }

        function crearSelectPersonalizado(select) {
            const wrapper = document.createElement('div');
            wrapper.className = 'custom-select';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            select.classList.add('custom-select-native');
            select.tabIndex = -1;
            select.setAttribute('aria-hidden', 'true');

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'custom-select-trigger';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');

            const label = document.createElement('span');
            const arrow = document.createElement('i');
            arrow.className = 'fa-solid fa-chevron-down';
            arrow.setAttribute('aria-hidden', 'true');
            trigger.append(label, arrow);

            const list = document.createElement('div');
            list.className = 'custom-select-options';
            list.setAttribute('role', 'listbox');
            list.hidden = true;

            function syncSelection() {
                const selected = select.options[select.selectedIndex];
                label.textContent = selected ? selected.textContent : '';
                trigger.classList.toggle('is-placeholder', !select.value);
                Array.from(list.children).forEach((optionButton, index) => {
                    const isSelected = select.options[index].value === select.value;
                    optionButton.classList.toggle('is-selected', isSelected);
                    optionButton.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                });
            }

            Array.from(select.options).forEach((option, index) => {
                const optionButton = document.createElement('button');
                optionButton.type = 'button';
                optionButton.className = 'custom-select-option';
                optionButton.setAttribute('role', 'option');
                optionButton.setAttribute('aria-selected', 'false');
                optionButton.textContent = option.textContent;

                if (option.value === '') {
                    optionButton.disabled = true;
                    optionButton.classList.add('is-placeholder');
                }

                const check = document.createElement('i');
                check.className = 'fa-solid fa-check';
                check.setAttribute('aria-hidden', 'true');
                optionButton.appendChild(check);

                optionButton.addEventListener('click', () => {
                    select.selectedIndex = index;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    syncSelection();
                    list.hidden = true;
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                });
                list.appendChild(optionButton);
            });

            trigger.addEventListener('click', () => {
                const abrir = list.hidden;
                document.querySelectorAll('.custom-select-options').forEach(otherList => {
                    otherList.hidden = true;
                    otherList.parentElement.querySelector('.custom-select-trigger').setAttribute('aria-expanded', 'false');
                });
                list.hidden = !abrir;
                trigger.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            });

            trigger.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    list.hidden = true;
                    trigger.setAttribute('aria-expanded', 'false');
                    return;
                }
                if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    list.hidden = false;
                    trigger.setAttribute('aria-expanded', 'true');
                    const firstEnabled = list.querySelector('.custom-select-option:not(:disabled)');
                    if (firstEnabled) firstEnabled.focus();
                }
            });

            list.addEventListener('keydown', event => {
                const options = Array.from(list.querySelectorAll('.custom-select-option:not(:disabled)'));
                const currentIndex = options.indexOf(document.activeElement);
                if (event.key === 'Escape') {
                    list.hidden = true;
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    const direction = event.key === 'ArrowDown' ? 1 : -1;
                    options[(currentIndex + direction + options.length) % options.length].focus();
                }
            });

            document.addEventListener('click', event => {
                if (!wrapper.contains(event.target)) {
                    list.hidden = true;
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });

            wrapper.append(trigger, list);
            select.addEventListener('change', syncSelection);
            syncSelection();
        }
    }
});

let paginaActual = 1;
let busquedaActual = '';
const porPagina = 10;

async function cargarProveedores(pagina = paginaActual, busqueda = busquedaActual) {
    paginaActual = pagina;
    busquedaActual = busqueda;

    const tbody = document.getElementById('tabla_proveedores');
    if (!tbody) return;

    try {
        const params = new URLSearchParams({
            search: busquedaActual,
            page: paginaActual,
            limit: porPagina
        });

        const respuesta = await fetch(BASE_URL + '/api/proveedores?' + params.toString(), {
            headers: { 'Accept': 'application/json' }
        });
        const json = await respuesta.json();
        
        if (!json.status) throw new Error(json.message);

        const proveedores = json.data || [];
        if (proveedores.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="table-empty">Aún no hay proveedores para mostrar.</td></tr>';
            renderizarPaginacion({
                page: 1,
                total_pages: 1,
                total: 0,
                per_page: porPagina
            });
            return;
        }

        tbody.innerHTML = '';
        proveedores.forEach(prov => {
            const tr = document.createElement('tr');
            tr.dataset.proveedorId = prov.id_proveedor;
            const proveedorActivo = Number(prov.estado) === 1;
            const estadoBadge = proveedorActivo
                ? '<span class="proveedor-estado-badge proveedor-estado-activo">Activo</span>'
                : '<span class="proveedor-estado-badge proveedor-estado-inactivo">Inactivo</span>';
            tr.innerHTML = `
                <td>${prov.nombre_tipo_documento}</td>
                <td>${prov.numero_documento}</td>
                <td>${prov.razon_social}</td>
                <td>${prov.telefono}</td>
                <td>${estadoBadge}</td>
                <td>
                    <div class="actions-group">
                        <button type="button" class="btn btn-edit" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button type="button" class="btn btn-delete" title="${proveedorActivo ? 'Eliminar' : 'Proveedor inactivo'}" aria-label="${proveedorActivo ? 'Eliminar proveedor' : 'Proveedor inactivo; eliminación deshabilitada'}" ${proveedorActivo ? '' : 'disabled'}>
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                </td>
            `;
            const btnEliminar = tr.querySelector('.btn-delete');
            if (btnEliminar) {
                btnEliminar.addEventListener('click', () => eliminarProveedor(prov.id_proveedor));
            }
            tbody.appendChild(tr);
        });
        
        renderizarPaginacion({
            page: json.pagina_actual || 1,
            total_pages: json.paginas || 1,
            total: json.total || 0,
            per_page: json.por_pagina || porPagina
        });
    } catch (error) {
        console.error(error);
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:#dc3545; padding:20px;">Error al cargar proveedores: ${error.message}</td></tr>`;
    }
}

function renderizarPaginacion(info) {
    const pagInfoSpan = document.querySelector('.pag-info');
    const pagBtnsDiv = document.querySelector('.pag-btns');

    if (!pagBtnsDiv) return;

    const { page, total_pages, total, per_page } = info;

    if (pagInfoSpan) {
        if (total === 0) {
            pagInfoSpan.textContent = 'Mostrando 0 proveedores';
        } else {
            const inicio = (page - 1) * per_page + 1;
            const fin = Math.min(page * per_page, total);

            pagInfoSpan.textContent =
                `Mostrando ${inicio}-${fin} de ${total} proveedores`;
        }
    }

    pagBtnsDiv.innerHTML = '';

    // Botón Anterior
    const btnPrev = document.createElement('button');
    btnPrev.type = 'button';
    btnPrev.className = 'pag-btn';
    btnPrev.setAttribute('aria-label', 'Página anterior');
    btnPrev.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
    btnPrev.disabled = page <= 1;

    btnPrev.addEventListener('click', () => {
        if (page > 1) {
            cargarProveedores(page - 1, busquedaActual);
        }
    });

    pagBtnsDiv.appendChild(btnPrev);

    // Botones numéricos
    for (let p = 1; p <= total_pages; p++) {
        const btnPage = document.createElement('button');

        btnPage.type = 'button';
        btnPage.className =
            'pag-btn' + (p === page ? ' pag-active' : '');
        btnPage.textContent = p;

        if (p === page) {
            btnPage.setAttribute('aria-current', 'page');
        } else {
            btnPage.addEventListener('click', () => {
                cargarProveedores(p, busquedaActual);
            });
        }

        pagBtnsDiv.appendChild(btnPage);
    }

    // Botón Siguiente
    const btnNext = document.createElement('button');
    btnNext.type = 'button';
    btnNext.className = 'pag-btn';
    btnNext.setAttribute('aria-label', 'Página siguiente');
    btnNext.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
    btnNext.disabled = page >= total_pages;

    btnNext.addEventListener('click', () => {
        if (page < total_pages) {
            cargarProveedores(page + 1, busquedaActual);
        }
    });

    pagBtnsDiv.appendChild(btnNext);
}

async function eliminarProveedor(id) {
    const confirmado = await Modal.confirm(
        'Confirmar Eliminación',
        '¿Está seguro de dar de baja a este proveedor?',
        'danger'
    );

    if (!confirmado) return;

    try {
        const resultado = await Api.delete('proveedores/' + id);

        if (resultado && resultado.ok) {
            await Modal.success(
                'Eliminado',
                resultado.data?.message ||
                    'Proveedor desactivado exitosamente.'
            );

            cargarProveedores();
        } else {
            await Modal.error(
                'Error',
                resultado?.data?.message || 'No se pudo eliminar.'
            );
        }
    } catch (error) {
        await Modal.error(
            'Error',
            'Error de conexión: ' + error.message
        );
    }
}