(function () {
    var POR_PAGINA = 10;

    var TIPOS = {
        baja_danio: { etiqueta: 'Baja por daño', signo: -1, esBaja: true },
        baja_vencimiento: { etiqueta: 'Baja por vencimiento', signo: -1, esBaja: true },
        ajuste_positivo: { etiqueta: 'Ajuste positivo', signo: 1, esBaja: false },
        ajuste_negativo: { etiqueta: 'Ajuste negativo', signo: -1, esBaja: false }
    };

    function crearError(mensaje, codigo) {
        var e = new Error(mensaje);
        e.codigo = codigo || 'ERROR';
        return e;
    }

    function normalizarItemListado(item) {
        return {
            id: Number(item.id || item.id_operacion),
            idProducto: Number(item.id_producto || 0),
            fechaHora: String(item.fecha_hora || ''),
            producto: String(item.producto || ''),
            codigoBarras: item.codigo_barra != null && item.codigo_barra !== '' ? String(item.codigo_barra) : null,
            tipo: String(item.tipo || ''),
            cantidad: Number(item.cantidad || 0),
            signo: Number(item.signo || 0),
            cantidadFirmada: Number(item.cantidad_firmada || 0),
            corregido: Boolean(item.corregido),
            totalCorrecciones: Number(item.total_correcciones || 0)
        };
    }

    function normalizarDetalle(d) {
        var vigente = d.vigente || {};
        var original = d.original || {};
        var correcciones = Array.isArray(d.correcciones) ? d.correcciones.map(function (c) {
            return {
                id: Number(c.id || c.numero || 0),
                fechaHora: String(c.fecha_hora || c.fechaHora || ''),
                responsable: String(c.responsable || ''),
                motivo: String(c.motivo || ''),
                cambios: Array.isArray(c.cambios) ? c.cambios : []
            };
        }) : [];

        return {
            id: Number(d.id || d.id_operacion),
            idProducto: Number(d.id_producto || 0),
            producto: String(d.producto || ''),
            codigoBarras: d.codigo_barra != null && d.codigo_barra !== '' ? String(d.codigo_barra) : null,
            stockActual: Number(d.stock_actual || 0),
            corregido: Boolean(d.corregido || correcciones.length > 0),
            totalCorrecciones: Number(d.total_correcciones || correcciones.length),
            version: Number(d.version || 1),
            tipo: String(vigente.tipo || ''),
            cantidad: Number(vigente.cantidad || 0),
            justificacion: String(vigente.justificacion || ''),
            lote: vigente.lote != null && vigente.lote !== '' ? String(vigente.lote) : null,
            fechaHora: String(original.fecha_hora || ''),
            responsable: String(original.responsable || ''),
            saldoAnterior: Number(original.saldo_anterior || 0),
            saldoResultante: Number(original.saldo_resultante || 0),
            original: {
                fechaHora: String(original.fecha_hora || ''),
                responsable: String(original.responsable || ''),
                tipo: String(original.tipo || ''),
                cantidad: Number(original.cantidad || 0),
                justificacion: String(original.justificacion || ''),
                lote: original.lote != null && original.lote !== '' ? String(original.lote) : null,
                saldoAnterior: Number(original.saldo_anterior || 0),
                saldoResultante: Number(original.saldo_resultante || 0)
            },
            correcciones: correcciones
        };
    }

    window.MovimientosInventarioApi = {
        tipos: TIPOS,
        motivoMax: 250,
        edicionHabilitada: true,

        listarMovimientos: async function (filtros) {
            var params = new URLSearchParams();
            if (filtros && filtros.q) params.set('q', String(filtros.q).trim());
            if (filtros && filtros.tipo) params.set('tipo', String(filtros.tipo));
            if (filtros && filtros.desde) params.set('desde', String(filtros.desde));
            if (filtros && filtros.hasta) params.set('hasta', String(filtros.hasta));
            params.set('pagina', String(Math.max(1, parseInt(filtros && filtros.pagina, 10) || 1)));
            params.set('por_pagina', String(POR_PAGINA));

            var res = await Api.get('inventario/movimientos?' + params.toString());
            if (res === null) {
                throw crearError('Consulta reemplazada por una más reciente.', 'ABORTADO');
            }
            if (!res.ok || !res.data || res.data.status !== true || !res.data.data) {
                var code = (res.data && res.data.code) || '';
                var msg = (res.data && res.data.message) || 'No se pudieron consultar los movimientos de inventario.';
                if (code === 'INVALID_DATE_RANGE' || code === 'INVALID_DATE' || code === 'INVALID_FILTER' || code === 'INVALID_PAGINATION') {
                    throw crearError(msg, 'VALIDACION');
                }
                if (res.status === 401 || res.status === 403) {
                    throw crearError(msg, 'PERMISO');
                }
                throw crearError(msg, 'ERROR');
            }

            var payload = res.data.data;
            return {
                items: Array.isArray(payload.items) ? payload.items.map(normalizarItemListado) : [],
                total: Number(payload.total || 0),
                pagina: Number(payload.pagina || 1),
                porPagina: Number(payload.por_pagina || POR_PAGINA),
                totalPaginas: Number(payload.total_paginas || 1)
            };
        },

        obtenerMovimiento: async function (id) {
            var res = await Api.get('inventario/movimientos/' + encodeURIComponent(String(id)));
            if (res === null) {
                throw crearError('Consulta cancelada.', 'ABORTADO');
            }
            if (!res.ok || !res.data || res.data.status !== true || !res.data.data) {
                var code = (res.data && res.data.code) || '';
                var msg = (res.data && res.data.message) || 'No se pudo cargar el movimiento.';
                if (res.status === 404 || code === 'MOVEMENT_NOT_FOUND' || code === 'INVALID_ID') {
                    throw crearError(msg, 'NO_ENCONTRADO');
                }
                if (res.status === 401 || res.status === 403) {
                    throw crearError(msg, 'PERMISO');
                }
                throw crearError(msg, 'ERROR');
            }
            return normalizarDetalle(res.data.data);
        },

        guardarCorreccion: async function (id, entrada) {
            var esBaja = Boolean(TIPOS[entrada.tipo] && TIPOS[entrada.tipo].esBaja);
            var payload = {
                tipo: String(entrada.tipo || ''),
                cantidad: Number(entrada.cantidad),
                justificacion: String(entrada.justificacion || '').trim(),
                lote: esBaja && entrada.lote && String(entrada.lote).trim() !== '' ? String(entrada.lote).trim() : null,
                motivo: String(entrada.motivo || '').trim(),
                version: Number(entrada.version),
                stock_actual_esperado: Number(entrada.stockActualEsperado)
            };
            var scope = 'movimiento-corregir-' + String(id);
            var referencia = await Api.referenciaSolicitud(scope, payload);
            var body = Object.assign({}, payload, { referencia_solicitud: referencia });

            var res = await Api.post('inventario/movimientos/' + encodeURIComponent(String(id)) + '/correcciones', body);
            if (!res) {
                throw crearError('No se pudo comunicar con el servidor.', 'ERROR');
            }
            if (res.ok && res.data && res.data.status === true && res.data.data) {
                Api.confirmarSolicitud(scope);
                var mov = res.data.data.movimiento || res.data.data;
                return normalizarDetalle(mov);
            }

            var code = (res.data && res.data.code) || '';
            var msg = (res.data && res.data.message) || 'No se pudo guardar la corrección.';
            if (res.status === 404 || code === 'MOVEMENT_NOT_FOUND') {
                throw crearError(msg, 'NO_ENCONTRADO');
            }
            if (code === 'VERSION_CONFLICT' || code === 'STOCK_CHANGED_CONFLICT' || code === 'CONCURRENT_OPERATION_CONFLICT' || code === 'REQUEST_IN_PROGRESS') {
                throw crearError(msg, 'CONFLICTO');
            }
            if (res.status === 422 || code === 'VALIDATION_ERROR' || code === 'NO_CHANGES' || code === 'NEGATIVE_STOCK_RESULT' || code === 'IDEMPOTENCY_PAYLOAD_MISMATCH') {
                throw crearError(msg, 'VALIDACION');
            }
            if (res.status === 401 || res.status === 403) {
                throw crearError(msg, 'PERMISO');
            }
            throw crearError(msg, 'ERROR');
        }
    };
})();
