const UsuariosHorariosService = (() => {
    const scopeError = (res, fallback) => {
        const msg = (res && res.data && res.data.message) ? res.data.message : fallback;
        const err = new Error(msg);
        err.status = res ? res.status : 0;
        err.code = res && res.data ? (res.data.code || '') : '';
        err.errors = res && res.data ? (res.data.errors || null) : null;
        return err;
    };

    const normalizarTurno = (t) => ({
        id: Number(t.id),
        nombre: String(t.nombre || ''),
        inicio: String(t.inicio || ''),
        fin: String(t.fin || ''),
        vendedoresAsignados: Number(t.vendedores_asignados ?? t.vendedoresAsignados ?? 0)
    });

    const normalizarProgramacion = (data) => {
        if (!data) return null;
        const turnosRaw = Array.isArray(data.turnos) ? data.turnos : [];
        return {
            usuario_id: Number(data.usuario_id),
            semana_inicio: String(data.semana_inicio || ''),
            alcance: String(data.alcance || 'desde'),
            origen_semana_inicio: String(data.origen_semana_inicio || ''),
            tiene_programacion_base: Boolean(data.tiene_programacion_base),
            tiene_semana_anterior: Boolean(data.tiene_semana_anterior),
            turnos: turnosRaw.map((t) => ({
                turnoId: Number(t.turno_id ?? t.turnoId ?? t.id),
                nombre: String(t.nombre || ''),
                inicio: String(t.inicio || ''),
                fin: String(t.fin || ''),
                dias: Array.isArray(t.dias) ? t.dias.map(Number) : []
            }))
        };
    };

    async function getUsuarios(busqueda = '') {
        const q = busqueda ? '?q=' + encodeURIComponent(busqueda) : '';
        return await Api.get('usuarios' + q);
    }

    async function getTurnos() {
        const res = await Api.get('turnos');
        if (!res) throw new Error('Consulta de turnos cancelada.');
        if (!res.ok || !res.data || !res.data.status) {
            throw scopeError(res, 'No se pudieron obtener los turnos.');
        }
        const raw = res.data.data;
        const lista = Array.isArray(raw) ? raw : (Array.isArray(raw && raw.turnos) ? raw.turnos : []);
        return lista.map(normalizarTurno);
    }

    async function crearTurno(datos) {
        const base = {
            nombre: String(datos.nombre || '').trim(),
            inicio: String(datos.inicio || '').trim(),
            fin: String(datos.fin || '').trim()
        };
        const ref = await Api.referenciaSolicitud('turno-crear', base);
        const res = await Api.post('turnos', { ...base, referencia_solicitud: ref }, {
            headers: { 'X-Request-Reference': ref }
        });
        if (!res || !res.ok || !res.data || !res.data.status) {
            throw scopeError(res, 'No se pudo crear el turno.');
        }
        Api.confirmarSolicitud('turno-crear');
        return normalizarTurno(res.data.data && res.data.data.turno ? res.data.data.turno : res.data.data);
    }

    async function editarTurno(turnoId, datos) {
        const id = Number(turnoId);
        const base = {
            id,
            nombre: String(datos.nombre || '').trim(),
            inicio: String(datos.inicio || '').trim(),
            fin: String(datos.fin || '').trim()
        };
        const ref = await Api.referenciaSolicitud('turno-editar-' + id, base);
        const res = await Api.put('turnos/' + id, {
            nombre: base.nombre,
            inicio: base.inicio,
            fin: base.fin,
            referencia_solicitud: ref
        }, {
            headers: { 'X-Request-Reference': ref }
        });
        if (!res || !res.ok || !res.data || !res.data.status) {
            throw scopeError(res, 'No se pudo actualizar el turno.');
        }
        Api.confirmarSolicitud('turno-editar-' + id);
        return normalizarTurno(res.data.data && res.data.data.turno ? res.data.data.turno : res.data.data);
    }

    async function eliminarTurno(turnoId) {
        const id = Number(turnoId);
        const ref = await Api.referenciaSolicitud('turno-eliminar-' + id, { id });
        const res = await Api.delete('turnos/' + id, {
            headers: { 'X-Request-Reference': ref }
        });
        if (!res || !res.ok || !res.data || !res.data.status) {
            throw scopeError(res, 'No se puede eliminar el turno.');
        }
        Api.confirmarSolicitud('turno-eliminar-' + id);
        return true;
    }

    async function getProgramacion(usuarioId, semanaInicio) {
        const q = '?usuario_id=' + encodeURIComponent(String(usuarioId))
            + '&semana_inicio=' + encodeURIComponent(String(semanaInicio));
        const res = await Api.get('programaciones' + q);
        if (!res) return null;
        if (!res.ok || !res.data || !res.data.status) {
            throw scopeError(res, 'No se pudo obtener la programación semanal.');
        }
        return normalizarProgramacion(res.data.data);
    }

    async function guardarProgramacion(usuarioId, semanaInicio, datos) {
        const uid = Number(usuarioId);
        const turnosPayload = (Array.isArray(datos.turnos) ? datos.turnos : []).map((t) => ({
            turno_id: Number(t.turnoId ?? t.turno_id),
            dias: (Array.isArray(t.dias) ? t.dias : []).map(Number).sort((a, b) => a - b)
        }));
        const base = {
            usuario_id: uid,
            semana_inicio: String(semanaInicio),
            alcance: String(datos.alcance || 'desde'),
            turnos: turnosPayload
        };
        const scope = 'programacion-guardar-' + uid + '-' + base.semana_inicio;
        const ref = await Api.referenciaSolicitud(scope, base);
        const res = await Api.put('programaciones', {
            ...base,
            referencia_solicitud: ref
        }, {
            headers: { 'X-Request-Reference': ref }
        });
        if (!res || !res.ok || !res.data || !res.data.status) {
            throw scopeError(res, 'No se pudo guardar la programación.');
        }
        Api.confirmarSolicitud(scope);
        return normalizarProgramacion(res.data.data);
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
