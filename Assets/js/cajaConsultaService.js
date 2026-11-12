(function (global) {
    'use strict';

    var config = global.CAJA_CONSULTA_CONFIG || {};
    var mockEnabled = config.mockEnabled === true;
    var scenario = config.mockScenario || 'admin_data';
    var latency = Math.max(0, Number(config.mockLatencyMs) || 0);
    var pageSize = Math.max(1, Number(config.pageSize) || 10);
    var defaultDate = config.defaultDate || '';
    var sellers = [
        { id: 'seller-luis', name: 'Luis' },
        { id: 'seller-maria', name: 'María' },
        { id: 'seller-ana', name: 'Ana' }
    ];

    var baseMovements = [
        { id: 'mov-1', time: '09:40', type: 'cash_sale', reference: 'Boleta B001-000120', amount: 80, effect: 'Suma', responsible: 'Luis' },
        { id: 'mov-2', time: '10:15', type: 'admin_injection', reference: 'Cambio para caja · Entregó: Admin', amount: 50, effect: 'Suma', responsible: 'Luis' },
        { id: 'mov-3', time: '11:30', type: 'admin_withdrawal', reference: 'Pago a proveedor · Recibió: Admin', amount: 30, effect: 'Resta', responsible: 'Luis' },
        { id: 'mov-4', time: '11:45', type: 'omitted_withdrawal', reference: 'Turno 19/10 tarde · registrado hoy', amount: 300, effect: 'Sin efecto: ya reflejado en la apertura', responsible: 'Luis' }
    ];

    var typeLabels = {
        cash_sale: 'Venta en efectivo',
        admin_injection: 'Inyección',
        manual_income: 'Ingreso manual',
        admin_withdrawal: 'Retiro',
        cash_out: 'Salida de caja',
        omitted_withdrawal: 'Retiro omitido'
    };
    var allowedScenarios = [
        'admin_data', 'admin_empty', 'seller_active', 'seller_no_cash',
        'access_denied', 'no_movements', 'multiple_pages', 'multiple_sessions', 'server_error'
    ];

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function delay(value) {
        var wait = scenario === 'server_error' ? Math.max(latency, 100) : latency;
        return new Promise(function (resolve) {
            global.setTimeout(function () { resolve(clone(value)); }, wait);
        });
    }

    function fail(type) {
        return delay({ type: type });
    }

    function allMovements(date) {
        if (scenario !== 'multiple_pages') {
            return clone(baseMovements).map(function (movement) {
                movement.date = date || defaultDate;
                return movement;
            });
        }
        var result = [];
        for (var index = 0; index < 12; index += 1) {
            var movement = clone(baseMovements[index % baseMovements.length]);
            movement.id = 'mov-' + (index + 1);
            movement.date = date || defaultDate;
            movement.time = String(8 + Math.floor(index / 2)).padStart(2, '0')
                + ':' + (index % 2 ? '30' : '00');
            result.push(movement);
        }
        return result;
    }

    function sessionSummary(empty) {
        return {
            opening_balance: 200,
            cash_sales: empty ? 0 : 80,
            income: empty ? 0 : 50,
            expenses: empty ? 0 : 30,
            current_balance: empty ? 200 : 300,
            card: 90,
            yape_plin: 0
        };
    }

    function sessionsFor(filters) {
        if (scenario === 'admin_empty') return [];
        if (scenario === 'multiple_sessions') {
            return [
                {
                    id: 'session-luis-morning',
                    seller: 'Luis',
                    turn: 'Mañana',
                    status: 'Cerrada',
                    opened_at: filters.date + 'T08:00:00-05:00',
                    closed_at: filters.date + 'T11:00:00-05:00'
                },
                {
                    id: 'session-luis-evening',
                    seller: 'Luis',
                    turn: 'Tarde',
                    status: 'Abierta',
                    opened_at: filters.date + 'T16:00:00-05:00',
                    closed_at: null
                }
            ];
        }
        return [{
            id: 'session-' + filters.seller_id + '-' + filters.date,
            seller: (sellers.find(function (item) { return item.id === filters.seller_id; }) || {}).name || 'Luis',
            turn: 'Turno mañana',
            status: 'Abierta',
            opened_at: filters.date + 'T08:00:00-05:00',
            closed_at: null
        }];
    }

    function makeResult(filters, page) {
        if (scenario === 'access_denied') return { type: 'access_denied' };

        var sessions = sessionsFor(filters);
        var selectedSession = sessions.find(function (item) { return item.id === filters.session_id; })
            || sessions[0];
        if (!selectedSession) {
            return { type: 'empty', session: null, summary: null, movements: [], pagination: { page: page, page_size: pageSize, total_pages: 0, total_movements: 0 } };
        }
        var noMovements = scenario === 'no_movements';
        var movements = noMovements ? [] : allMovements(filters.date);
        var start = (page - 1) * pageSize;
        var rows = movements.slice(start, start + pageSize);
        return {
            type: 'result',
            session: Object.assign({}, selectedSession, { session_id: selectedSession.id }),
            summary: sessionSummary(noMovements),
            movements: rows,
            pagination: {
                page: page,
                page_size: pageSize,
                total_pages: Math.ceil(movements.length / pageSize),
                total_movements: movements.length
            }
        };
    }

    function mockListSellers() {
        if (!allowedScenarios.includes(scenario)) {
            return Promise.reject(new Error('CAJA_CONSULTA_MOCK_SCENARIO no es un caso válido.'));
        }
        if (scenario === 'server_error') return Promise.reject(new Error('No se pudo cargar la lista de vendedores.'));
        return delay(sellers);
    }

    function mockQuery(filters) {
        if (!allowedScenarios.includes(scenario)) {
            return Promise.reject(new Error('CAJA_CONSULTA_MOCK_SCENARIO no es un caso válido.'));
        }
        if (scenario === 'server_error') return fail('error');
        if (scenario === 'access_denied') return fail('access_denied');
        return delay(makeResult(filters, Number(filters.page) || 1));
    }

    function mockListSessions(filters) {
        if (!allowedScenarios.includes(scenario)) {
            return Promise.reject(new Error('CAJA_CONSULTA_MOCK_SCENARIO no es un escenario válido.'));
        }
        if (scenario === 'server_error') return Promise.reject(new Error('No se pudieron cargar las sesiones.'));
        if (scenario === 'access_denied') return delay({ type: 'access_denied' });
        return delay(sessionsFor(filters));
    }

    function mockActiveSession(filters) {
        if (!allowedScenarios.includes(scenario)) {
            return Promise.reject(new Error('CAJA_CONSULTA_MOCK_SCENARIO no es un caso válido.'));
        }
        if (scenario === 'seller_no_cash') return fail('no_session');
        if (scenario === 'access_denied') return fail('access_denied');
        if (scenario === 'server_error') return fail('error');
        var page = Number(filters && filters.page) || 1;
        return delay(makeResult({ seller_id: 'seller-luis' }, page));
    }

    function apiRequest(endpoint, options) {
        // TODO: Confirmar rutas, filtros y formato de respuesta con el backend.
        return Api.request(endpoint, options).then(function (response) {
            if (!response) throw new Error('La solicitud de consulta fue cancelada.');
            if (response.status === 403 || (response.data && response.data.code === 'FORBIDDEN')) {
                return { type: 'access_denied' };
            }
            if (response.status === 404 || (response.data && ['NO_SESSION', 'NO_OPEN_SESSION', 'NO_OPENINGS'].includes(response.data.code))) {
                return { type: 'empty' };
            }
            if (!response.ok) return { type: 'error' };
            return response.data && response.data.data ? response.data.data : response.data;
        });
    }

    function normalizeResult(result) {
        if (result && result.type) return result;
        if (!result || !result.summary || !Array.isArray(result.movements)) {
            throw new Error('La respuesta de consulta de caja tiene un formato incompleto.');
        }
        return {
            type: 'result',
            session: result.session || {},
            summary: result.summary,
            movements: result.movements,
            pagination: result.pagination || {}
        };
    }

    global.CajaConsultaService = {
        typeLabels: typeLabels,
        listSessions: function (filters) {
            if (mockEnabled) return mockListSessions(filters);
            var query = 'date=' + encodeURIComponent(filters.date)
                + '&seller_id=' + encodeURIComponent(filters.seller_id);
            return apiRequest('caja/consulta/sesiones?' + query, { method: 'GET' }).then(function (data) {
                if (data.type === 'access_denied') return data;
                if (data.type === 'empty') return [];
                if (!Array.isArray(data)) throw new Error('El servicio no devolvió la lista de sesiones.');
                return data;
            });
        },
        listSellers: function () {
            if (mockEnabled) return mockListSellers();
            return apiRequest('caja/consulta/vendedores', { method: 'GET' }).then(function (data) {
                if (data.type) throw new Error(data.type);
                if (!Array.isArray(data)) throw new Error('El servicio no devolvió la lista de vendedores.');
                return data;
            });
        },
        query: function (filters) {
            if (mockEnabled) return mockQuery(filters).then(normalizeResult);
            var query = [
                'session_id=' + encodeURIComponent(filters.session_id),
                'page=' + encodeURIComponent(filters.page),
                'page_size=' + encodeURIComponent(pageSize)
            ].join('&');
            return apiRequest('caja/consulta?' + query, { method: 'GET' }).then(normalizeResult);
        },
        getActiveSession: function (filters) {
            filters = filters || {};
            if (mockEnabled) return mockActiveSession(filters).then(normalizeResult);
            var query = [
                'page=' + encodeURIComponent(Number(filters.page) || 1),
                'page_size=' + encodeURIComponent(pageSize)
            ].join('&');
            // TODO: Defer display of session dates to the API contract, especially for overnight shifts.
            return apiRequest('caja/consulta/activa?' + query, { method: 'GET' }).then(normalizeResult);
        }
    };
}(window));
