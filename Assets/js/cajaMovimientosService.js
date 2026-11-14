(function (global) {
    'use strict';

    function responseData(response) {
        var body = response && response.data ? response.data : {};
        return { body: body, data: body.data !== undefined && body.data !== null ? body.data : {} };
    }

    function normalizeError(response) {
        var parts = responseData(response);
        var code = parts.body.code || parts.data.code || 'INTERNAL_ERROR';
        var data = parts.data;
        var types = {
            CASH_CLOSED: 'cash_closed',
            CASH_SESSION_CLOSED: 'cash_closed',
            NO_OPEN_SESSION: 'cash_closed',
            INSUFFICIENT_BALANCE: 'insufficient_balance',
            INVALID_AMOUNT: 'amount_invalid',
            AMOUNT_INVALID: 'amount_invalid',
            MOTIVE_REQUIRED: 'reason_required',
            REASON_REQUIRED: 'reason_required',
            ADMINISTRATOR_REQUIRED: 'administrator_required',
            ADMIN_REQUIRED: 'administrator_required',
            ORIGIN_TURN_REQUIRED: 'origin_turn_required',
            ORIGIN_SESSION_REQUIRED: 'origin_turn_required',
            CONFIRMATION_REQUIRED: 'confirmation_required',
            INTERNAL_ERROR: 'error',
            MOVEMENT_NOT_FOUND: 'movement_not_found',
            CORRECTION_NOT_ALLOWED: 'correction_not_allowed',
            VALIDATION_ERROR: 'validation_error',
            REQUEST_IN_PROGRESS: 'in_progress'
        };
        return {
            type: types[code] || 'error',
            code: code,
            message: parts.body.message || 'No se pudo completar la operación.',
            current_balance: data.current_balance !== undefined ? Number(data.current_balance) : undefined
        };
    }

    var activeSessionId = null;

    async function sendMovement(endpoint, body) {
        var clean = Object.assign({}, body);
        delete clean.request_id; delete clean.referencia_solicitud;
        var scope = endpoint + ':' + (activeSessionId || clean.session_id || 'none');
        var ref = await Api.referenciaSolicitud(scope, clean);
        var response = await apiRequest(endpoint, { method: 'POST', headers: { 'X-Request-Reference': ref }, body: Object.assign({}, clean, {referencia_solicitud: ref}) });
        var result = normalizeResult(response);
        if (result.type === 'success') Api.confirmarSolicitud(scope);
        return result;
    }

    function normalizeSession(response) {
        var parts = responseData(response);
        var data = parts.data;
        if (parts.body.code === 'NO_OPEN_SESSION' || (!response.ok && response.status === 409)) {
            activeSessionId = null;
            return { is_open: false, seller: '', turn: '', session_id: null, balance: null };
        }
        if (!response.ok || !parts.body.status) {
            throw new Error(parts.body.message || 'No se pudo consultar la sesión actual de caja.');
        }
        activeSessionId = data.session_id;
        return {
            is_open: Boolean(data.is_open),
            seller: data.seller || '',
            turn: data.turn || '',
            session_id: data.session_id !== null && data.session_id !== undefined ? Number(data.session_id) : null,
            balance: data.current_balance === null || data.balance === null ? null : Number(data.current_balance !== undefined ? data.current_balance : data.balance)
        };
    }

    function normalizeResult(response) {
        var parts = responseData(response);
        var body = parts.body;
        var data = parts.data;
        var code = body.code || data.code || (body.status === true ? 'SUCCESS' : 'INTERNAL_ERROR');
        if (response.ok && body.status === true && (
            code === 'SUCCESS'
            || code === 'MOVEMENT_REGISTERED'
            || code === 'CORRECTION_SAVED'
            || code === 'OPERATION_ALREADY_CONFIRMED'
            || code === 'INCOME_REGISTERED'
            || code === 'EXPENSE_REGISTERED'
            || code === 'CORRECTION_REGISTERED'
        )) {
            return { type: 'success', code: code, message: body.message || '', data: data };
        }
        return normalizeError(response);
    }

    function apiRequest(endpoint, options) {
        return Api.request(endpoint, options).then(function (response) {
            if (!response) throw new Error('La petición fue interrumpida.');
            return response;
        });
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
            return apiRequest('caja/sesion-actual', { method: 'GET' }).then(normalizeSession);
        },
        listActiveAdministrators: function () {
            return apiRequest('caja/administradores', { method: 'GET' }).then(function (response) {
                var parts = responseData(response);
                if (!response.ok || !parts.body.status) {
                    throw new Error(parts.body.message || 'No se pudieron obtener los administradores activos.');
                }
                var raw = parts.data;
                return Array.isArray(raw) ? raw : (Array.isArray(raw.administrators) ? raw.administrators : []);
            });
        },
        listClosedTurns: function () {
            return apiRequest('caja/turnos-anteriores', { method: 'GET' }).then(function (response) {
                var parts = responseData(response);
                if (!response.ok || !parts.body.status) {
                    throw new Error(parts.body.message || 'No se pudieron obtener las sesiones anteriores.');
                }
                var raw = parts.data;
                return Array.isArray(raw) ? raw : (Array.isArray(raw.turns) ? raw.turns : (Array.isArray(raw.sessions) ? raw.sessions : []));
            });
        },
        listCorrectableMovements: function (sessionId) {
            var query = sessionId ? '?session_id=' + encodeURIComponent(sessionId) : '';
            return apiRequest('caja/movimientos/corregibles' + query, { method: 'GET' }).then(function (response) {
                var parts = responseData(response);
                if (!response.ok || !parts.body.status) {
                    throw new Error(parts.body.message || 'No se pudieron obtener los movimientos corregibles.');
                }
                var data = parts.data;
                if (!data || !Array.isArray(data.movements) || !Array.isArray(data.corrections)) {
                    throw new Error('El servicio no devolvió los movimientos y correcciones.');
                }
                return data;
            });
        },
        requestCorrection: function (payload) {
            var body = {
                original_movement_id: Number(payload.original_movement_id),
                corrected_amount: Number(payload.corrected_amount),
                reason: String(payload.reason || '').trim(),
                referencia_solicitud: payload.request_id
            };
            if (payload.session_id) body.session_id = Number(payload.session_id);
            return sendMovement('caja/movimientos/correcciones', body);
        },
        registerIncome: function (payload) {
            var body = Object.assign({}, payload, { referencia_solicitud: payload.request_id });
            delete body.request_id;
            return sendMovement('caja/ingresos', body);
        },
        registerExpense: function (payload) {
            var body = Object.assign({}, payload, { referencia_solicitud: payload.request_id });
            delete body.request_id;
            if (body.type !== 'omitted_withdrawal') {
                delete body.origin_turn_id;
                delete body.origin_session_id;
                delete body.confirm_previous_open;
                delete body.confirm_no_previous_record;
                delete body.confirm_different_seller;
                delete body.declared_occurred_in_open_origin_session;
                delete body.confirmed_reflected_in_opening;
            }
            return sendMovement('caja/egresos', body);
        }
    };
}(window));
