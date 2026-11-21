(function () {
    var CLAVE_MOCK = 'amazonMarketMockMovimientos';
    var POR_PAGINA = 10; // TODO backend: confirmar tamaño de página.

    var TIPOS = {
        baja_danio: { etiqueta: 'Baja por daño', signo: -1, esBaja: true },
        baja_vencimiento: { etiqueta: 'Baja por vencimiento', signo: -1, esBaja: true },
        ajuste_positivo: { etiqueta: 'Ajuste positivo', signo: 1, esBaja: false },
        ajuste_negativo: { etiqueta: 'Ajuste negativo', signo: -1, esBaja: false }
    };

    function esperar(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function error(mensaje, codigo) {
        var e = new Error(mensaje);
        e.codigo = codigo || 'ERROR';
        return e;
    }

    function parametro(nombre) {
        return new URLSearchParams(window.location.search).get(nombre);
    }

    function crearMock() {
        function mov(id, fechaHora, producto, codigoBarras, tipo, cantidad, justificacion, lote, saldoAnterior, stockActual, original, correcciones) {
            var signo = TIPOS[tipo].signo;
            return {
                id: id, fechaHora: fechaHora, responsable: 'Admin', producto: producto, codigoBarras: codigoBarras,
                tipo: tipo, cantidad: cantidad, justificacion: justificacion, lote: lote,
                saldoAnterior: original ? original.saldoAnterior : saldoAnterior,
                saldoResultante: original ? original.saldoResultante : saldoAnterior + signo * cantidad,
                original: original || { tipo: tipo, cantidad: cantidad },
                stockActual: stockActual, correcciones: correcciones || [], version: (correcciones || []).length + 1
            };
        }
        return [
            mov(128, '2026-11-20T10:42', 'Leche evaporada 400 g', '0775000000101', 'baja_vencimiento', 3, 'Latas golpeadas al descargar', null, 20, 17,
                { tipo: 'baja_danio', cantidad: 2, saldoAnterior: 20, saldoResultante: 18 }, [
                    { id: 1, fechaHora: '2026-11-20T11:10', responsable: 'Admin', motivo: 'Se contaron mal las unidades',
                      cambios: [{ campo: 'cantidad', anterior: 2, nuevo: 3 }] },
                    { id: 2, fechaHora: '2026-11-20T15:20', responsable: 'Admin', motivo: 'Las latas estaban vencidas, no dañadas',
                      cambios: [{ campo: 'tipo', anterior: 'baja_danio', nuevo: 'baja_vencimiento' }] }
                ]),
            mov(127, '2026-11-20T09:15', 'Agua San Luis 500 ml', '7750182003049', 'ajuste_positivo', 1, 'Unidad encontrada en el conteo físico', null, 47, 48),
            mov(126, '2026-11-19T17:30', 'Arroz Extra BELL’S Bolsa 750g', '2000000000005', 'ajuste_negativo', 5, 'Faltante en el conteo físico', null, 55, 50),
            mov(125, '2026-11-19T11:05', 'Aceite Vegetal PRIMOR Clásico Botella 1.8L', '7750243059381', 'baja_vencimiento', 3, 'Botellas vencidas retiradas del anaquel', 'L-2403', 47, 44),
            mov(124, '2026-11-18T16:48', 'Agua Mineral SAN MATEO Sin Gas Bidón 7L', '7753749002059', 'baja_danio', 1, 'Bidón roto al reponer', null, 51, 50),
            mov(123, '2026-11-18T09:20', 'Aceite de Oliva EL OLIVAR Extra Virgen 500ml', '2000000000002', 'ajuste_positivo', 4, 'Cajas halladas en almacén', null, 43, 47)
        ];
    }

    function leer() {
        // TODO backend: reemplazar este mock por la consulta real. El mock se guarda en sessionStorage solo para poder navegar entre pantallas.
        try {
            var guardado = window.sessionStorage.getItem(CLAVE_MOCK);
            if (guardado) return JSON.parse(guardado);
        } catch (e) { /* sin almacenamiento: se usa memoria */ }
        return crearMock();
    }

    var memoria = null;
    function datos() {
        if (memoria === null) memoria = leer();
        return memoria;
    }

    function guardar() {
        try { window.sessionStorage.setItem(CLAVE_MOCK, JSON.stringify(memoria)); } catch (e) { /* ignorar */ }
    }

    function copia(objeto) { return JSON.parse(JSON.stringify(objeto)); }

    var fallosCarga = 0, fallosGuardado = 0, conflictos = 0;

    function simularFalloCarga() {
        if (parametro('mockError') === '1' && fallosCarga === 0) {
            fallosCarga += 1;
            throw error('No se pudo consultar los movimientos.', 'ERROR');
        }
    }

    window.MovimientosInventarioApi = {
        tipos: TIPOS,
        motivoMax: 250, // TODO backend: límite de caracteres del motivo por definir.

        listarMovimientos: async function (filtros) {
            // TODO backend: GET de movimientos con búsqueda, tipo, rango de fechas y paginación.
            await esperar(500);
            simularFalloCarga();
            var q = (filtros.q || '').trim().toLowerCase();
            var lista = datos().filter(function (m) {
                if (q && m.producto.toLowerCase().indexOf(q) === -1 && m.codigoBarras.indexOf(q) === -1) return false;
                if (filtros.tipo && m.tipo !== filtros.tipo) return false;
                var dia = m.fechaHora.slice(0, 10);
                if (filtros.desde && dia < filtros.desde) return false;
                if (filtros.hasta && dia > filtros.hasta) return false;
                return true;
            }).sort(function (a, b) { return b.id - a.id; });
            var pagina = Math.max(1, parseInt(filtros.pagina, 10) || 1);
            var totalPaginas = Math.max(1, Math.ceil(lista.length / POR_PAGINA));
            pagina = Math.min(pagina, totalPaginas);
            var items = lista.slice((pagina - 1) * POR_PAGINA, pagina * POR_PAGINA).map(function (m) {
                return { id: m.id, fechaHora: m.fechaHora, producto: m.producto, codigoBarras: m.codigoBarras, tipo: m.tipo,
                         cantidad: m.cantidad, corregido: m.correcciones.length > 0 };
            });
            return { items: items, total: lista.length, pagina: pagina, totalPaginas: totalPaginas };
        },

        obtenerMovimiento: async function (id) {
            // TODO backend: GET del movimiento con registro original y correcciones en orden cronológico.
            await esperar(500);
            simularFalloCarga();
            var m = datos().find(function (x) { return x.id === Number(id); });
            if (!m) throw error('El movimiento no existe.', 'NO_ENCONTRADO');
            return copia(m);
        },

        guardarCorreccion: async function (id, entrada) {
            // TODO backend: POST de la corrección (validar permisos, versión y saldo; guardar todo en una transacción).
            await esperar(700);
            if (parametro('mockSaveError') === '1' && fallosGuardado === 0) {
                fallosGuardado += 1;
                throw error('No se pudo guardar la corrección.', 'ERROR');
            }
            var m = datos().find(function (x) { return x.id === Number(id); });
            if (!m) throw error('El movimiento no existe.', 'NO_ENCONTRADO');
            if ((parametro('mockConflict') === '1' && conflictos === 0) || entrada.version !== m.version) {
                conflictos += 1;
                throw error('El movimiento o su saldo cambió mientras revisabas.', 'CONFLICTO');
            }

            var esBaja = TIPOS[entrada.tipo].esBaja;
            var nuevo = { tipo: entrada.tipo, cantidad: entrada.cantidad, justificacion: entrada.justificacion, lote: esBaja ? (entrada.lote || null) : null };
            var cambios = [];
            ['cantidad', 'tipo', 'justificacion', 'lote'].forEach(function (campo) {
                if ((m[campo] || null) !== (nuevo[campo] || null)) cambios.push({ campo: campo, anterior: m[campo], nuevo: nuevo[campo] });
            });
            if (!cambios.length) throw error('No se modificó ningún dato.', 'VALIDACION');

            var saldo = m.stockActual - TIPOS[m.tipo].signo * m.cantidad + TIPOS[nuevo.tipo].signo * nuevo.cantidad;
            if (saldo < 0) throw error('El saldo resultante sería ' + saldo + ' unidades.', 'VALIDACION');

            var ahora = new Date();
            var pad = function (n) { return String(n).padStart(2, '0'); };
            m.correcciones.push({
                id: m.correcciones.length + 1,
                fechaHora: ahora.getFullYear() + '-' + pad(ahora.getMonth() + 1) + '-' + pad(ahora.getDate()) + 'T' + pad(ahora.getHours()) + ':' + pad(ahora.getMinutes()),
                responsable: 'Admin', motivo: entrada.motivo, cambios: cambios
            });
            m.tipo = nuevo.tipo; m.cantidad = nuevo.cantidad; m.justificacion = nuevo.justificacion; m.lote = nuevo.lote;
            m.stockActual = saldo;
            m.version += 1;
            guardar();
            return copia(m);
        }
    };
})();