document.addEventListener('DOMContentLoaded', () => {
    const botonCrear = document.getElementById('btn_crear_proveedor');

    if (!botonCrear) return;

    botonCrear.addEventListener('click', async () => {
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
                onMount: inicializarFormularioProveedor
            });
        } catch (error) {
            await Modal.error('Error', error.message);
        }
    });

    function inicializarFormularioProveedor() {
        const formulario = document.getElementById('form_nuevo_proveedor');
        if (!formulario) return;

        const tipoDocumento = formulario.elements.id_tipo_documento;
        const numeroDocumento = formulario.elements.nro_documento;
        const nombre = formulario.elements.nombre;
        const telefono = formulario.elements.telefono;
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
        tipoDocumento.addEventListener('change', actualizarValidacionDocumento);
        numeroDocumento.addEventListener('input', () => {
            if (documentoTouched) validarDocumento();
        });
        numeroDocumento.addEventListener('blur', () => {
            documentoTouched = true;
            validarDocumento();
        });
        nombre.addEventListener('input', () => {
            if (nombreTouched) validarNombre();
        });
        nombre.addEventListener('blur', () => {
            nombreTouched = true;
            validarNombre();
        });
        telefono.addEventListener('input', () => {
            if (telefonoTouched) validarTelefono();
        });
        telefono.addEventListener('blur', () => {
            telefonoTouched = true;
            validarTelefono();
        });
        tipoDocumento.value = tipoDocumento.value || '2';
        actualizarValidacionDocumento();

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
