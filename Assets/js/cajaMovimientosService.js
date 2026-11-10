(function (global) {
    'use strict';

    var config = global.CAJA_MOVEMENTS_CONFIG || {};
    var mockEnabled = config.mockEnabled === true;
    var mockScenario = config.mockScenario || 'success';
    var mockLatencyMs = Math.max(0, Number(config.mockLatencyMs) || 0);
    var mockBalance = mockScenario === 'insufficient_balance' ? 100 : 250;
    var processedRequests = new Map();
    var allowedScenarios = [
        'success',
        'cash_closed',
        'insufficient_balance',
        'administrator_missing',
        'server_error',
        'latency'
    ];

    var mockSession = {
        is_open: mockScenario !== 'cash_closed',
        seller: 'Luis',
        turn: 'Mañana',
        balance: mockBalance
    };
    var mockAdministrators = [
        { id: 'admin-1', name: 'Admin' },
        { id: 'admin-2', name: 'Administrador de turno' }
    ];
    var mockClosedTurns = [
        {
            id: 'turn-previous-1',
            label: '19/10/2026 · turno tarde (cerrado)',
            expected: 500,
            counted: 200,
            shortage: 300
        }
    ];

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function wait(result) {
        var latency = mockScenario === 'latency' ? Math.max(mockLatencyMs, 1200) : mockLatencyMs;
        return new Promise(function (resolve) {
            global.setTimeout(function () { resolve(clone(result)); }, latency);
        });
    }

    function responseData(response) {
        var body = response && response.data ? response.data : {};
        return { body: body, data: body.data || body };
    }

    function normalizeError(response) {
        var parts = responseData(response);
        var code = parts.body.code || parts.data.code || 'INTERNAL_ERROR';
        var data = parts.data;
        var types = {
            CASH_CLOSED: 'cash_closed',
            CASH_SESSION_CLOSED: 'cash_closed',
            INSUFFICIENT_BALANCE: 'insufficient_balance',
            INVALID_AMOUNT: 'amount_invalid',
            AMOUNT_INVALID: 'amount_invalid',
            MOTIVE_REQUIRED: 'reason_required',
            REASON_REQUIRED: 'reason_required',
            ADMINISTRATOR_REQUIRED: 'administrator_required',
            ADMIN_REQUIRED: 'administrator_required',
            ORIGIN_TURN_REQUIRED: 'origin_turn_required',
            CONFIRMATION_REQUIRED: 'confirmation_required',
            INTERNAL_ERROR: 'error'
        };
        return {
            type: types[code] || 'error',
            code: code,
            current_balance: data.current_balance
        };
    }

    function normalizeSession(response) {
        var parts = responseData(response);
        var data = parts.data;
        return {
            is_open: Boolean(data.is_open || data.open),
            seller: data.seller || data.vendor || '',
            turn: data.turn || data.shift || '',
            balance: Number(data.current_balance !== undefined ? data.current_balance : data.balance)
        };
    }

    function normalizeResult(response) {
        var parts = responseData(response);
        var body = parts.body;
        var data = parts.data;
        var code = body.code || data.code || (body.status === true ? 'SUCCESS' : 'INTERNAL_ERROR');
        if (code === 'SUCCESS' || code === 'MOVEMENT_REGISTERED' || body.status === true) {
            return { type: 'success', data: data };
        }
        return normalizeError(response);
    }

    function apiRequest(endpoint, options) {
        // TODO: Confirmar nombres de endpoints y envoltorios de respuesta con el backend.
        return Api.request(endpoint, options).then(function (response) {
            if (!response) throw new Error('El servicio de caja no devolvió una respuesta.');
            return response;
        });
    }

    function mockFailure(type, extra) {
        return Object.assign({ type: type }, extra || {});
    }

    function validateMockPayload(payload, movementType) {
        if (!mockSession.is_open) return mockFailure('cash_closed');
        if (!Number.isFinite(Number(payload.amount)) || Number(payload.amount) <= 0) {
            return mockFailure('amount_invalid');
        }
        if (!String(payload.reason || '').trim()) return mockFailure('reason_required');

        if (movementType === 'income' && payload.type === 'admin_injection' && !payload.administrator_id) {
            return mockFailure('administrator_required');
        }
        if (movementType === 'expense' && payload.type !== 'cash_out' && !payload.administrator_id) {
            return mockFailure('administrator_required');
        }
        if (movementType === 'expense' && payload.type === 'omitted_withdrawal') {
            if (!payload.origin_turn_id) return mockFailure('origin_turn_required');
            if (!payload.confirm_previous_open) return mockFailure('confirmation_required');
        }
        return null;
    }

    function mockRegister(payload, movementType) {
        if (!allowedScenarios.includes(mockScenario)) {
            return Promise.reject(new Error('CAJA_MOVIMIENTOS_MOCK_SCENARIO no es un caso válido.'));
        }

        var failure = validateMockPayload(payload, movementType);
        if (failure) return wait(failure);
        if (mockScenario === 'cash_closed') {
            mockSession.is_open = false;
            return wait(mockFailure('cash_closed'));
        }
        if (mockScenario === 'administrator_missing'
            && ((movementType === 'income' && payload.type === 'admin_injection')
                || (movementType === 'expense' && payload.type !== 'cash_out'))) {
            return wait(mockFailure('administrator_required'));
        }
        if (mockScenario === 'server_error') return wait(mockFailure('error'));

        if (processedRequests.has(payload.request_id)) {
            return wait(processedRequests.get(payload.request_id));
        }

        if (movementType === 'expense' && mockScenario === 'insufficient_balance') {
            mockBalance = 20;
            mockSession.balance = mockBalance;
            return wait(mockFailure('insufficient_balance', { current_balance: mockBalance }));
        }

        var amount = Number(payload.amount);
        var isOmitted = movementType === 'expense' && payload.type === 'omitted_withdrawal';
        var impact = movementType === 'income' ? amount : (isOmitted ? 0 : -amount);
        if (movementType === 'expense' && !isOmitted && amount > mockBalance) {
            return wait(mockFailure('insufficient_balance', { current_balance: mockBalance }));
        }

        mockBalance += impact;
        mockSession.balance = mockBalance;
        var result = {
            type: 'success',
            data: {
                movement: {
                    id: 'mock-' + payload.request_id,
                    type: payload.type,
                    amount: amount,
                    impact: impact
                },
                balance: mockBalance
            }
        };
        processedRequests.set(payload.request_id, result);
        return wait(result);
    }

    function createRequestId() {
        if (!global.crypto || typeof global.crypto.randomUUID !== 'function') {
            throw new Error('Este navegador no permite generar un identificador seguro de solicitud.');
        }
        return global.crypto.randomUUID();
    }

    global.CajaMovimientosService = {
        createRequestId: createRequestId,
        getSessionState: function () {
            if (mockEnabled) {
                if (!allowedScenarios.includes(mockScenario)) {
                    return Promise.reject(new Error('CAJA_MOVIMIENTOS_MOCK_SCENARIO no es un caso válido.'));
                }
                return wait(Object.assign({}, mockSession, { balance: mockBalance }));
            }
            return apiRequest('caja/sesion-actual', { method: 'GET' }).then(normalizeSession);
        },
        listActiveAdministrators: function () {
            if (mockEnabled) return wait(mockAdministrators);
            return apiRequest('usuarios?rol=administrador&estado=activo', { method: 'GET' })
                .then(function (response) { return responseData(response).data; });
        },
        listClosedTurns: function () {
            if (mockEnabled) return wait(mockClosedTurns);
            return apiRequest('caja/turnos-anteriores?estado=cerrado', { method: 'GET' })
                .then(function (response) { return responseData(response).data; });
        },
        registerIncome: function (payload) {
            if (mockEnabled) return mockRegister(payload, 'income');
            return apiRequest('caja/ingresos', {
                method: 'POST',
                headers: { 'X-Request-Reference': payload.request_id },
                body: payload
            }).then(normalizeResult);
        },
        registerExpense: function (payload) {
            if (mockEnabled) return mockRegister(payload, 'expense');
            return apiRequest('caja/egresos', {
                method: 'POST',
                headers: { 'X-Request-Reference': payload.request_id },
                body: payload
            }).then(normalizeResult);
        }
    };
}(window));
