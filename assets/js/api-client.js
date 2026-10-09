/**
 * Lorella Speciality Cafe - API Client Helper
 * Reusable Vanilla JavaScript fetch wrapper with automatic CSRF token management.
 */

const ApiClient = (() => {
    let csrfToken = null;

    /**
     * Fetch fresh CSRF token from server if missing.
     */
    async function ensureCsrfToken() {
        if (csrfToken) {
            return csrfToken;
        }

        try {
            const response = await fetch('/api/auth/csrf');
            const data = await response.json();
            if (data && data.success && data.data && data.data.csrf_token) {
                csrfToken = data.data.csrf_token;
            }
        } catch (error) {
            console.warn('Failed to retrieve initial CSRF token:', error);
        }

        return csrfToken;
    }

    /**
     * Generic HTTP request execution.
     */
    async function request(endpoint, options = {}) {
        const url = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
        const headers = {
            'Accept': 'application/json',
            ...(options.headers || {})
        };

        const method = (options.method || 'GET').toUpperCase();

        // Attach CSRF token on mutation methods
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
            const token = await ensureCsrfToken();
            if (token) {
                headers['X-CSRF-TOKEN'] = token;
            }
        }

        if (options.body && !(options.body instanceof FormData) && typeof options.body === 'object') {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }

        try {
            const response = await fetch(url, {
                ...options,
                method,
                headers
            });

            const data = await response.json().catch(() => ({
                success: false,
                message: `Server returned HTTP status ${response.status}`
            }));

            if (!response.ok) {
                return Promise.reject({
                    status: response.status,
                    ...data
                });
            }

            return data;
        } catch (err) {
            return Promise.reject(err);
        }
    }

    return {
        get: (endpoint, headers = {}) => request(endpoint, { method: 'GET', headers }),
        post: (endpoint, body = {}, headers = {}) => request(endpoint, { method: 'POST', body, headers }),
        put: (endpoint, body = {}, headers = {}) => request(endpoint, { method: 'PUT', body, headers }),
        delete: (endpoint, headers = {}) => request(endpoint, { method: 'DELETE', headers }),
        setCsrfToken: (token) => { csrfToken = token; },
    };
})();

window.ApiClient = ApiClient;
