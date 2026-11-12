// TODO backend: sustituir estas respuestas por el contrato de turnos y programación semanal.
const UsuariosHorariosService = (() => {
    const turnos = [
        { id: 1, nombre: 'Mañana', inicio: '07:00', fin: '15:00' },
        { id: 2, nombre: 'Tarde', inicio: '15:00', fin: '22:00' }
    ];
    const programaciones = new Map();
    const reglasDesde = [];
    let siguienteTurnoId = 3;

    function fechaSemana(fecha) {
        const dia = new Date(fecha + 'T12:00:00');
        const lunes = new Date(dia);
        lunes.setDate(dia.getDate() - ((dia.getDay() + 6) % 7));
        return fechaIso(lunes);
    }

    function fechaIso(fecha) {
        return [
            fecha.getFullYear(),
            String(fecha.getMonth() + 1).padStart(2, '0'),
            String(fecha.getDate()).padStart(2, '0')
        ].join('-');
    }

    function desplazarSemana(inicio, semanas) {
        const fecha = new Date(inicio + 'T12:00:00');
        fecha.setDate(fecha.getDate() + semanas * 7);
        return fechaIso(fecha);
    }

    function clave(usuarioId, semanaInicio) {
        return String(usuarioId) + '|' + fechaSemana(semanaInicio);
    }

    function copiarTurnos(programacion) {
        if (Array.isArray(programacion.turnos)) {
            return programacion.turnos.map(horario => ({
                turnoId: Number(horario.turnoId),
                dias: [...horario.dias]
            }));
        }
        if (programacion.turnoId !== undefined && Array.isArray(programacion.dias)) {
            return [{ turnoId: Number(programacion.turnoId), dias: [...programacion.dias] }];
        }
        return [];
    }

    function clonar(programacion) {
        return programacion ? {
            turnos: copiarTurnos(programacion),
            semanaInicio: programacion.semanaInicio,
            alcance: programacion.alcance || 'semana'
        } : null;
    }

    function guardarEjemplo(usuarioId, semanaInicio, turnosProgramados) {
        const semana = fechaSemana(semanaInicio);
        programaciones.set(clave(usuarioId, semana), {
            turnos: turnosProgramados,
            semanaInicio: semana,
            alcance: 'semana'
        });
    }

    function guardarBaseEjemplo(usuarioId, semanaInicio, turnosProgramados) {
        const semana = fechaSemana(semanaInicio);
        reglasDesde.push({
            usuarioId: String(usuarioId),
            semanaInicio: semana,
            programacion: {
                turnos: turnosProgramados,
                semanaInicio: semana,
                alcance: 'desde'
            }
        });
    }

    const semanaActual = fechaSemana(fechaIso(new Date()));
    const semanaAnterior = desplazarSemana(semanaActual, -1);
    guardarBaseEjemplo(4, semanaAnterior, [
        { turnoId: 1, dias: [0, 1, 2, 3, 4] },
        { turnoId: 2, dias: [0, 1, 2, 3, 4] }
    ]);
    guardarBaseEjemplo(6, semanaAnterior, [{ turnoId: 2, dias: [0, 1, 2, 3, 4] }]);
    guardarEjemplo(6, semanaActual, [{ turnoId: 2, dias: [0, 1, 2, 3, 4, 5] }]);
    guardarBaseEjemplo(7, semanaActual, [{ turnoId: 1, dias: [2, 3, 4] }]);

    // TODO backend: mantener el endpoint vigente de usuarios hasta integrar el contrato consolidado.
    async function getUsuarios(busqueda = '') {
        const endpoint = busqueda ? 'usuarios?q=' + encodeURIComponent(busqueda) : 'usuarios';
        return Api.get(endpoint);
    }

    // TODO backend: recibir turnos activos y la cantidad de vendedores asignados.
    async function getTurnos() {
        return turnos.map(turno => ({
            ...turno,
            vendedoresAsignados: contarAsignados(turno.id)
        }));
    }

    function contarAsignados(turnoId) {
        const usuarios = new Set();
        programaciones.forEach((programacion, claveProgramacion) => {
            if (copiarTurnos(programacion).some(horario => horario.turnoId === Number(turnoId))) {
                usuarios.add(claveProgramacion.split('|')[0]);
            }
        });
        reglasDesde.forEach(regla => {
            if (copiarTurnos(regla.programacion).some(horario => horario.turnoId === Number(turnoId))) {
                usuarios.add(String(regla.usuarioId));
            }
        });
        return usuarios.size;
    }

    function validarTurno(datos, turnoIdActual = null) {
        const nombre = String(datos.nombre || '').trim();
        if (!nombre) throw new Error('El nombre del turno es obligatorio.');
        if (turnos.some(turno => turno.id !== Number(turnoIdActual)
            && turno.nombre.toLocaleLowerCase('es') === nombre.toLocaleLowerCase('es'))) {
            throw new Error('Ya existe un turno con ese nombre.');
        }
        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(datos.inicio || '')
            || !/^([01]\d|2[0-3]):[0-5]\d$/.test(datos.fin || '')) {
            throw new Error('Ingresa horas válidas en formato de 24 horas.');
        }
        if (datos.fin <= datos.inicio) throw new Error('La hora de fin debe ser posterior al inicio.');
        return { nombre: nombre, inicio: datos.inicio, fin: datos.fin };
    }

    // TODO backend: crear turno y devolver su identificador y datos normalizados.
    async function crearTurno(datos) {
        const valido = validarTurno(datos);
        const turno = { id: siguienteTurnoId++, ...valido };
        turnos.push(turno);
        return { ...turno, vendedoresAsignados: 0 };
    }

    // TODO backend: editar turno y conservar las asignaciones existentes.
    async function editarTurno(turnoId, datos) {
        const turno = turnos.find(item => item.id === Number(turnoId));
        if (!turno) throw new Error('No se encontró el turno que deseas editar.');
        Object.assign(turno, validarTurno(datos, turnoId));
        return { ...turno, vendedoresAsignados: contarAsignados(turno.id) };
    }

    // TODO backend: rechazar la eliminación si existen vendedores asignados.
    async function eliminarTurno(turnoId) {
        const asignados = contarAsignados(turnoId);
        if (asignados > 0) throw new Error('No se puede eliminar: hay vendedores asignados a este turno.');
        const indice = turnos.findIndex(item => item.id === Number(turnoId));
        if (indice < 0) throw new Error('No se encontró el turno que deseas eliminar.');
        turnos.splice(indice, 1);
        return { eliminado: true, id: Number(turnoId) };
    }

    // TODO backend: el servidor decide qué programación rige para esta semana.
    async function getProgramacion(usuarioId, semanaInicio) {
        const semana = fechaSemana(semanaInicio);
        const especifica = programaciones.get(clave(usuarioId, semana));
        if (especifica) return clonar(especifica);

        const regla = reglasDesde
            .filter(item => String(item.usuarioId) === String(usuarioId) && item.semanaInicio <= semana)
            .sort((a, b) => b.semanaInicio.localeCompare(a.semanaInicio))[0];
        return regla ? clonar({ ...regla.programacion, semanaInicio: semana, alcance: 'desde' }) : null;
    }

    // TODO backend: persistir varios turnos, sus días y el alcance; devolver lo confirmado por el servidor.
    async function guardarProgramacion(usuarioId, semanaInicio, datos) {
        const semana = fechaSemana(semanaInicio);
        if (!Array.isArray(datos.turnos) || datos.turnos.length === 0) {
            throw new Error('Selecciona al menos un turno.');
        }
        const idsSeleccionados = new Set();
        const turnosProgramados = datos.turnos.map(horario => {
            const turnoId = Number(horario.turnoId);
            if (!turnos.some(turno => turno.id === turnoId)) throw new Error('Selecciona un turno válido.');
            if (idsSeleccionados.has(turnoId)) throw new Error('No repitas un turno en la programación.');
            idsSeleccionados.add(turnoId);
            if (!Array.isArray(horario.dias) || horario.dias.length === 0) {
                throw new Error('Selecciona al menos un día para cada turno.');
            }
            if (horario.dias.some(dia => !Number.isInteger(dia) || dia < 0 || dia > 6)) {
                throw new Error('La selección de días no es válida.');
            }
            return {
                turnoId: turnoId,
                dias: [...new Set(horario.dias)].sort((a, b) => a - b)
            };
        });
        const programacion = {
            turnos: turnosProgramados,
            semanaInicio: semana,
            alcance: datos.alcance
        };
        if (datos.alcance === 'desde') {
            for (const [claveProgramacion, existente] of programaciones) {
                if (claveProgramacion.startsWith(String(usuarioId) + '|')
                    && existente.semanaInicio >= semana) {
                    programaciones.delete(claveProgramacion);
                }
            }
            for (let indice = reglasDesde.length - 1; indice >= 0; indice--) {
                if (String(reglasDesde[indice].usuarioId) === String(usuarioId)
                    && reglasDesde[indice].semanaInicio >= semana) {
                    reglasDesde.splice(indice, 1);
                }
            }
            reglasDesde.push({ usuarioId: String(usuarioId), semanaInicio: semana, programacion: programacion });
        } else {
            programaciones.set(clave(usuarioId, semana), programacion);
        }
        return clonar(programacion);
    }

    return {
        getUsuarios,
        getTurnos,
        crearTurno,
        editarTurno,
        eliminarTurno,
        getProgramacion,
        guardarProgramacion
    };
})();
