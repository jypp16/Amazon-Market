const Api = (() => {
    const BASE = (typeof API_URL !== 'undefined') ? API_URL : '/AM4/AmazonMarket/api';
    const controllers = {};
    const intents = new Map();
    const activeIntents = new Map();

    // Conserva la misma intención incluso si se pierde la respuesta o se recarga
    // la pestaña. Solo se guarda su huella y referencia, nunca los datos enviados.
    async function referenciaSolicitud(scope, payload) {
        const bytes = new TextEncoder().encode(JSON.stringify(payload));
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        const hash = Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('');
        // Cada contenido conserva su referencia: A → B → A debe recuperar A,
        // aunque la respuesta de A se haya perdido y B haya sido rechazado.
        const scopeKey = 'am-solicitud:' + BASE + ':' + scope;
        const key = scopeKey + ':' + hash;
        let intent = intents.get(key);
        try { intent = JSON.parse(sessionStorage.getItem(key)) || intent; } catch (_) {}
        if (!intent || intent.hash !== hash) {
            intent = { hash, ref: 'am_' + crypto.randomUUID().replace(/-/g, '') };
            intents.set(key, intent);
            try { sessionStorage.setItem(key, JSON.stringify(intent)); } catch (_) {}
        }
        activeIntents.set(scopeKey, key);
        return intent.ref;
    }

    function confirmarSolicitud(scope) {
        const scopeKey = 'am-solicitud:' + BASE + ':' + scope;
        const key = activeIntents.get(scopeKey);
        if (key) {
            intents.delete(key);
            try { sessionStorage.removeItem(key); } catch (_) {}
            activeIntents.delete(scopeKey);
        }
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    async function request(endpoint, options = {}) {
        const key = endpoint + '_' + (options.method || 'GET');

        if (controllers[key]) controllers[key].abort();
        controllers[key] = new AbortController();
        const ctrl = controllers[key];

        const headers = {
            'Accept': 'application/json',
            ...options.headers
        };

        // Adjuntar token CSRF a peticiones que modifican estado
        const method = (options.method || 'GET').toUpperCase();
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            const token = getCsrfToken();
            if (token) headers['X-CSRF-Token'] = token;
        }

        const { headers: _omit, ...rest } = options;
        const config = {
            credentials: 'same-origin',
            headers: headers,
            signal: ctrl.signal,
            ...rest
        };

        if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(options.body);
        }

        try {
            const response = await fetch(BASE + '/' + endpoint, config);
            delete controllers[key];

            const contentType = response.headers.get('content-type');

            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                return { ok: response.ok, status: response.status, data: data };
            }

            throw new Error('El servidor respondió con un formato inesperado.');
        } catch (error) {
            delete controllers[key];
            if (error.name === 'AbortError') return null;
            throw error;
        }
    }

    async function download(endpoint, fallbackFilename = 'reporte.pdf') {
        const response = await fetch(BASE + '/' + endpoint, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/pdf, application/json' }
        });
        const contentType = response.headers.get('content-type') || '';

        if (!response.ok || !contentType.includes('application/pdf')) {
            let data = null;
            if (contentType.includes('application/json')) {
                try { data = await response.json(); } catch (_) {}
            }
            return {
                ok: false,
                status: response.status,
                data: data || { message: 'El servidor no devolvió un PDF válido.' }
            };
        }

        const disposition = response.headers.get('content-disposition') || '';
        const filenameMatch = disposition.match(/filename\s*=\s*(?:"([^"]+)"|([^;]+))/i);
        const filename = filenameMatch ? (filenameMatch[1] || filenameMatch[2]).trim() : fallbackFilename;
        const objectUrl = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 30000);

        return { ok: true, status: response.status, filename: filename };
    }

    async function downloadExcel(endpoint, fallbackFilename = 'reporte.xlsx') {
        const expectedType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        const response = await fetch(BASE + '/' + endpoint, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': expectedType + ', application/json' }
        });
        const contentType = (response.headers.get('content-type') || '').toLowerCase();

        if (!response.ok || !contentType.includes(expectedType)) {
            let data = null;
            if (contentType.includes('application/json')) {
                try { data = await response.json(); } catch (_) {}
            }
            return {
                ok: false,
                status: response.status,
                data: data || { message: 'El servidor no devolvió un Excel válido.' }
            };
        }

        const blob = await response.blob();
        const bytes = new Uint8Array(await blob.arrayBuffer());
        const contieneTexto = function(texto) {
            const esperado = Array.from(texto, caracter => caracter.charCodeAt(0));
            for (let i = 0; i <= bytes.length - esperado.length; i++) {
                let coincide = true;
                for (let j = 0; j < esperado.length; j++) {
                    if (bytes[i + j] !== esperado[j]) { coincide = false; break; }
                }
                if (coincide) return true;
            }
            return false;
        };
        const zipValido = bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4b
            && contieneTexto('[Content_Types].xml') && contieneTexto('xl/workbook.xml');
        if (!zipValido) {
            return { ok: false, status: response.status, data: { code: 'INVALID_XLSX', message: 'El archivo recibido no es un libro Excel válido.' } };
        }

        const disposition = response.headers.get('content-disposition') || '';
        const filenameMatch = disposition.match(/filename\s*=\s*(?:"([^"]+)"|([^;]+))/i);
        const rawFilename = filenameMatch ? (filenameMatch[1] || filenameMatch[2]).trim() : fallbackFilename;
        const filename = rawFilename.replace(/[\\/\r\n]/g, '_');
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 30000);

        return { ok: true, status: response.status, filename: filename };
    }

    return {
        referenciaSolicitud: referenciaSolicitud,
        confirmarSolicitud: confirmarSolicitud,
        request: request,
        get: function(endpoint) { return request(endpoint, { method: 'GET' }); },
        post: function(endpoint, body) { return request(endpoint, { method: 'POST', body: body }); },
        put: function(endpoint, body) { return request(endpoint, { method: 'PUT', body: body }); },
        delete: function(endpoint) { return request(endpoint, { method: 'DELETE' }); },
        download: download,
        downloadExcel: downloadExcel,
        cancelar: function() { Object.values(controllers).forEach(function(c) { c.abort(); }); }
    };
})();

function debounce(fn, delay) {
    var timer = null;
    return function () {
        var args = arguments;
        var ctx = this;
        clearTimeout(timer);
        timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
    };
}
