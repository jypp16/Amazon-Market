(function () {
    function crearError(mensaje, codigo, data) {
        var e = new Error(mensaje);
        e.codigo = codigo || 'ERROR';
        if (data !== undefined) e.data = data;
        return e;
    }

    function normalizarAviso(item) {
        return {
            id: String(item.id != null ? item.id : item.id_aviso),
            idAviso: Number(item.id_aviso != null ? item.id_aviso : item.id),
            idLineaCompra: Number(item.id_linea_compra || 0),
            idDetalleCompra: Number(item.id_detalle_compra || 0),
            categoria: String(item.categoria || ''),
            subcategoria: String(item.subcategoria || 'General'),
            producto: String(item.producto || ''),
            fechaVencimiento: String(item.fecha_vencimiento || ''),
            condicion: String(item.condicion || 'proximo_a_vencer'),
            lote: item.lote != null && item.lote !== '' ? String(item.lote) : null
        };
    }

    window.AvisosVencimientoApi = {
        obtenerAvisos: async function () {
            var res = await Api.get('alertas/vencimientos');
            if (!res || !res.ok || !res.data || res.data.status !== true || !res.data.data) {
                var msg = (res && res.data && res.data.message) || 'No se pudieron consultar los avisos de vencimiento.';
                var code = (res && res.data && res.data.code) || 'ERROR';
                throw crearError(msg, code);
            }
            var items = Array.isArray(res.data.data.items) ? res.data.data.items : [];
            return items.map(normalizarAviso);
        },

        registrarRevision: async function (id, respuesta, meta) {
            var fechaVista = meta && meta.fechaVencimiento ? String(meta.fechaVencimiento) : '';
            var idDetalle = meta && meta.idDetalleCompra ? Number(meta.idDetalleCompra) : null;
            var payload = {
                respuesta: String(respuesta || ''),
                fecha_vencimiento_vista: fechaVista,
                id_detalle_compra_visto: idDetalle
            };
            var scope = 'aviso-revision-' + String(id) + '-' + fechaVista + '-' + String(respuesta);
            var referencia = await Api.referenciaSolicitud(scope, payload);
            var body = Object.assign({}, payload, { referencia_solicitud: referencia });

            var res = await Api.post('alertas/vencimientos/' + encodeURIComponent(String(id)) + '/revisiones', body);
            if (!res) {
                throw crearError('No se pudo comunicar con el servidor.', 'ERROR');
            }
            if (res.ok && res.data && res.data.status === true && res.data.data) {
                Api.confirmarSolicitud(scope);
                var d = res.data.data;
                return {
                    estado: String(d.estado || (respuesta === 'no' ? 'cerrado' : 'activo')),
                    avisosActivos: Number(d.avisos_activos != null ? d.avisos_activos : 0),
                    idRevision: Number(d.id_revision || 0)
                };
            }

            var code = (res.data && res.data.code) || 'ERROR';
            var msg = (res.data && res.data.message) || 'No se pudo guardar la respuesta.';
            if (code === 'EXPIRATION_DATE_CHANGED' || code === 'AVISO_ALREADY_CLOSED' || code === 'AVISO_NOT_ELIGIBLE') {
                Api.confirmarSolicitud(scope);
                throw crearError(msg, 'CONFLICTO_VIGENCIA', res.data && res.data.data);
            }
            throw crearError(msg, code);
        },

        obtenerResumenAvisos: async function () {
            var res = await Api.get('alertas/vencimientos/resumen');
            if (!res || !res.ok || !res.data || res.data.status !== true || !res.data.data) {
                var msg = (res && res.data && res.data.message) || 'No se pudo consultar el resumen de avisos.';
                throw crearError(msg, (res && res.data && res.data.code) || 'ERROR');
            }
            return {
                avisosActivos: Number(res.data.data.avisos_activos)
            };
        }
    };
})();
