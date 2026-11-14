(function (global) {
    'use strict';

    var config = global.CAJA_CONSULTA_CONFIG || {};
    var pageSize = Math.max(1, Number(config.pageSize) || 10);

    var typeLabels = {
        cash_sale: 'Venta en efectivo',
        admin_injection: 'Inyección',
        manual_income: 'Ingreso manual',
        admin_withdrawal: 'Retiro',
        cash_out: 'Salida de caja',
        omitted_withdrawal: 'Retiro omitido'
    };

    function apiRequest(endpoint, options) {
        return Api.request(endpoint, options).then(function (response) {
            if (!response) throw new Error('La solicitud de consulta fue cancelada.');
            if (response.status === 403 || (response.data && ['FORBIDDEN', 'FORBIDDEN_ADMIN_ONLY', 'FORBIDDEN_SELLER_ONLY', 'SESSION_NOT_OWNED'].includes(response.data.code))) {
                return { type: 'access_denied' };
            }
            if (response.data && response.data.code === 'NO_OPEN_SESSION') {
                return { type: 'no_session' };
            }
            if (response.status === 404 || (response.data && ['NO_SESSION', 'NO_OPENINGS', 'CASH_SESSION_NOT_FOUND'].includes(response.data.code))) {
                return { type: 'empty' };
            }
            if (!response.ok || !response.data || response.data.status !== true) {
                return { type: 'error', message: (response.data && response.data.message) || 'No se pudo completar la consulta.' };
            }
            return response.data.data !== undefined ? response.data.data : response.data;
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
            authorizations: Array.isArray(result.authorizations) ? result.authorizations : [],
            movements: result.movements,
            pagination: result.pagination || {}
        };
    }

    global.CajaConsultaService = {
        typeLabels: typeLabels,
        listSessions: function (filters) {
            var query = 'date=' + encodeURIComponent(filters.date)
                + '&seller_id=' + encodeURIComponent(filters.seller_id);
            return apiRequest('caja/consulta/sesiones?' + query, { method: 'GET' }).then(function (data) {
                if (data.type === 'access_denied' || data.type === 'error') return data;
                if (data.type === 'empty') return [];
                if (!Array.isArray(data)) throw new Error('El servicio no devolvió la lista de sesiones.');
                return data;
            });
        },
        listSellers: function () {
            return apiRequest('caja/consulta/vendedores', { method: 'GET' }).then(function (data) {
                if (data.type) throw new Error(data.message || data.type);
                if (!Array.isArray(data)) throw new Error('El servicio no devolvió la lista de vendedores.');
                return data;
            });
        },
        query: function (filters) {
            var query = [
                'session_id=' + encodeURIComponent(filters.session_id),
                'page=' + encodeURIComponent(filters.page),
                'page_size=' + encodeURIComponent(pageSize)
            ].join('&');
            return apiRequest('caja/consulta?' + query, { method: 'GET' }).then(normalizeResult);
        },
        getActiveSession: function (filters) {
            filters = filters || {};
            var query = [
                'page=' + encodeURIComponent(Number(filters.page) || 1),
                'page_size=' + encodeURIComponent(pageSize)
            ].join('&');
            return apiRequest('caja/consulta/activa?' + query, { method: 'GET' }).then(normalizeResult);
        }
    };
}(window));
