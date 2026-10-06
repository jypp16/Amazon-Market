const Api = (() => {
    const BASE = (typeof API_URL !== 'undefined') ? API_URL : '/AM4/AmazonMarket/api';
    const controllers = {};

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

    return {
        request: request,
        get: function(endpoint) { return request(endpoint, { method: 'GET' }); },
        post: function(endpoint, body) { return request(endpoint, { method: 'POST', body: body }); },
        put: function(endpoint, body) { return request(endpoint, { method: 'PUT', body: body }); },
        delete: function(endpoint) { return request(endpoint, { method: 'DELETE' }); },
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
