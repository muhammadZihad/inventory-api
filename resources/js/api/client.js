const BASE_URL = '/api/v1';
const TOKEN_KEY = 'api_token';

/**
 * A failed API call, carrying the server's error envelope.
 *
 * The backend returns the same shape for every failure
 * ({success, message, errors}), so one error class can expose the message for
 * a toast and the field errors for inline form display.
 */
export class ApiError extends Error {
    constructor(status, message, errors = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }

    /** Validation failures, keyed by field name. */
    get fieldErrors() {
        if (!this.errors || Array.isArray(this.errors)) {
            return {};
        }

        return Object.fromEntries(
            Object.entries(this.errors).filter(([, value]) => Array.isArray(value) && typeof value[0] === 'string'),
        );
    }

    /** Per-product shortfalls returned with a 409 insufficient-stock response. */
    get stockShortfalls() {
        return this.errors?.items && Array.isArray(this.errors.items) ? this.errors.items : [];
    }
}

/** Read the stored bearer token. */
export function getToken() {
    return localStorage.getItem(TOKEN_KEY) || '';
}

/** Persist or clear the bearer token. */
export function setToken(token) {
    if (token) {
        localStorage.setItem(TOKEN_KEY, token);
    } else {
        localStorage.removeItem(TOKEN_KEY);
    }
}

/**
 * Generate an idempotency key for a retry-safe write.
 *
 * The key is created once per user intent, not per attempt, so that a retry of
 * the same submission replays the original response instead of duplicating it.
 */
export function newIdempotencyKey() {
    if (globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID();
    }

    return `key-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/** Drop empty filter values so they are never sent as blank query parameters. */
export function cleanParams(params = {}) {
    return Object.fromEntries(
        Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    );
}

/** Callbacks invoked when the server rejects the stored token. */
const unauthorizedHandlers = new Set();

/** Register a listener for 401 responses, e.g. to sign the user out. */
export function onUnauthorized(handler) {
    unauthorizedHandlers.add(handler);

    return () => unauthorizedHandlers.delete(handler);
}

/**
 * Perform an API request and unwrap the response envelope.
 *
 * Returns the whole envelope rather than just `data`, because list endpoints
 * carry pagination in a sibling `meta` key.
 */
export async function request(path, { method = 'GET', body = null, params = null, headers = {} } = {}) {
    const url = new URL(`${BASE_URL}${path}`, globalThis.location.origin);

    if (params) {
        Object.entries(cleanParams(params)).forEach(([key, value]) => url.searchParams.set(key, value));
    }

    const token = getToken();
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            ...headers,
        },
        body: body ? JSON.stringify(body) : null,
    });

    if (response.status === 204) {
        return { success: true, data: null };
    }

    let payload = null;

    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    if (!response.ok) {
        if (response.status === 401) {
            unauthorizedHandlers.forEach((handler) => handler());
        }

        throw new ApiError(
            response.status,
            payload?.message || `Request failed with status ${response.status}.`,
            payload?.errors ?? null,
        );
    }

    return {
        ...payload,
        // Surfaces whether a write was executed or replayed from a stored response.
        replayed: response.headers.get('Idempotent-Replay') === 'true',
    };
}

/** Thin verb helpers over `request`. */
export const api = {
    get: (path, params) => request(path, { params }),
    post: (path, body, headers) => request(path, { method: 'POST', body, headers }),
    put: (path, body) => request(path, { method: 'PUT', body }),
    patch: (path, body) => request(path, { method: 'PATCH', body }),
    delete: (path) => request(path, { method: 'DELETE' }),
};
