document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('tabla_usuarios');
    const inputBusqueda = document.getElementById('busqueda_usuario');

    if (!tbody) return;

    let roles = [];
    const puedeProgramar = typeof canProgramSchedules !== 'undefined' && canProgramSchedules
        && typeof UsuariosHorariosService !== 'undefined';
    let turnos = [];
    let programacionesActuales = new Map();
    let semanaLista = inicioSemana(new Date());
    let usuarioProgramado = null;
    let semanaModal = semanaLista;
    let programacionSemana = null;
    let programacionAnterior = null;
    let modalRegreso = null;
    let turnoEditando = null;
    let toastTimer = null;
    const overlayProgramacion = document.getElementById('program_schedule_modal');
    const overlayTurnos = document.getElementById('manage_shifts_modal');
    const diaIndices = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
    const diaNombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    const turnosSeleccionados = new Map();

    async function cargarUsuarios(busqueda = '') {
        try {
            tbody.textContent = '';
            const trLoad = document.createElement('tr');
            const tdLoad = document.createElement('td');
            tdLoad.colSpan = 9;
            tdLoad.style.textAlign = 'center';
            tdLoad.style.padding = '20px';
            tdLoad.style.color = '#888';
            const spinner = document.createElement('i');
            spinner.className = 'fa-solid fa-spinner fa-spin';
            tdLoad.appendChild(spinner);
            tdLoad.appendChild(document.createTextNode(' Cargando usuarios...'));
            trLoad.appendChild(tdLoad);
            tbody.appendChild(trLoad);

            const inicio = Date.now();
            const resultado = puedeProgramar
                ? await UsuariosHorariosService.getUsuarios(busqueda)
                : await Api.get(busqueda ? 'usuarios?q=' + encodeURIComponent(busqueda) : 'usuarios');
            const elapsed = Date.now() - inicio;
            if (elapsed < 800) await new Promise(r => setTimeout(r, 800 - elapsed));

            if (!resultado || !resultado.ok) {
                mostrarError('No se pudieron cargar los usuarios.');
                return;
            }

            const usuarios = resultado.data.data || [];
            usuariosActuales = usuarios;
            await cargarProgramacionesDeFilas(usuarios);
            renderizarTabla(usuarios);
        } catch (error) {
            mostrarError('Error de conexión: ' + error.message);
        }
    }

    async function cargarProgramacionesDeFilas(usuarios) {
        programacionesActuales = new Map();
        if (!puedeProgramar) return;
        const vendedores = usuarios.filter(esVendedor);
        const resultados = await Promise.all(vendedores.map(async usuario => [
            String(usuario.id_usuario),
            await UsuariosHorariosService.getProgramacion(usuario.id_usuario, semanaLista)
        ]));
        resultados.forEach(([id, programacion]) => programacionesActuales.set(id, programacion));
    }

    function renderizarTabla(usuarios) {
        tbody.textContent = '';

        if (usuarios.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 9;
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

            const celdaHorario = document.createElement('td');
            celdaHorario.className = 'user-schedule-cell';
            if (esAdministrador(usu)) {
                const acceso = document.createElement('span');
                acceso.className = 'user-schedule-badge is-open';
                acceso.innerHTML = '<i class="fa-solid fa-lock-open" aria-hidden="true"></i> Acceso libre';
                celdaHorario.appendChild(acceso);
            } else if (esVendedor(usu)) {
                renderizarHorarioUsuario(celdaHorario, usu);
            } else {
                celdaHorario.textContent = '-';
            }

            const celdaAcciones = document.createElement('td');
            const divAcciones = document.createElement('div');
            divAcciones.className = 'actions-group';

            if (typeof canEdit !== 'undefined' && canEdit) {
                const btnEditar = document.createElement('button');
                btnEditar.type = 'button';
                btnEditar.className = 'btn btn-edit';
                btnEditar.title = 'Editar';
                btnEditar.setAttribute('aria-label', 'Editar usuario');
                const iconEdit = document.createElement('i');
                iconEdit.className = 'fa-solid fa-pen-to-square';
                btnEditar.appendChild(iconEdit);
                btnEditar.addEventListener('click', () => abrirModalEditar(usu));
                divAcciones.appendChild(btnEditar);
            }

            if (puedeProgramar && esVendedor(usu)) {
                const btnProgramar = document.createElement('button');
                btnProgramar.type = 'button';
                btnProgramar.className = 'btn btn-schedule';
                btnProgramar.title = 'Programar horario';
                btnProgramar.setAttribute('aria-label', 'Programar horario');
                btnProgramar.innerHTML = '<i class="fa-regular fa-calendar-days" aria-hidden="true"></i>';
                btnProgramar.addEventListener('click', async () => {
                    try {
                        await abrirModalProgramacion(usu, btnProgramar);
                    } catch (error) {
                        await Modal.error('No se pudo cargar la programación', error.message || 'Inténtalo de nuevo.');
                    }
                });
                divAcciones.appendChild(btnProgramar);
            }

            if (typeof canDelete !== 'undefined' && canDelete) {
                const btnEliminar = document.createElement('button');
                btnEliminar.type = 'button';
                btnEliminar.className = 'btn btn-delete';
                btnEliminar.title = 'Eliminar';
                btnEliminar.setAttribute('aria-label', 'Eliminar usuario');
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
            fila.appendChild(celdaHorario);
            fila.appendChild(celdaAcciones);
            tbody.appendChild(fila);
        });
    }

    function renderizarHorarioUsuario(celda, usuario) {
        const programacion = programacionesActuales.get(String(usuario.id_usuario));
        if (!programacion) {
            const sinProgramar = document.createElement('span');
            sinProgramar.className = 'user-schedule-badge is-unset';
            sinProgramar.innerHTML = '<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Sin programar';
            celda.appendChild(sinProgramar);
            return;
        }
        const horarios = normalizarTurnosProgramados(programacion)
            .map(horario => ({
                horario: horario,
                turno: turnos.find(item => Number(item.id) === Number(horario.turnoId))
            }))
            .filter(item => item.turno);
        if (!horarios.length) {
            const sinProgramar = document.createElement('span');
            sinProgramar.className = 'user-schedule-badge is-unset';
            sinProgramar.innerHTML = '<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Sin programar';
            celda.appendChild(sinProgramar);
            return;
        }
        horarios.forEach(({ horario, turno }) => {
            const entrada = document.createElement('div');
            entrada.className = 'user-schedule-entry';
            const badge = document.createElement('span');
            badge.className = 'user-schedule-badge';
            badge.textContent = turno.nombre + ' · ' + turno.inicio + '–' + turno.fin;
            const dias = document.createElement('div');
            dias.className = 'user-schedule-days-mini';
            diaIndices.forEach((dia, indice) => {
                const cuadrito = document.createElement('span');
                cuadrito.textContent = dia;
                if (horario.dias.includes(indice)) cuadrito.classList.add('is-active');
                dias.appendChild(cuadrito);
            });
            entrada.append(badge, dias);
            celda.appendChild(entrada);
        });
    }

    function esVendedor(usuario) {
        return String(usuario.rol || '').toLocaleLowerCase('es').includes('vendedor');
    }

    function esAdministrador(usuario) {
        return String(usuario.rol || '').toLocaleLowerCase('es').includes('administrador');
    }

    function inicioSemana(fecha) {
        const lunes = new Date(fecha.getFullYear(), fecha.getMonth(), fecha.getDate(), 12);
        lunes.setDate(lunes.getDate() - ((lunes.getDay() + 6) % 7));
        return fechaIso(lunes);
    }

    function fechaIso(fecha) {
        return [
            fecha.getFullYear(),
            String(fecha.getMonth() + 1).padStart(2, '0'),
            String(fecha.getDate()).padStart(2, '0')
        ].join('-');
    }

    function moverSemana(inicio, cantidad) {
        const fecha = new Date(inicio + 'T12:00:00');
        fecha.setDate(fecha.getDate() + cantidad * 7);
        return fechaIso(fecha);
    }

    function fechaCorta(fecha) {
        return new Intl.DateTimeFormat('es-PE', { day: 'numeric', month: 'short' })
            .format(fecha).replace('.', '');
    }

    function rangoSemana(inicio) {
        const lunes = new Date(inicio + 'T12:00:00');
        const domingo = new Date(lunes);
        domingo.setDate(domingo.getDate() + 6);
        const mismoMes = lunes.getMonth() === domingo.getMonth()
            && lunes.getFullYear() === domingo.getFullYear();
        const inicioTexto = mismoMes
            ? String(lunes.getDate())
            : fechaCorta(lunes) + (lunes.getFullYear() !== domingo.getFullYear() ? ' ' + lunes.getFullYear() : '');
        return inicioTexto + ' – ' + fechaCorta(domingo) + ' ' + domingo.getFullYear();
    }

    async function cargarTurnos() {
        if (!puedeProgramar) return;
        turnos = await UsuariosHorariosService.getTurnos();
    }

    function abrirOverlay(overlay, foco) {
        if (!overlay) return;
        modalRegreso = document.activeElement;
        overlay.hidden = false;
        document.body.classList.add('user-schedule-modal-open');
        window.requestAnimationFrame(() => (foco || overlay.querySelector('button, input'))?.focus());
    }

    function cerrarOverlay(overlay) {
        if (!overlay) return;
        if (overlay === overlayTurnos && !document.getElementById('shift_edit_panel').hidden) {
            cerrarEditorTurno();
        }
        overlay.hidden = true;
        if (overlayProgramacion?.hidden !== false && overlayTurnos?.hidden !== false) {
            document.body.classList.remove('user-schedule-modal-open');
        }
        if (modalRegreso && typeof modalRegreso.focus === 'function') modalRegreso.focus();
    }

    function mostrarAviso(mensaje) {
        const aviso = document.getElementById('user_schedule_toast');
        if (!aviso) return;
        window.clearTimeout(toastTimer);
        aviso.textContent = mensaje;
        aviso.hidden = false;
        toastTimer = window.setTimeout(() => { aviso.hidden = true; }, 3500);
    }

    function resumenProgramacion(programacion, incluirTurno = true) {
        if (!programacion) return 'La semana anterior no tiene programación';
        const resumenes = normalizarTurnosProgramados(programacion).map(horario => {
            const turno = turnos.find(item => Number(item.id) === Number(horario.turnoId));
            const dias = horario.dias.map(indice => diaIndices[indice]).join(' ');
            return (incluirTurno && turno ? turno.nombre + ' · ' : '') + dias;
        });
        return resumenes.length ? resumenes.join(' | ') : 'La semana no tiene turnos programados';
    }

    function normalizarTurnosProgramados(programacion) {
        if (Array.isArray(programacion.turnos)) {
            return programacion.turnos.map(horario => ({
                turnoId: Number(horario.turnoId),
                dias: Array.isArray(horario.dias) ? horario.dias.slice() : []
            }));
        }
        if (programacion.turnoId !== undefined && Array.isArray(programacion.dias)) {
            return [{ turnoId: Number(programacion.turnoId), dias: programacion.dias.slice() }];
        }
        return [];
    }

    async function abrirModalProgramacion(usuario, boton) {
        usuarioProgramado = usuario;
        semanaModal = semanaLista;
        turnosSeleccionados.clear();
        document.getElementById('program_schedule_user').textContent =
            (usuario.nombre || '') + ' · ' + (usuario.username || '');
        document.getElementById('program_shift_error').textContent = '';
        modalRegreso = boton;
        await cargarSemanaModal();
        abrirOverlay(overlayProgramacion, overlayProgramacion.querySelector('[data-close-schedule]'));
    }

    async function cargarSemanaModal() {
        document.getElementById('program_week_range').textContent = rangoSemana(semanaModal);
        [programacionSemana, programacionAnterior] = await Promise.all([
            UsuariosHorariosService.getProgramacion(usuarioProgramado.id_usuario, semanaModal),
            UsuariosHorariosService.getProgramacion(usuarioProgramado.id_usuario, moverSemana(semanaModal, -1))
        ]);
        turnosSeleccionados.clear();
        normalizarTurnosProgramados(programacionSemana || {}).forEach(horario => {
            turnosSeleccionados.set(String(horario.turnoId), new Set(horario.dias));
        });
        renderizarProgramacionModal();
    }

    function renderizarProgramacionModal() {
        const anterior = document.getElementById('program_previous_summary');
        const copiar = document.getElementById('program_copy_previous');
        anterior.textContent = programacionAnterior
            ? resumenProgramacion(programacionAnterior)
            : 'La semana anterior no tiene programación';
        copiar.hidden = !programacionAnterior;

        const opcionesTurno = document.getElementById('program_shift_options');
        opcionesTurno.textContent = '';
        turnos.forEach(turno => {
            const turnoId = String(turno.id);
            const selectedDays = turnosSeleccionados.get(turnoId);
            const tarjeta = document.createElement('article');
            tarjeta.className = 'user-schedule-shift-option';
            const encabezado = document.createElement('label');
            encabezado.className = 'user-schedule-shift-header';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'program_turnos[]';
            checkbox.value = turnoId;
            checkbox.checked = Boolean(selectedDays);
            const texto = document.createElement('span');
            const nombre = document.createElement('strong');
            nombre.textContent = turno.nombre;
            const horas = document.createElement('small');
            horas.textContent = turno.inicio + '–' + turno.fin;
            texto.append(nombre, horas);
            encabezado.append(checkbox, texto);

            const opcionesDia = document.createElement('div');
            opcionesDia.className = 'user-schedule-shift-days';
            opcionesDia.setAttribute('aria-label', 'Días de atención para ' + turno.nombre);
            const botonesDia = [];
            diaIndices.forEach((dia, indice) => {
                const boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'user-schedule-day user-schedule-shift-day';
                boton.textContent = dia;
                boton.title = diaNombres[indice];
                boton.setAttribute('aria-label', diaNombres[indice] + ' · ' + turno.nombre);
                boton.setAttribute('aria-pressed', selectedDays && selectedDays.has(indice) ? 'true' : 'false');
                boton.disabled = !selectedDays;
                botonesDia.push(boton);
                opcionesDia.appendChild(boton);
            });

            const errorDias = document.createElement('p');
            errorDias.className = 'user-schedule-error';
            errorDias.id = 'program_days_error_' + turnoId;
            errorDias.setAttribute('aria-live', 'polite');
            tarjeta.append(encabezado, opcionesDia, errorDias);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) turnosSeleccionados.set(turnoId, new Set());
                else turnosSeleccionados.delete(turnoId);
                tarjeta.classList.toggle('is-selected', checkbox.checked);
                botonesDia.forEach(botonDia => { botonDia.disabled = !checkbox.checked; });
                errorDias.textContent = '';
                document.getElementById('program_shift_error').textContent = '';
                actualizarResumenProgramacion();
            });
            botonesDia.forEach((boton, indice) => {
                boton.addEventListener('click', () => {
                    const dias = turnosSeleccionados.get(turnoId);
                    if (!dias) return;
                    if (dias.has(indice)) dias.delete(indice);
                    else dias.add(indice);
                    boton.setAttribute('aria-pressed', dias.has(indice) ? 'true' : 'false');
                    errorDias.textContent = '';
                    actualizarResumenProgramacion();
                });
            });
            tarjeta.classList.toggle('is-selected', Boolean(selectedDays));
            opcionesTurno.appendChild(tarjeta);
        });

        const aplicar = document.getElementById('program_apply_scope');
        aplicar.hidden = !programacionSemana;
        document.getElementById('program_base_hint').hidden = Boolean(programacionSemana);
        const soloSemana = aplicar.querySelector('input[value="semana"]');
        if (soloSemana) soloSemana.checked = true;
        document.getElementById('program_shift_error').textContent = '';
        actualizarResumenProgramacion();
    }

    function actualizarResumenProgramacion() {
        const resumen = document.getElementById('program_schedule_summary');
        const descripciones = [...turnosSeleccionados.entries()].map(([turnoId, dias]) => {
            const turno = turnos.find(item => String(item.id) === turnoId);
            if (!turno) return '';
            return turno.nombre + ': ' + dias.size
                + (dias.size === 1 ? ' día por semana' : ' días por semana');
        }).filter(Boolean);
        resumen.textContent = descripciones.length
            ? descripciones.join(' · ') + '.'
            : 'Selecciona uno o más turnos y sus días de atención.';
    }

    async function guardarHorarioProgramado() {
        const errorTurno = document.getElementById('program_shift_error');
        const horariosSeleccionados = [...turnosSeleccionados.entries()];
        errorTurno.textContent = horariosSeleccionados.length ? '' : 'Selecciona al menos un turno';
        let hayDiasVacios = false;
        horariosSeleccionados.forEach(([turnoId, dias]) => {
            const errorDias = document.getElementById('program_days_error_' + turnoId);
            errorDias.textContent = dias.size ? '' : 'Selecciona al menos un día para este turno';
            if (!dias.size) hayDiasVacios = true;
        });
        if (!horariosSeleccionados.length || hayDiasVacios) return;

        const boton = document.getElementById('program_save');
        boton.disabled = true;
        try {
            const alcance = programacionSemana
                ? (overlayProgramacion.querySelector('input[name="program_scope"]:checked')?.value || 'semana')
                : 'desde';
            await UsuariosHorariosService.guardarProgramacion(
                usuarioProgramado.id_usuario,
                semanaModal,
                {
                    turnos: horariosSeleccionados.map(([turnoId, dias]) => ({
                        turnoId: Number(turnoId),
                        dias: [...dias].sort((a, b) => a - b)
                    })),
                    alcance: alcance
                }
            );
            await cargarProgramacionesDeFilas(usuariosActuales);
            renderizarTabla(usuariosActuales);
            cerrarOverlay(overlayProgramacion);
            mostrarAviso('Programación guardada');
        } catch (error) {
            await Modal.error('No se pudo guardar', error.message || 'No se pudo guardar la programación.');
        } finally {
            boton.disabled = false;
        }
    }

    function abrirModalTurnos(boton) {
        modalRegreso = boton;
        renderizarListaTurnos();
        abrirOverlay(overlayTurnos, document.getElementById('shift_new'));
    }

    function renderizarListaTurnos() {
        const lista = document.getElementById('shifts_list');
        lista.textContent = '';
        if (!turnos.length) {
            const vacio = document.createElement('p');
            vacio.className = 'user-shifts-empty';
            vacio.textContent = 'Aún no hay turnos de atención. Crea uno para programar a los vendedores.';
            lista.appendChild(vacio);
        }
        turnos.forEach(turno => {
            const tarjeta = document.createElement('article');
            tarjeta.className = 'user-shift-card';
            const datos = document.createElement('div');
            const nombre = document.createElement('strong');
            nombre.textContent = turno.nombre;
            const detalle = document.createElement('p');
            const cantidad = Number(turno.vendedoresAsignados) || 0;
            detalle.textContent = turno.inicio + '–' + turno.fin + ' · ' + cantidad
                + (cantidad === 1 ? ' vendedor asignado' : ' vendedores asignados');
            datos.append(nombre, detalle);

            const acciones = document.createElement('div');
            acciones.className = 'user-shift-actions';
            const editar = document.createElement('button');
            editar.type = 'button';
            editar.className = 'btn btn-edit';
            editar.setAttribute('aria-label', 'Editar turno ' + turno.nombre);
            editar.title = 'Editar turno';
            editar.innerHTML = '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>';
            editar.addEventListener('click', () => abrirEditorTurno(turno, tarjeta));
            const eliminar = document.createElement('button');
            eliminar.type = 'button';
            eliminar.className = 'btn btn-delete';
            eliminar.setAttribute('aria-label', 'Eliminar turno ' + turno.nombre);
            eliminar.title = cantidad ? 'Un turno con vendedores asignados no se puede eliminar' : 'Eliminar turno';
            eliminar.disabled = cantidad > 0;
            eliminar.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i>';
            eliminar.addEventListener('click', () => eliminarTurno(turno));
            acciones.append(editar, eliminar);
            tarjeta.append(datos, acciones);
            if (cantidad) {
                const nota = document.createElement('small');
                nota.className = 'user-shifts-delete-note';
                nota.textContent = 'Tiene vendedores asignados y no se puede eliminar.';
                tarjeta.appendChild(nota);
            }
            lista.appendChild(tarjeta);
        });
    }

    function abrirEditorTurno(turno = null, tarjeta = null) {
        const panel = document.getElementById('shift_edit_panel');
        const lista = document.getElementById('shifts_list');
        turnoEditando = turno ? turno.id : null;
        document.getElementById('shift_name').value = turno?.nombre || '';
        document.getElementById('shift_start').value = turno?.inicio || '';
        document.getElementById('shift_end').value = turno?.fin || '';
        limpiarErroresTurno();
        if (tarjeta) {
            tarjeta.classList.add('is-editing');
            tarjeta.appendChild(panel);
        } else {
            document.querySelector('.user-shifts-dialog').insertBefore(panel, lista);
        }
        panel.hidden = false;
        document.getElementById('shift_name').focus();
    }

    function limpiarErroresTurno() {
        ['shift_name', 'shift_start', 'shift_end'].forEach(campo => {
            document.getElementById(campo + '_error').textContent = '';
            document.getElementById(campo).removeAttribute('aria-invalid');
        });
    }

    function cerrarEditorTurno() {
        const panel = document.getElementById('shift_edit_panel');
        const lista = document.getElementById('shifts_list');
        const tarjetaAnterior = panel.parentElement?.closest('.user-shift-card');
        panel.hidden = true;
        document.querySelector('.user-shifts-dialog').insertBefore(panel, lista);
        tarjetaAnterior?.classList.remove('is-editing');
        turnoEditando = null;
    }

    function validarEditorTurno() {
        limpiarErroresTurno();
        const nombre = document.getElementById('shift_name').value.trim();
        const inicio = document.getElementById('shift_start').value;
        const fin = document.getElementById('shift_end').value;
        let valido = true;
        const marcarError = (campo, mensaje) => {
            document.getElementById(campo).setAttribute('aria-invalid', 'true');
            document.getElementById(campo + '_error').textContent = mensaje;
            valido = false;
        };
        if (!nombre) marcarError('shift_name', 'El nombre del turno es obligatorio.');
        else if (turnos.some(turno => turno.id !== Number(turnoEditando)
            && turno.nombre.toLocaleLowerCase('es') === nombre.toLocaleLowerCase('es'))) {
            marcarError('shift_name', 'Ya existe un turno con ese nombre.');
        }
        const horaValida = valor => /^([01]\d|2[0-3]):[0-5]\d$/.test(valor);
        if (!horaValida(inicio)) marcarError('shift_start', 'Ingresa una hora válida.');
        if (!horaValida(fin)) marcarError('shift_end', 'Ingresa una hora válida.');
        else if (horaValida(inicio) && fin <= inicio) marcarError('shift_end', 'La hora de fin debe ser posterior al inicio.');
        return valido ? { nombre, inicio, fin } : null;
    }

    async function guardarTurno() {
        const datos = validarEditorTurno();
        if (!datos) return;
        const boton = document.getElementById('shift_save');
        boton.disabled = true;
        try {
            if (turnoEditando === null) await UsuariosHorariosService.crearTurno(datos);
            else await UsuariosHorariosService.editarTurno(turnoEditando, datos);
            await cargarTurnos();
            cerrarEditorTurno();
            renderizarListaTurnos();
            await cargarProgramacionesDeFilas(usuariosActuales);
            renderizarTabla(usuariosActuales);
        } catch (error) {
            const mensaje = error.message || 'No se pudo guardar el turno.';
            document.getElementById('shift_name_error').textContent = mensaje;
            document.getElementById('shift_name').setAttribute('aria-invalid', 'true');
        } finally {
            boton.disabled = false;
        }
    }

    async function eliminarTurno(turno) {
        const confirmado = await Modal.confirm('Eliminar turno', '¿Deseas eliminar el turno «' + turno.nombre + '»?', 'danger');
        if (!confirmado) return;
        try {
            await UsuariosHorariosService.eliminarTurno(turno.id);
            await cargarTurnos();
            if (!document.getElementById('shift_edit_panel').hidden) cerrarEditorTurno();
            renderizarListaTurnos();
            await cargarProgramacionesDeFilas(usuariosActuales);
            renderizarTabla(usuariosActuales);
        } catch (error) {
            await Modal.error('No se puede eliminar el turno', error.message);
        }
    }

    function instalarControlesModales() {
        if (!puedeProgramar) return;
        overlayProgramacion.querySelectorAll('[data-close-schedule]').forEach(boton =>
            boton.addEventListener('click', () => cerrarOverlay(overlayProgramacion)));
        overlayTurnos.querySelectorAll('[data-close-shifts]').forEach(boton =>
            boton.addEventListener('click', () => {
                if (!document.getElementById('shift_edit_panel').hidden) cerrarEditorTurno();
                cerrarOverlay(overlayTurnos);
            }));
        overlayProgramacion.addEventListener('click', evento => {
            if (evento.target === overlayProgramacion) cerrarOverlay(overlayProgramacion);
        });
        overlayTurnos.addEventListener('click', evento => {
            if (evento.target === overlayTurnos) cerrarOverlay(overlayTurnos);
        });
        document.addEventListener('keydown', evento => {
            const visible = overlayProgramacion.hidden === false ? overlayProgramacion
                : (overlayTurnos.hidden === false ? overlayTurnos : null);
            if (!visible) return;
            if (document.querySelector('[data-modal-dynamic]')) return;
            if (evento.key === 'Escape') {
                cerrarOverlay(visible);
                return;
            }
            if (evento.key !== 'Tab') return;
            const elementos = [...visible.querySelectorAll('button:not(:disabled), input:not(:disabled), [tabindex]:not([tabindex="-1"])')]
                .filter(elemento => elemento.offsetParent !== null);
            if (!elementos.length) return;
            const primero = elementos[0];
            const ultimo = elementos[elementos.length - 1];
            if (evento.shiftKey && document.activeElement === primero) {
                evento.preventDefault();
                ultimo.focus();
            } else if (!evento.shiftKey && document.activeElement === ultimo) {
                evento.preventDefault();
                primero.focus();
            }
        });

        document.getElementById('btn_turnos_atencion')?.addEventListener('click', evento =>
            abrirModalTurnos(evento.currentTarget));
        document.getElementById('program_week_previous').addEventListener('click', async () => {
            semanaModal = moverSemana(semanaModal, -1);
            try {
                await cargarSemanaModal();
            } catch (error) {
                await Modal.error('No se pudo cargar la semana', error.message || 'Inténtalo de nuevo.');
            }
        });
        document.getElementById('program_week_next').addEventListener('click', async () => {
            semanaModal = moverSemana(semanaModal, 1);
            try {
                await cargarSemanaModal();
            } catch (error) {
                await Modal.error('No se pudo cargar la semana', error.message || 'Inténtalo de nuevo.');
            }
        });
        document.getElementById('program_copy_previous').addEventListener('click', () => {
            if (!programacionAnterior) return;
            turnosSeleccionados.clear();
            normalizarTurnosProgramados(programacionAnterior).forEach(horario => {
                turnosSeleccionados.set(String(horario.turnoId), new Set(horario.dias));
            });
            renderizarProgramacionModal();
        });
        document.getElementById('program_save').addEventListener('click', guardarHorarioProgramado);
        document.getElementById('shift_new').addEventListener('click', () => abrirEditorTurno());
        document.getElementById('shift_cancel_edit').addEventListener('click', cerrarEditorTurno);
        document.getElementById('shift_save').addEventListener('click', guardarTurno);
        ['shift_name', 'shift_start', 'shift_end'].forEach(campo => {
            document.getElementById(campo).addEventListener('input', () => {
                document.getElementById(campo).removeAttribute('aria-invalid');
                document.getElementById(campo + '_error').textContent = '';
            });
        });
    }

    let usuariosActuales = [];

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
        td.colSpan = 9;
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

    instalarControlesModales();
    cargarDropdowns();
    if (puedeProgramar) {
        cargarTurnos()
            .then(() => cargarUsuarios())
            .catch(error => mostrarError('No se pudieron cargar los turnos: ' + error.message));
    } else {
        cargarUsuarios();
    }
});
