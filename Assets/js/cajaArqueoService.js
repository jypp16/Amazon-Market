(function (global) {
    'use strict';

    var LAST_CLOSE_STORAGE_KEY = 'am-ultimo-cierre-ref';

    function apiRequest(endpoint, options) {
        return Api.request(endpoint, options).then(function (response) {
            if (!response) throw new Error('La petición fue interrumpida.');
            return response;
        });
    }

    function responseData(response) {
        var body = response && response.data ? response.data : {};
        return body.data !== undefined ? body.data : body;
    }

    function saveLastCloseRef(sessionId, ref) {
        if (!sessionId || !ref) return;
        try {
            global.sessionStorage.setItem(LAST_CLOSE_STORAGE_KEY, JSON.stringify({
                session_id: Number(sessionId),
                reference: String(ref)
            }));
        } catch (_) {}
    }

    function getLastCloseRef() {
        try {
            var raw = global.sessionStorage.getItem(LAST_CLOSE_STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (_) {
            return null;
        }
    }

    function normalizeState(response) {
        var body = response && response.data ? response.data : {};
        if (body.code === 'NO_OPEN_SESSION' || (!response.ok && response.status === 409)) {
            return {
                is_open: false,
                is_owner: false,
                session_id: null,
                seller: '',
                turn: '',
                expected: 0,
                opening_fund: 0,
                cash_sales: 0,
                income: 0,
                expenses: 0,
                card: 0,
                yape_plin: 0,
                version: 1,
                history: [],
                schedule: null,
                closed_summary: null
            };
        }
        if (!response.ok || !body.status) {
            throw new Error(body.message || 'No se pudo cargar el estado de arqueo.');
        }
        var data = body.data || {};
        return {
            is_open: Boolean(data.is_open),
            is_owner: data.is_owner !== false,
            session_id: data.session_id !== null && data.session_id !== undefined ? Number(data.session_id) : null,
            seller: data.seller || '',
            turn: data.turn || '',
            expected: Number(data.expected || 0),
            opening_fund: Number(data.opening_fund || 0),
            cash_sales: Number(data.cash_sales || 0),
            income: Number(data.income || 0),
            expenses: Number(data.expenses || 0),
            card: Number(data.card || 0),
            yape_plin: Number(data.yape_plin || 0),
            version: Number(data.version || 1),
            history: Array.isArray(data.history) ? data.history : [],
            schedule: data.schedule || null,
            closed_summary: data.closed_summary || null
        };
    }

    function normalizeResult(response, endpoint) {
        var body = response && response.data ? response.data : {};
        var data = (body.data && typeof body.data === 'object') ? body.data : {};
        var codes = {
            ARQUEO_SAVED: 'saved',
            OPERATION_ALREADY_CONFIRMED: endpoint === 'caja/arqueo' ? 'saved' : 'closed',
            CASH_CHANGED: 'caja_changed',
            CASH_SESSION_CLOSED: 'session_closed',
            NO_OPEN_SESSION: 'session_closed',
            SESSION_NOT_OWNED: 'session_not_owned',
            ARQUEO_MISSING: 'arqueo_stale',
            ARQUEO_STALE: 'arqueo_stale',
            AUTHORIZATION_REQUIRED: 'authorization_required',
            CLOSE_CONFIRMED: 'closed',
            INVALID_ADMIN_CREDENTIALS: 'authorization_denied',
            INVALID_ADMIN_EMAIL: 'invalid_admin_email',
            ADMIN_ROLE_REQUIRED: 'authorization_denied',
            SELF_AUTHORIZATION_NOT_ALLOWED: 'authorization_denied',
            MOTIVE_REQUIRED: 'motive_required',
            OBSERVATION_REQUIRED: 'observation_required',
            INVALID_COUNT: 'count_invalid',
            REQUEST_IN_PROGRESS: 'in_progress'
        };
        var code = body.code || data.code || '';
        var type = code === 'SUCCESS'
            ? (endpoint === 'caja/arqueo' ? 'saved' : 'closed')
            : (codes[code] || (response.ok && body.status === true
                ? (endpoint === 'caja/arqueo' ? 'saved' : 'closed')
                : 'error'));

        return Object.assign({
            type: type,
            code: code,
            message: body.message || ''
        }, data);
    }

    function createRequestId() {
        if (!global.crypto || typeof global.crypto.randomUUID !== 'function') {
            throw new Error('Este navegador no permite generar un identificador seguro de solicitud.');
        }
        return global.crypto.randomUUID();
    }

    global.CajaArqueoService = {
        createRequestId: createRequestId,
        getLastCloseRef: getLastCloseRef,
        getTurnoDeHoy: function () {
            return apiRequest('caja/turno-hoy', { method: 'GET' }).then(function (res) {
                return responseData(res);
            });
        },
        getArqueos: function (sesionId) {
            return apiRequest('caja/arqueos?session_id=' + encodeURIComponent(sesionId), { method: 'GET' })
                .then(function (res) { return responseData(res); });
        },
        getState: function () {
            return apiRequest('caja/arqueo/estado', { method: 'GET' }).then(normalizeState);
        },
        saveArqueo: async function (payload) {
            var base = {
                counted: Number(payload.counted),
                version: Number(payload.version),
                observation: payload.observation ? String(payload.observation).trim() : null
            };
            var ref = await Api.referenciaSolicitud('caja-arqueo', base);
            var response = await apiRequest('caja/arqueo', {
                method: 'POST',
                headers: { 'X-Request-Reference': ref },
                body: {
                    counted: base.counted,
                    version: base.version,
                    observation: base.observation,
                    referencia_solicitud: ref
                }
            });
            var result = normalizeResult(response, 'caja/arqueo');
            if (result.type === 'saved') {
                Api.confirmarSolicitud('caja-arqueo');
            }
            return result;
        },
        closeCash: async function (payload) {
            // Identidad funcional del cierre SIN secretos (nunca admin_password ni admin_email)
            var functionalIdentity = {
                session_id: Number(payload.session_id || 0),
                arqueo_id: Number(payload.arqueo_id),
                exception_reason: payload.exception_reason ? String(payload.exception_reason).trim() : ''
            };
            var ref = await Api.referenciaSolicitud('caja-cerrar', functionalIdentity);
            saveLastCloseRef(functionalIdentity.session_id, ref);

            var body = {
                arqueo_id: Number(payload.arqueo_id),
                referencia_solicitud: ref
            };
            if (payload.admin_email) body.admin_email = String(payload.admin_email).trim();
            if (payload.admin_password) body.admin_password = String(payload.admin_password);
            if (payload.exception_reason) body.exception_reason = String(payload.exception_reason).trim();

            var response = await apiRequest('caja/cerrar', {
                method: 'POST',
                headers: { 'X-Request-Reference': ref },
                body: body
            });
            var result = normalizeResult(response, 'caja/cerrar');
            if (result.type === 'closed') {
                Api.confirmarSolicitud('caja-cerrar');
            }
            return { result: result, reference: ref };
        },
        getCierre: function (sesionId, referenciaSolicitud) {
            if (!sesionId || !referenciaSolicitud) {
                return Promise.resolve({ pending: true, data: null });
            }
            var q = '?session_id=' + encodeURIComponent(sesionId)
                + '&referencia_solicitud=' + encodeURIComponent(referenciaSolicitud);
            return apiRequest('caja/cierre' + q, { method: 'GET' }).then(function (res) {
                var body = res && res.data ? res.data : {};
                if (!res.ok || !body.status) {
                    throw new Error(body.message || 'No se pudo consultar el resultado del cierre.');
                }
                if (body.data === null) {
                    return { pending: true, data: null };
                }
                return { pending: false, data: body.data };
            });
        }
    };
}(window));
