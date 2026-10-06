document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('tabla_usuarios');
    const inputBusqueda = document.getElementById('busqueda_usuario');

    if (!tbody) return;

    let roles = [];

    async function cargarUsuarios(busqueda = '') {
        try {
            tbody.textContent = '';
            const trLoad = document.createElement('tr');
            const tdLoad = document.createElement('td');
            tdLoad.colSpan = 8;
            tdLoad.style.textAlign = 'center';
            tdLoad.style.padding = '20px';
            tdLoad.style.color = '#888';
            const spinner = document.createElement('i');
            spinner.className = 'fa-solid fa-spinner fa-spin';
            tdLoad.appendChild(spinner);
            tdLoad.appendChild(document.createTextNode(' Cargando usuarios...'));
            trLoad.appendChild(tdLoad);
            tbody.appendChild(trLoad);

            const endpoint = busqueda ? 'usuarios?q=' + encodeURIComponent(busqueda) : 'usuarios';
            const inicio = Date.now();
            const resultado = await Api.get(endpoint);
            const elapsed = Date.now() - inicio;
            if (elapsed < 800) await new Promise(r => setTimeout(r, 800 - elapsed));

            if (!resultado || !resultado.ok) {
                mostrarError('No se pudieron cargar los usuarios.');
                return;
            }

            renderizarTabla(resultado.data.data || []);
        } catch (error) {
            mostrarError('Error de conexión: ' + error.message);
        }
    }

    function renderizarTabla(usuarios) {
        tbody.textContent = '';

        if (usuarios.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 8;
            td.textContent = 'No se encontraron usuarios.';
            td.style.textAlign = 'center';
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }

        usuarios.forEach(usu => {
            const fila = document.createElement('tr');

            const celdaRol = document.createElement('td');
            const badgeRol = document.createElement('span');
            badgeRol.className = 'badge-neutral';
            badgeRol.textContent = usu.rol;
            celdaRol.appendChild(badgeRol);

            const celdaUsername = document.createElement('td');
            celdaUsername.className = 'font-semibold';
            celdaUsername.textContent = usu.username;

            const celdaNombre = document.createElement('td');
            celdaNombre.textContent = usu.nombre;

            const celdaDni = document.createElement('td');
            celdaDni.textContent = usu.dni;

            const celdaTel = document.createElement('td');
            celdaTel.textContent = usu.telefono || '-';

            const celdaEmail = document.createElement('td');
            celdaEmail.textContent = usu.email || '-';

            const celdaDir = document.createElement('td');
            celdaDir.textContent = usu.direccion || '-';

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
                btnEditar.addEventListener('click', () => abrirModalEditar(usu));
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
                btnEliminar.addEventListener('click', () => eliminarUsuario(usu.id_usuario));
                divAcciones.appendChild(btnEliminar);
            }

            celdaAcciones.appendChild(divAcciones);
            fila.appendChild(celdaRol);
            fila.appendChild(celdaUsername);
            fila.appendChild(celdaNombre);
            fila.appendChild(celdaDni);
            fila.appendChild(celdaTel);
            fila.appendChild(celdaEmail);
            fila.appendChild(celdaDir);
            fila.appendChild(celdaAcciones);
            tbody.appendChild(fila);
        });
    }

    async function cargarDropdowns() {
        try {
            const res = await Api.get('roles');
            if (res && res.ok) roles = res.data.data || [];
        } catch (e) { /* ignore */ }
    }

    function abrirModalCrear() {
        ModalForm.open({
            titulo: 'Registrar Nuevo Usuario',
            endpoint: 'usuarios',
            method: 'POST',
            btnText: 'Guardar Usuario',
            onSuccess: () => cargarUsuarios(),
            campos: [
                { name: 'id_rol', label: 'Rol', type: 'select', col: 'col-6', required: true,
                    options: roles.map(r => ({ value: r.id_rol, label: r.nombre })) },
                { name: 'username', label: 'Username', type: 'text', col: 'col-6', required: true, maxlength: '10', placeholder: 'Nombre de usuario' },
                { name: 'password', label: 'Contraseña', type: 'password', col: 'col-6', required: true, minlength: '6', placeholder: 'Mínimo 6 caracteres' },
                { name: 'nombre', label: 'Nombre Completo', type: 'text', col: 'col-6', required: true, placeholder: 'Nombre completo' },
                { name: 'dni', label: 'DNI', type: 'text', col: 'col-6', required: true, maxlength: '8', pattern: '[0-9]{8}', placeholder: '8 dígitos' },
                { name: 'telefono', label: 'Teléfono', type: 'text', col: 'col-6', maxlength: '9', placeholder: '9 dígitos' },
                { name: 'email', label: 'Correo Electrónico', type: 'email', col: 'col-6', placeholder: 'correo@ejemplo.com' },
                { name: 'direccion', label: 'Dirección', type: 'text', col: 'col-6', placeholder: 'Calle, Av, Jr...' }
            ]
        });
    }

    function abrirModalEditar(usu) {
        ModalForm.open({
            titulo: 'Editar Usuario',
            endpoint: 'usuarios',
            id: usu.id_usuario,
            method: 'PUT',
            btnText: 'Guardar Cambios',
            onSuccess: () => cargarUsuarios(),
            campos: [
                { name: 'id_rol', label: 'Rol', type: 'select', col: 'col-6', required: true,
                    options: roles.map(r => ({ value: r.id_rol, label: r.nombre })),
                    value: roles.find(r => r.nombre === usu.rol)?.id_rol || '' },
                { name: 'username', label: 'Username', type: 'text', col: 'col-6', required: true, value: usu.username, maxlength: '10' },
                { name: 'password', label: 'Contraseña (dejar vacío para no cambiar)', type: 'password', col: 'col-6', minlength: '6', placeholder: 'Mínimo 6 caracteres' },
                { name: 'nombre', label: 'Nombre Completo', type: 'text', col: 'col-6', required: true, value: usu.nombre },
                { name: 'dni', label: 'DNI', type: 'text', col: 'col-6', required: true, value: usu.dni, maxlength: '8', pattern: '[0-9]{8}' },
                { name: 'telefono', label: 'Teléfono', type: 'text', col: 'col-6', value: usu.telefono || '', maxlength: '9' },
                { name: 'email', label: 'Correo Electrónico', type: 'email', col: 'col-6', value: usu.email || '' },
                { name: 'direccion', label: 'Dirección', type: 'text', col: 'col-6', value: usu.direccion || '' }
            ]
        });
    }

    async function eliminarUsuario(id) {
        const confirmado = await Modal.confirm('Confirmar Eliminación', '¿Está seguro de dar de baja a este usuario?', 'danger');
        if (!confirmado) return;

        try {
            const resultado = await Api.delete('usuarios/' + id);
            if (resultado && resultado.ok) {
                await Modal.success('Eliminado', resultado.data.message || 'Usuario eliminado correctamente.');
                cargarUsuarios();
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
        td.colSpan = 8;
        td.textContent = mensaje;
        td.style.textAlign = 'center';
        td.style.color = '#dc3545';
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    document.getElementById('btn_crear_usuario')?.addEventListener('click', () => {
        if (roles.length === 0) {
            Modal.error('Error', 'Los roles no se han cargado.');
            return;
        }
        abrirModalCrear();
    });

    if (inputBusqueda) {
        inputBusqueda.addEventListener('input', debounce(() => {
            cargarUsuarios(inputBusqueda.value);
        }, 350));
    }

    cargarDropdowns();
    cargarUsuarios();
});
