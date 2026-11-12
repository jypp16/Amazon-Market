(function (global) {
    'use strict';

    function useDemo() {
        return Boolean(global.CajaDemoService
            && (!global.CAJA_WORKFLOWS_CONFIG || global.CAJA_WORKFLOWS_CONFIG.mockEnabled !== false));
    }

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

    function responseData(response) {
        var body = response && response.data ? response.data : {};
        return body.data || body;
    }

    function normalizeShift(response) {
        var data = responseData(response);
        var shift = data.shift || data.turn || null;
        return {
            status: data.status || (data.within_hours ? 'within_hours' : 'outside_hours'),
            has_shift_today: data.has_shift_today !== undefined
                ? Boolean(data.has_shift_today) : Boolean(shift),
            shift: shift
        };
    }

    function normalizeOpeningState(response) {
        var data = responseData(response);
        return {
            is_open: Boolean(data.is_open || data.open),
            reference_amount: Number(data.reference_amount),
            reference_date: data.reference_date || data.previous_close_date || '',
            reference_turn: data.reference_turn || data.previous_close_turn || '',
            counted_amount: Number(data.counted_amount !== undefined
                ? data.counted_amount : data.opening_balance)
        };
    }

    function send(payload) {
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
        getTurnoDeHoy: function (vendedorId) {
            if (useDemo()) return global.CajaDemoService.getTurnoDeHoy(vendedorId);
            // TODO: Confirmar endpoint y contrato del turno del vendedor.
            return Api.request('caja/turno-hoy?vendedor_id=' + encodeURIComponent(vendedorId), { method: 'GET' })
                .then(normalizeShift);
        },
        getEstadoCaja: function (vendedorId) {
            if (useDemo()) return global.CajaDemoService.getEstadoCaja(vendedorId);
            // TODO: Confirmar endpoint y contrato de la sesión activa del vendedor.
            return Api.request('caja/estado?vendedor_id=' + encodeURIComponent(vendedorId), { method: 'GET' })
                .then(normalizeOpeningState);
        },
        requestOpening: function (payload) {
            if (useDemo()) return global.CajaDemoService.guardarApertura(payload);
            return send(payload);
        },
        authorizeAndOpen: function (payload) {
            if (useDemo()) return global.CajaDemoService.solicitarAutorizacion(
                Object.assign({ operation: 'open' }, payload)
            );
            return send(payload);
        }
    };
}(window));
