(function (global) {
    'use strict';

    var openingConfig = global.CAJA_SERVICE_CONFIG || {};
    var arqueoConfig = global.CAJA_ARQUEO_CONFIG || {};
    var config = global.CAJA_WORKFLOWS_CONFIG || {};
    var isOpeningScreen = Boolean(global.CAJA_SERVICE_CONFIG);
    var scenario = config.scenario || openingConfig.mockScenario || arqueoConfig.mockScenario || 'success';
    var latency = Math.max(0, Number(config.latencyMs || arqueoConfig.mockLatencyMs) || 180);
    var reference = Number(openingConfig.referenceAmount);
    var expected = 300;
    var opened = scenario === 'already_open';
    var sessionClosed = scenario === 'session_closed' || scenario === 'no_session';
    var closedSummary = null;
    var version = 1;
    var concurrentChangeSent = false;
    var lostConnectionSent = false;
    var requestResults = new Map();
    var history = [];
    var allowedScenarios = [
        'success', 'authorization_required', 'invalid_credentials', 'motive_required',
        'already_open', 'server_error', 'outside_hours', 'no_shift', 'no_turn_today',
        'no_arqueos', 'no_arqueo', 'stale', 'concurrent_sale', 'session_closed',
        'no_session', 'connection_lost', 'shortage', 'surplus', 'balanced',
        'close_outside_hours'
    ];

    if (!Number.isFinite(reference)) reference = 200;

    var counted = scenario === 'surplus' ? 310 : (scenario === 'balanced' ? 300 : 295);
    var demoDate = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Lima',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).format(new Date());
    if (scenario !== 'no_arqueos' && scenario !== 'no_arqueo') {
        history.push({
            id: 'arqueo-0',
            date: demoDate,
            time: '13:10',
            counted: 290,
            difference: -10,
            observation: 'Arqueo anterior',
            status: 'Reemplazado',
            version: version
        });
        history.push({
            id: 'arqueo-1',
            date: demoDate,
            time: '13:25',
            counted: counted,
            difference: counted - expected,
            observation: counted === expected ? '' : 'Conteo de ejemplo',
            status: scenario === 'stale' ? 'Desactualizado' : 'Vigente',
            version: version
        });
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function wait(value) {
        return new Promise(function (resolve) {
            global.setTimeout(function () { resolve(clone(value)); }, latency);
        });
    }

    function createRequestId() {
        if (!global.crypto || typeof global.crypto.randomUUID !== 'function') {
            throw new Error('Este navegador no permite generar un identificador seguro de solicitud.');
        }
        return global.crypto.randomUUID();
    }

    function currentShift() {
        return {
            name: 'Mañana',
            label: 'Mañana · 08:00–14:00',
            start: '08:00',
            end: '14:00'
        };
    }

    function getTurnoDeHoy(vendedorId) {
        // TODO backend: consultar el turno y la asistencia del vendedor para la fecha local.
        var noShift = scenario === 'no_shift' || scenario === 'no_turn_today';
        var outside = scenario === 'outside_hours' || scenario === 'authorization_required'
            || scenario === 'close_outside_hours';
        var shift = currentShift();
        return wait({
            vendedor_id: vendedorId,
            status: noShift ? 'no_shift' : (outside ? 'outside_hours' : 'within_hours'),
            has_shift_today: !noShift,
            shift: noShift ? null : shift
        });
    }

    function getEstadoCaja(vendedorId) {
        // TODO backend: obtener la sesión propia, sus totales y el estado de cierre vigente.
        var isOpen = isOpeningScreen ? opened || scenario === 'already_open' : !sessionClosed;
        return wait({
            is_open: isOpen,
            is_owner: true,
            session_id: 'demo-session',
            vendedor_id: vendedorId,
            seller: config.sellerName || global.VENDEDOR_NOMBRE || 'Vendedor',
            turn: isOpen ? 'Turno mañana' : '',
            counted_amount: Number(openingConfig.openingCounted) || 190,
            opening_balance: Number(openingConfig.openingCounted) || 190,
            reference_amount: reference,
            reference_date: new Intl.DateTimeFormat('es-PE', {
                timeZone: 'America/Lima',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            }).format(new Date(Date.now() - 86400000)),
            reference_turn: 'Tarde',
            opening_fund: 200,
            cash_sales: 80,
            income: 50,
            expenses: 30,
            expected: expected,
            card: 90,
            yape_plin: 40,
            version: version,
            history: clone(history),
            closed_summary: clone(closedSummary)
        });
    }

    function openedResult(payload) {
        if (payload.request_id && requestResults.has(payload.request_id)) {
            return wait(requestResults.get(payload.request_id));
        }
        opened = true;
        sessionClosed = false;
        var cashCounted = Number(payload.cash_counted);
        var result = {
            type: 'opened',
            data: {
                opening_balance: cashCounted,
                opening_difference: cashCounted - reference,
                opened_at: new Date().toISOString()
            }
        };
        if (payload.request_id) requestResults.set(payload.request_id, result);
        return wait(result);
    }

    function guardarApertura(payload) {
        // TODO backend: registrar apertura con referencia idempotente y respuesta de la sesión creada.
        if (payload.request_id && requestResults.has(payload.request_id)) {
            return wait(requestResults.get(payload.request_id));
        }
        if (opened || scenario === 'already_open') return wait({ type: 'already_open' });
        if (!Number.isFinite(Number(payload.cash_counted)) || Number(payload.cash_counted) < 0) {
            return wait({ type: 'error' });
        }
        if (scenario === 'server_error') return wait({ type: 'error' });
        if (scenario === 'outside_hours' || scenario === 'authorization_required'
            || scenario === 'no_shift' || scenario === 'no_turn_today'
            || scenario === 'invalid_credentials' || scenario === 'motive_required'
            || scenario === 'server_error') {
            return wait({
                type: 'authorization_required',
                schedule: { start: currentShift().start, end: currentShift().end }
            });
        }
        return openedResult(payload);
    }

    function solicitarAutorizacion(payload) {
        // TODO backend: validar rol/credenciales, rechazar autoautorización y autorizar esta apertura o cierre.
        if (payload.request_id && requestResults.has(payload.request_id)) {
            return wait(requestResults.get(payload.request_id));
        }
        if (scenario === 'invalid_credentials') return wait({ type: 'authorization_denied' });
        if (scenario === 'motive_required') return wait({ type: 'motive_required' });
        if (scenario === 'server_error') return wait({ type: 'error' });
        if (!String(payload.admin_username || '').trim()
            || !String(payload.admin_password || '')
            || !String(payload.exception_reason || '').trim()) {
            return wait({ type: 'motive_required' });
        }
        if (config.sellerUsername && String(payload.admin_username).toLowerCase()
            === String(config.sellerUsername).toLowerCase()) {
            return wait({ type: 'authorization_denied', reason: 'self_authorization' });
        }
        if (payload.operation === 'close') {
            return confirmarCierre(payload);
        }
        return openedResult(payload);
    }

    function getArqueos(sesionId) {
        // TODO backend: listar arqueos de la sesión autorizada en orden cronológico.
        return wait({ sesion_id: sesionId, history: clone(history) });
    }

    function guardarArqueo(payload) {
        // TODO backend: guardar el conteo y observación de forma idempotente y validar la versión de caja.
        if (scenario === 'server_error') return wait({ type: 'error' });
        if (!Number.isFinite(Number(payload.counted)) || Number(payload.counted) < 0) {
            return wait({ type: 'count_invalid' });
        }
        if (Number(payload.counted) !== expected && !String(payload.observation || '').trim()) {
            return wait({ type: 'observation_required' });
        }
        if (sessionClosed) return wait({ type: 'session_closed' });
        if (scenario === 'concurrent_sale' && !concurrentChangeSent) {
            concurrentChangeSent = true;
            history.forEach(function (item) {
                if (item.status === 'Vigente') item.status = 'Desactualizado';
            });
            expected += 10;
            version += 1;
            return wait({ type: 'caja_changed', expected: expected, version: version });
        }
        if (requestResults.has(payload.request_id)) return wait(requestResults.get(payload.request_id));
        history.forEach(function (item) {
            if (item.status === 'Vigente') item.status = 'Reemplazado';
        });
        var saved = {
            type: 'saved',
            arqueo: {
                id: 'arqueo-' + (history.length + 1),
                date: demoDate,
                time: new Intl.DateTimeFormat('es-PE', {
                    timeZone: 'America/Lima',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                }).format(new Date()),
                counted: Number(payload.counted),
                difference: Number(payload.counted) - expected,
                observation: String(payload.observation || '').trim(),
                status: 'Vigente',
                version: version
            }
        };
        history.push(saved.arqueo);
        requestResults.set(payload.request_id, saved);
        return wait(saved);
    }

    function confirmarCierre(payload) {
        // TODO backend: confirmar cierre solo con el arqueo más reciente y autorización válida si corresponde.
        if (payload.request_id && requestResults.has(payload.request_id)) {
            return wait(requestResults.get(payload.request_id));
        }
        if (sessionClosed) return wait({ type: 'session_closed', summary: closedSummary });
        var current = history.find(function (item) { return item.status === 'Vigente'; });
        if (!current || current.id !== payload.arqueo_id || Number(current.version) !== Number(version)) {
            return wait({ type: 'arqueo_stale' });
        }
        if (scenario === 'server_error') return wait({ type: 'error' });
        if ((scenario === 'authorization_required' || scenario === 'close_outside_hours')
            && !payload.admin_username) {
            var shift = currentShift();
            return wait({ type: 'authorization_required', schedule: { start: shift.start, end: shift.end } });
        }
        if (requestResults.has(payload.request_id)) return wait(requestResults.get(payload.request_id));
        closedSummary = {
            expected: expected,
            counted: Number(current.counted),
            difference: Number(current.difference),
            observation: current.observation,
            closed_at: new Date().toISOString()
        };
        opened = false;
        sessionClosed = true;
        var result = { type: 'closed', summary: clone(closedSummary) };
        requestResults.set(payload.request_id, result);
        if (scenario === 'connection_lost' && !lostConnectionSent) {
            lostConnectionSent = true;
            return new Promise(function (resolve, reject) {
                global.setTimeout(function () {
                    reject(new Error('Conexión perdida después de confirmar el cierre.'));
                }, latency);
            });
        }
        return wait(result);
    }

    function getCierre(sesionId) {
        // TODO backend: recuperar el cierre persistido para reconciliar reintentos y errores de conexión.
        return wait(clone(closedSummary ? { sesion_id: sesionId, summary: closedSummary } : null));
    }

    function guarded(method) {
        return function () {
            if (!allowedScenarios.includes(scenario)) {
                return Promise.reject(new Error('CAJA_WORKFLOWS_MOCK_SCENARIO no es un caso válido.'));
            }
            return method.apply(null, arguments);
        };
    }

    global.CajaDemoService = {
        createRequestId: createRequestId,
        getTurnoDeHoy: guarded(getTurnoDeHoy),
        getEstadoCaja: guarded(getEstadoCaja),
        guardarApertura: guarded(guardarApertura),
        solicitarAutorizacion: guarded(solicitarAutorizacion),
        getArqueos: guarded(getArqueos),
        guardarArqueo: guarded(guardarArqueo),
        confirmarCierre: guarded(confirmarCierre),
        getCierre: guarded(getCierre)
    };
}(window));
