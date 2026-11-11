(function (global) {
    'use strict';

    function useDemo() {
        return Boolean(global.CajaDemoService
            && (!global.CAJA_WORKFLOWS_CONFIG || global.CAJA_WORKFLOWS_CONFIG.mockEnabled !== false));
    }

    function apiRequest(endpoint, options) {
        // TODO: Confirmar endpoints y envoltorios de respuesta con el backend.
        return Api.request(endpoint, options).then(function (response) {
            if (!response) throw new Error('El servicio de caja no devolvió una respuesta.');
            return response;
        });
    }

    function responseData(response) {
        var body = response && response.data ? response.data : {};
        return body.data || body;
    }

    function createRequestId() {
        if (!global.crypto || typeof global.crypto.randomUUID !== 'function') {
            throw new Error('Este navegador no permite generar un identificador seguro de solicitud.');
        }
        return global.crypto.randomUUID();
    }

    function normalizeState(response) {
        var data = responseData(response);
        return {
            is_open: Boolean(data.is_open || data.open),
            is_owner: data.is_owner !== false,
            session_id: data.session_id || data.sesion_id,
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

    global.CajaArqueoService = {
        createRequestId: createRequestId,
        getTurnoDeHoy: function (vendedorId) {
            if (useDemo()) return global.CajaDemoService.getTurnoDeHoy(vendedorId);
            // TODO: Confirmar endpoint y contrato del turno del vendedor.
            return apiRequest('caja/turno-hoy?vendedor_id=' + encodeURIComponent(vendedorId), { method: 'GET' })
                .then(responseData);
        },
        getArqueos: function (sesionId) {
            if (useDemo()) return global.CajaDemoService.getArqueos(sesionId);
            // TODO: Confirmar endpoint y contrato del historial de arqueos.
            return apiRequest('caja/arqueos?sesion_id=' + encodeURIComponent(sesionId), { method: 'GET' })
                .then(responseData);
        },
        getState: function () {
            if (useDemo()) {
                return global.CajaDemoService.getEstadoCaja().then(function (data) {
                    return {
                        is_open: data.is_open,
                        is_owner: data.is_owner,
                        session_id: data.session_id,
                        seller: data.seller,
                        turn: data.turn,
                        expected: data.expected,
                        opening_fund: data.opening_fund,
                        cash_sales: data.cash_sales,
                        income: data.income,
                        expenses: data.expenses,
                        card: data.card,
                        yape_plin: data.yape_plin,
                        version: data.version,
                        history: data.history,
                        closed_summary: data.closed_summary
                    };
                });
            }
            return apiRequest('caja/arqueo/estado', { method: 'GET' }).then(normalizeState);
        },
        saveArqueo: function (payload) {
            if (useDemo()) return global.CajaDemoService.guardarArqueo(payload);
            return apiRequest('caja/arqueo', {
                method: 'POST',
                headers: { 'X-Request-Reference': payload.request_id },
                body: payload
            }).then(function (response) {
                return normalizeResult(response, 'caja/arqueo');
            });
        },
        closeCash: function (payload) {
            if (useDemo()) {
                return payload.admin_username
                    ? global.CajaDemoService.solicitarAutorizacion(Object.assign({ operation: 'close' }, payload))
                    : global.CajaDemoService.confirmarCierre(payload);
            }
            return apiRequest('caja/cerrar', {
                method: 'POST',
                headers: { 'X-Request-Reference': payload.request_id },
                body: payload
            }).then(function (response) {
                return normalizeResult(response, 'caja/cerrar');
            });
        },
        getCierre: function (sesionId) {
            if (useDemo()) return global.CajaDemoService.getCierre(sesionId);
            // TODO: Confirmar endpoint para recuperar el cierre persistido tras un reintento.
            return apiRequest('caja/cierre?sesion_id=' + encodeURIComponent(sesionId), { method: 'GET' })
                .then(responseData);
        }
    };
}(window));
