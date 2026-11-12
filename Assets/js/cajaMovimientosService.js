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
        'invalid_credentials',
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
    var mockMovements = [
        {
            id: 'movement-income-1',
            type: 'admin_injection',
            label: 'Inyección',
            amount: 50,
            current_amount: 50,
            reference: 'Inyección de efectivo · registrada con error',
            reason: 'Ejemplo de registro comprobablemente incorrecto',
            recorded_at: '2026-11-10T09:15:00-05:00',
            status: 'Confirmado'
        },
        {
            id: 'movement-expense-1',
            type: 'cash_out',
            label: 'Salida de caja',
            amount: 20,
            current_amount: 20,
            reference: 'Pago de movilidad',
            reason: 'Salida registrada',
            recorded_at: '2026-11-10T10:20:00-05:00',
            status: 'Confirmado'
        }
    ];
    var mockCorrections = [];

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
            INTERNAL_ERROR: 'error',
            INVALID_ADMIN_CREDENTIALS: 'authorization_denied',
            ADMIN_ROLE_REQUIRED: 'authorization_denied',
            MOVEMENT_NOT_FOUND: 'movement_not_found',
            CORRECTION_NOT_ALLOWED: 'correction_not_allowed'
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
            session_id: data.session_id || data.sesion_id,
            balance: Number(data.current_balance !== undefined ? data.current_balance : data.balance)
        };
    }

    function normalizeResult(response) {
        var parts = responseData(response);
        var body = parts.body;
        var data = parts.data;
        var code = body.code || data.code || (body.status === true ? 'SUCCESS' : 'INTERNAL_ERROR');
        if (code === 'SUCCESS' || code === 'MOVEMENT_REGISTERED'
            || code === 'CORRECTION_SAVED' || body.status === true || body.type === 'success') {
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

    function mockListCorrectableMovements() {
        if (!allowedScenarios.includes(mockScenario)) {
            return Promise.reject(new Error('CAJA_MOVIMIENTOS_MOCK_SCENARIO no es un caso válido.'));
        }
        if (!mockSession.is_open) return Promise.reject(new Error('No hay una sesión de caja abierta.'));
        return wait({ movements: mockMovements, corrections: mockCorrections });
    }

    function mockRequestCorrection(payload) {
        if (!allowedScenarios.includes(mockScenario)) {
            return Promise.reject(new Error('CAJA_MOVIMIENTOS_MOCK_SCENARIO no es un caso válido.'));
        }
        if (!mockSession.is_open) return wait(mockFailure('cash_closed'));
        if (!payload.original_movement_id) return wait(mockFailure('movement_required'));
        if (!Number.isFinite(Number(payload.corrected_amount)) || Number(payload.corrected_amount) < 0) {
            return wait(mockFailure('amount_invalid'));
        }
        if (!String(payload.reason || '').trim()) return wait(mockFailure('reason_required'));
        if (!String(payload.admin_username || '').trim()) return wait(mockFailure('administrator_required'));
        if (!String(payload.admin_password || '')) return wait(mockFailure('authorization_denied'));
        if (config.sellerUsername && String(payload.admin_username).toLowerCase()
            === String(config.sellerUsername).toLowerCase()) {
            return wait(mockFailure('authorization_denied'));
        }
        if (mockScenario === 'administrator_missing' || mockScenario === 'invalid_credentials') {
            return wait(mockFailure('authorization_denied'));
        }
        if (mockScenario === 'server_error') return wait(mockFailure('error'));
        if (processedRequests.has(payload.request_id)) return wait(processedRequests.get(payload.request_id));

        var original = mockMovements.find(function (item) { return item.id === payload.original_movement_id; });
        if (!original) return wait(mockFailure('movement_not_found'));
        if (original.type === 'omitted_withdrawal' || original.type === 'correction') {
            return wait(mockFailure('correction_not_allowed'));
        }

        var before = Number(original.current_amount);
        var corrected = Number(payload.corrected_amount);
        var isIncome = original.type === 'manual' || original.type === 'admin_injection';
        var balanceEffect = isIncome ? corrected - before : before - corrected;
        mockBalance += balanceEffect;
        mockSession.balance = mockBalance;
        original.current_amount = corrected;
        var correction = {
            id: 'correction-' + payload.request_id,
            original_movement_id: original.id,
            original_reference: original.reference,
            original_amount: Number(original.amount),
            previous_corrected_amount: before,
            corrected_amount: corrected,
            reason: String(payload.reason).trim(),
            effect: balanceEffect < 0 ? 'Resta ' + formatCorrectionMoney(balanceEffect)
                : (balanceEffect > 0 ? 'Suma ' + formatCorrectionMoney(balanceEffect) : 'Sin efecto en el saldo'),
            status: 'Autorizada',
            recorded_at: new Date().toISOString(),
            responsible: payload.admin_username
        };
        mockCorrections.push(correction);
        var result = {
            type: 'success',
            data: {
                correction: correction,
                original_movement: original,
                balance: mockBalance
            }
        };
        processedRequests.set(payload.request_id, result);
        return wait(result);
    }

    function formatCorrectionMoney(value) {
        return 'S/ ' + new Intl.NumberFormat('es-ES', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Math.abs(value));
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
        listCorrectableMovements: function (sessionId) {
            if (mockEnabled) return mockListCorrectableMovements();
            var query = sessionId ? '?session_id=' + encodeURIComponent(sessionId) : '';
            // TODO backend: devolver originales confirmados y correcciones enlazadas de esta sesión.
            return apiRequest('caja/movimientos/corregibles' + query, { method: 'GET' }).then(function (response) {
                var data = responseData(response).data;
                if (!data || !Array.isArray(data.movements) || !Array.isArray(data.corrections)) {
                    throw new Error('El servicio no devolvió los movimientos y correcciones.');
                }
                return data;
            });
        },
        requestCorrection: function (payload) {
            if (mockEnabled) return mockRequestCorrection(payload);
            // TODO backend: validar evidencia/motivo y credenciales admin; nunca editar ni borrar el original.
            return apiRequest('caja/movimientos/correcciones', {
                method: 'POST',
                headers: { 'X-Request-Reference': payload.request_id },
                body: payload
            }).then(normalizeResult);
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
