(function (global) {
    'use strict';

    var config = global.CAJA_SERVICE_CONFIG || {};
    var mockEnabled = config.mockEnabled === true;
    var mockScenario = config.mockScenario || 'authorization_required';
    var mockReferenceAmount = Number(config.referenceAmount);
    var allowedScenarios = [
        'success',
        'authorization_required',
        'invalid_credentials',
        'motive_required',
        'already_open',
        'server_error'
    ];

    function normalizeResponse(response) {
        var body = response && response.data ? response.data : {};
        var data = body.data || {};
        var code = body.code || data.code || (body.status === true ? 'OPENED' : 'INTERNAL_ERROR');

        if (code === 'OPENED') {
            return { type: 'opened', data: data };
        }
        if (code === 'AUTHORIZATION_REQUIRED') {
            return {
                type: 'authorization_required',
                schedule: data.schedule || {
                    start: data.shift_start,
                    end: data.shift_end
                }
            };
        }
        if (code === 'CASH_ALREADY_OPEN') {
            return { type: 'already_open' };
        }
        if (code === 'INVALID_ADMIN_CREDENTIALS' || code === 'ADMIN_ROLE_REQUIRED') {
            return { type: 'authorization_denied' };
        }
        if (code === 'MOTIVE_REQUIRED') {
            return { type: 'motive_required' };
        }

        return { type: 'error' };
    }

    function mockResponse(payload) {
        if (!allowedScenarios.includes(mockScenario)) {
            return Promise.reject(new Error('CAJA_MOCK_SCENARIO no es un escenario válido.'));
        }

        var hasAuthorization = Object.prototype.hasOwnProperty.call(payload, 'admin_username');
        var now = new Date().toISOString();
        var opened = {
            type: 'opened',
            data: {
                opening_balance: Number(payload.cash_counted),
                opening_difference: Number(payload.cash_counted) - mockReferenceAmount,
                opened_at: now
            }
        };
        var result;

        if (mockScenario === 'success') result = opened;
        else if (mockScenario === 'already_open') result = { type: 'already_open' };
        else if (mockScenario === 'server_error' && hasAuthorization) result = { type: 'error' };
        else if (mockScenario === 'invalid_credentials' && hasAuthorization) result = { type: 'authorization_denied' };
        else if (mockScenario === 'motive_required' && hasAuthorization) result = { type: 'motive_required' };
        else if (hasAuthorization) result = opened;
        else result = {
            type: 'authorization_required',
            schedule: { start: '08:00', end: '14:00' }
        };

        return new Promise(function (resolve) {
            global.setTimeout(function () { resolve(result); }, 180);
        });
    }

    function send(payload) {
        if (mockEnabled) return mockResponse(payload);

        // TODO: Confirmar endpoint, contrato y autorización de administrador sin cambiar la sesión del vendedor.
        return Api.request('caja/abrir', {
            method: 'POST',
            headers: { 'X-Request-Reference': payload.request_id },
            body: payload
        }).then(function (response) {
            if (!response) throw new Error('No se recibió una respuesta del servicio de caja.');
            return normalizeResponse(response);
        });
    }

    function createRequestId() {
        if (!global.crypto || typeof global.crypto.randomUUID !== 'function') {
            throw new Error('Este navegador no permite generar un identificador seguro de solicitud.');
        }
        return global.crypto.randomUUID();
    }

    global.CajaService = {
        createRequestId: createRequestId,
        requestOpening: function (payload) {
            return send(payload);
        },
        authorizeAndOpen: function (payload) {
            return send(payload);
        }
    };
}(window));
