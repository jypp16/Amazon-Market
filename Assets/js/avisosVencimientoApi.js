(function () {
    function esperar(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    function crearAvisosMock() {
        return [
            { id: 'av-001', categoria: 'Lácteos', subcategoria: 'Leches', producto: 'Leche evaporada 400 g', fechaVencimiento: '2026-11-14', condicion: 'fecha_pasada', lote: null },
            { id: 'av-002', categoria: 'Abarrotes', subcategoria: 'Aceites', producto: 'Aceite Vegetal PRIMOR Clásico Botella 1.8L', fechaVencimiento: '2026-11-20', condicion: 'vence_hoy', lote: 'L-2403' },
            { id: 'av-003', categoria: 'Lácteos', subcategoria: 'Yogures', producto: 'Yogur natural 1 L', fechaVencimiento: '2026-11-25', condicion: 'proximo_a_vencer', lote: null },
            { id: 'av-004', categoria: 'Lácteos', subcategoria: 'Yogures', producto: 'Yogur natural 1 L', fechaVencimiento: '2026-11-30', condicion: 'proximo_a_vencer', lote: 'L-2411' }
        ];
    }

    var avisosActivos = null;
    var fallosSimulados = 0;
    var fallosConsultaSimulados = 0;
    var fallosResumenSimulados = 0;

    window.AvisosVencimientoApi = {
        obtenerAvisos: async function () {
            // TODO backend: reemplazar este mock por la consulta real de avisos de vencimiento.
            await esperar(650);

            var parametros = new URLSearchParams(window.location.search);
            if (parametros.get('mockError') === '1' && fallosConsultaSimulados === 0) {
                fallosConsultaSimulados += 1;
                throw new Error('No se pudo consultar los avisos de vencimiento.');
            }

            if (avisosActivos === null) {
                avisosActivos = crearAvisosMock();
            }
            return avisosActivos.map(function (aviso) {
                return Object.assign({}, aviso);
            });
        },

        registrarRevision: async function (id, respuesta) {
            // TODO backend: reemplazar este mock por el servicio real de registro de revisiones.
            await esperar(700);

            var parametros = new URLSearchParams(window.location.search);
            if (parametros.get('mockSaveError') === '1' && fallosSimulados === 0) {
                fallosSimulados += 1;
                throw new Error('No se pudo guardar la respuesta.');
            }

            if (respuesta === 'no') {
                avisosActivos = avisosActivos.filter(function (aviso) {
                    return aviso.id !== id;
                });
            }

            return {
                estado: respuesta === 'no' ? 'cerrado' : 'activo',
                avisosActivos: avisosActivos.length
            };
        },

        obtenerResumenAvisos: async function () {
            // TODO backend: reemplazar este mock por el resumen real de avisos activos.
            await esperar(450);

            var parametros = new URLSearchParams(window.location.search);
            if (parametros.get('mockResumenError') === '1' && fallosResumenSimulados === 0) {
                fallosResumenSimulados += 1;
                throw new Error('No se pudo consultar el resumen de avisos.');
            }

            if (avisosActivos === null) {
                avisosActivos = crearAvisosMock();
            }

            return { avisosActivos: avisosActivos.length };
        }
    };
})();