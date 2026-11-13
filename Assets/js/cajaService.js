(function (global) {
    'use strict';

    function responseData(response) {
        var body = response && response.data ? response.data : {};
        return body.data !== undefined ? body.data : body;
    }

    function normalizeShift(response) {
        if (!response || !response.ok || !response.data || !response.data.status) {
            var msg = (response && response.data && response.data.message)
                ? response.data.message : 'No se pudo obtener la información de turnos.';
            throw new Error(msg);
        }
        var data = responseData(response) || {};
        var available = Array.isArray(data.available_shifts) ? data.available_shifts : [];
        var scheduled = Array.isArray(data.scheduled_shifts) ? data.scheduled_shifts : [];
        return {
            status: data.status || (data.has_shift_today ? 'within_hours' : 'no_shift'),
            has_shift_today: Boolean(data.has_shift_today),
            shift: data.shift || null,
            scheduled_shifts: scheduled,
            available_shifts: available,
            recommended_shift_id: data.recommended_shift_id !== null && data.recommended_shift_id !== undefined
                ? Number(data.recommended_shift_id)
                : null,
            server_date: data.server_date || '',
            server_time: data.server_time || ''
        };
    }

    function normalizeOpeningState(response) {
        if (!response || !response.ok || !response.data || !response.data.status) {
            var msg = (response && response.data && response.data.message)
                ? response.data.message : 'No se pudo obtener el estado actual de caja.';
            throw new Error(msg);
        }
        var data = responseData(response) || {};
        return {
            is_open: Boolean(data.is_open),
            is_owner: Boolean(data.is_owner),
            session_id: data.session_id !== null && data.session_id !== undefined ? Number(data.session_id) : null,
            has_previous_close: Boolean(data.has_previous_close),
            reference_amount: data.reference_amount !== null && data.reference_amount !== undefined
                ? Number(data.reference_amount)
                : null,
            reference_date: data.reference_date || null,
            reference_turn: data.reference_turn || null,
            counted_amount: data.counted_amount !== null && data.counted_amount !== undefined
                ? Number(data.counted_amount)
                : null,
            occupied_by: data.occupied_by || null
        };
    }

    function normalizeOpeningResponse(response) {
        var body = response && response.data ? response.data : {};
        var data = body.data || {};
        var code = body.code || (body.status === true ? 'OPENED' : 'INTERNAL_ERROR');

        if (response.ok && body.status === true && (code === 'OPENED' || code === 'OPERATION_ALREADY_CONFIRMED')) {
            Api.confirmarSolicitud('caja-abrir');
            return {
                type: 'opened',
                code: code,
                idempotent_replay: Boolean(body.idempotent_replay || data.idempotent_replay),
                data: data,
                message: body.message || 'Apertura confirmada.'
            };
        }
        if (code === 'CASH_ALREADY_OPEN') {
            return { type: 'already_open', code: code, message: body.message || 'La caja física ya tiene una sesión abierta.' };
        }
        if (code === 'REQUEST_IN_PROGRESS') {
            return { type: 'in_progress', code: code, message: body.message || 'La apertura se encuentra en proceso.' };
        }
        return {
            type: 'error',
            status: response.status,
            code: code,
            message: body.message || 'No se pudo completar la apertura.',
            errors: body.errors || null
        };
    }

    global.CajaService = {
        getTurnoDeHoy: function () {
            return Api.request('caja/turno-hoy', { method: 'GET' }).then(function (res) {
                if (!res) throw new Error('Consulta de turno cancelada.');
                return normalizeShift(res);
            });
        },
        getEstadoCaja: function () {
            return Api.request('caja/estado', { method: 'GET' }).then(function (res) {
                if (!res) throw new Error('Consulta de estado de caja cancelada.');
                return normalizeOpeningState(res);
            });
        },
        requestOpening: async function (payload) {
            var base = {
                shift_id: Number(payload.shift_id),
                cash_counted: Number(payload.cash_counted)
            };
            var ref = await Api.referenciaSolicitud('caja-abrir', base);
            var response = await Api.request('caja/abrir', {
                method: 'POST',
                headers: { 'X-Request-Reference': ref },
                body: {
                    shift_id: base.shift_id,
                    cash_counted: base.cash_counted,
                    referencia_solicitud: ref
                }
            });
            if (!response) {
                return {
                    type: 'uncertain',
                    message: 'La petición fue interrumpida. Puedes reintentar para recuperar el resultado.'
                };
            }
            return normalizeOpeningResponse(response);
        }
    };
}(window));
