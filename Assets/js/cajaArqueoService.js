(function (global) {
    'use strict';

    var config = global.CAJA_ARQUEO_CONFIG || {};
    var mockEnabled = config.mockEnabled === true;
    var mockScenario = config.mockScenario || 'success';
    var latency = Math.max(0, Number(config.mockLatencyMs) || 0);
    var requestResults = new Map();
    var concurrentChangeSent = false;
    var lostConnectionSent = false;
    var authorizationRequested = false;
    var state = {
        is_open: mockScenario !== 'session_closed',
        is_owner: true,
        seller: 'Luis',
        turn: 'Turno mañana',
        expected: 300,
        opening_fund: 200,
        cash_sales: 80,
        income: 50,
        expenses: 30,
        card: 90,
        yape_plin: 40,
        version: 1,
        history: [],
        closed_summary: null
    };

    var sampleCount = mockScenario === 'surplus' ? 310 : (mockScenario === 'balanced' ? 300 : 295);
    state.history = [
        { id: 'arqueo-1', time: '13:10', counted: 290, difference: -10, status: 'Reemplazado', version: 1 },
        {
            id: 'arqueo-2',
            time: '13:25',
            counted: sampleCount,
            difference: sampleCount - state.expected,
            status: mockScenario === 'stale' ? 'Desactualizado' : 'Vigente',
            version: 1
        }
    ];

    var allowedScenarios = [
        'success', 'shortage', 'surplus', 'balanced', 'stale', 'concurrent_sale',
        'session_closed', 'authorization_required', 'connection_lost', 'server_error'
    ];

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function wait(result) {
        return new Promise(function (resolve) {
            global.setTimeout(function () { resolve(clone(result)); }, latency);
        });
    }

    function createRequestId() {
        if (!global.crypto || typeof global.crypto.randomUUID !== 'function') {
            throw new Error('Este navegador no permite generar un identificador seguro de solicitud.');
        }
        return global.crypto.randomUUID();
    }

    function apiRequest(endpoint, options) {
        // TODO: Confirmar endpoints y envoltorios de respuesta con el backend.
        return Api.request(endpoint, options).then(function (response) {
            if (!response) throw new Error('El servicio de caja no devolvió una respuesta.');
            return response;
        });
    }

    function markHistoryOutdated() {
        state.history.forEach(function (item) {
            if (item.status === 'Vigente') item.status = 'Desactualizado';
        });
    }

    function replaceCurrentArqueo() {
        state.history.forEach(function (item) {
            if (item.status === 'Vigente') item.status = 'Reemplazado';
        });
    }

    function closeSession(payload) {
        var summary = {
            expected: Number(state.expected),
            counted: Number(payload.counted),
            difference: Number(payload.difference),
            observation: String(payload.observation || ''),
            closed_at: new Date().toISOString()
        };
        state.is_open = false;
        state.closed_summary = summary;
        return summary;
    }

    function mockGetState() {
        if (!allowedScenarios.includes(mockScenario)) {
            return Promise.reject(new Error('CAJA_ARQUEO_MOCK_SCENARIO no es un caso válido.'));
        }
        return wait(state);
    }

    function mockSaveArqueo(payload) {
        if (!allowedScenarios.includes(mockScenario)) {
            return Promise.reject(new Error('CAJA_ARQUEO_MOCK_SCENARIO no es un caso válido.'));
        }
        if (!state.is_open) return wait({ type: 'session_closed' });
        if (!Number.isFinite(Number(payload.counted)) || Number(payload.counted) < 0) {
            return wait({ type: 'count_invalid' });
        }
        if (Number(payload.counted) !== Number(state.expected) && !String(payload.observation || '').trim()) {
            return wait({ type: 'observation_required' });
        }
        if (mockScenario === 'server_error') return wait({ type: 'error' });
        if (mockScenario === 'concurrent_sale' && !concurrentChangeSent) {
            concurrentChangeSent = true;
            markHistoryOutdated();
            state.cash_sales += 10;
            state.expected += 10;
            state.version += 1;
            return wait({ type: 'caja_changed', expected: state.expected, version: state.version });
        }

        var id = payload.request_id;
        if (requestResults.has(id)) return wait(requestResults.get(id));
        replaceCurrentArqueo();
        var difference = Number(payload.counted) - Number(state.expected);
        var saved = {
            type: 'saved',
            arqueo: {
                id: 'arqueo-' + (state.history.length + 1),
                time: new Intl.DateTimeFormat('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date()),
                counted: Number(payload.counted),
                difference: difference,
                observation: String(payload.observation || '').trim(),
                status: 'Vigente',
                version: Number(state.version)
            }
        };
        state.history.push(saved.arqueo);
        requestResults.set(id, saved);
        return wait(saved);
    }

    function mockCloseCash(payload) {
        if (!allowedScenarios.includes(mockScenario)) {
            return Promise.reject(new Error('CAJA_ARQUEO_MOCK_SCENARIO no es un caso válido.'));
        }
        if (!state.is_open) return wait({ type: 'session_closed', summary: state.closed_summary });
        var active = state.history.find(function (item) { return item.status === 'Vigente'; });
        if (!active || active.id !== payload.arqueo_id || Number(active.version) !== Number(state.version)) {
            return wait({ type: 'arqueo_stale' });
        }
        if (mockScenario === 'server_error') return wait({ type: 'error' });
        if (mockScenario === 'authorization_required' && !payload.admin_username) {
            authorizationRequested = true;
            return wait({ type: 'authorization_required', schedule: { start: '08:00', end: '14:00' } });
        }
        if (mockScenario === 'authorization_required' && authorizationRequested
            && (!payload.admin_username || !payload.admin_password || !payload.exception_reason)) {
            return wait({ type: 'authorization_denied' });
        }

        var id = payload.request_id;
        if (requestResults.has(id)) return wait(requestResults.get(id));
        var summary = closeSession({
            counted: active.counted,
            difference: active.difference,
            observation: active.observation
        });
        var result = { type: 'closed', summary: summary };
        requestResults.set(id, result);
        if (mockScenario === 'connection_lost' && !lostConnectionSent) {
            lostConnectionSent = true;
            return new Promise(function (resolve, reject) {
                global.setTimeout(function () { reject(new Error('Conexión perdida después de confirmar el cierre.')); }, latency);
            });
        }
        return wait(result);
    }

    function normalizeState(response) {
        // TODO: Adaptar el contrato real de estado cuando el backend lo confirme.
        var body = response && response.data ? response.data : {};
        var data = body.data || body;
        return {
            is_open: Boolean(data.is_open || data.open),
            is_owner: data.is_owner !== false,
            seller: data.seller || data.vendor || '',
            turn: data.turn || data.shift || '',
            expected: Number(data.expected !== undefined ? data.expected : data.expected_cash),
            opening_fund: Number(data.opening_fund),
            cash_sales: Number(data.cash_sales),
            income: Number(data.income),
            expenses: Number(data.expenses),
            card: Number(data.card),
            yape_plin: Number(data.yape_plin),
            version: Number(data.version),
            history: data.history || data.arqueos || [],
            closed_summary: data.closed_summary || null
        };
    }

    function normalizeResult(response, endpoint) {
        var body = response && response.data ? response.data : {};
        var data = body.data || body;
        var codes = {
            ARQUEO_SAVED: 'saved',
            CASH_CHANGED: 'caja_changed',
            CASH_SESSION_CLOSED: 'session_closed',
            SESSION_NOT_OWNED: 'session_not_owned',
            SESSION_CLOSED: 'session_closed',
            ARQUEO_MISSING: 'arqueo_stale',
            ARQUEO_STALE: 'arqueo_stale',
            AUTHORIZATION_REQUIRED: 'authorization_required',
            CLOSE_CONFIRMED: 'closed',
            INVALID_ADMIN_CREDENTIALS: 'authorization_denied',
            OBSERVATION_REQUIRED: 'observation_required',
            INVALID_COUNT: 'count_invalid'
        };
        var code = body.code || data.code;
        var type = code === 'SUCCESS'
            ? (endpoint === 'caja/arqueo' ? 'saved' : 'closed')
            : (codes[code] || (body.status === true
                ? (endpoint === 'caja/arqueo' ? 'saved' : 'closed')
                : 'error'));
        return Object.assign({ type: type }, data);
    }

    function request(endpoint, options, mockMethod) {
        if (mockEnabled) return mockMethod();
        return apiRequest(endpoint, options).then(function (response) {
            return normalizeResult(response, endpoint);
        });
    }

    global.CajaArqueoService = {
        createRequestId: createRequestId,
        getState: function () {
            if (mockEnabled) return mockGetState();
            return apiRequest('caja/arqueo/estado', { method: 'GET' }).then(normalizeState);
        },
        saveArqueo: function (payload) {
            return request('caja/arqueo', {
                method: 'POST',
                headers: { 'X-Request-Reference': payload.request_id },
                body: payload
            }, function () { return mockSaveArqueo(payload); });
        },
        closeCash: function (payload) {
            return request('caja/cerrar', {
                method: 'POST',
                headers: { 'X-Request-Reference': payload.request_id },
                body: payload
            }, function () { return mockCloseCash(payload); });
        }
    };
}(window));
