/**
 * The only way this application talks to the server.
 *
 * ── fetch, not axios ─────────────────────────────────────────────────────────
 *
 * Nothing here needs a library. `fetch` is in every browser this tool supports,
 * and the fifteen kilobytes axios costs sit on the critical path of the first
 * paint — for interceptors we would write once and never change.
 *
 * ── Authentication: there is none to do ──────────────────────────────────────
 *
 * The API is same-origin behind the session cookie, so the browser attaches it
 * automatically. No token is ever handed to JavaScript, which means no token
 * for an XSS to steal and nothing to refresh. `credentials: 'same-origin'` is
 * explicit rather than assumed because the default has differed between
 * browsers and versions.
 *
 * The CSRF token is read from the cookie Laravel sets on every response rather
 * than from a meta tag baked into the document. A token in the HTML goes stale
 * the moment the session regenerates — at sign-in, at the 2FA challenge — and a
 * stale token is a 419 on the next write with no obvious cause.
 */

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
        public readonly payload: unknown = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** The first message for a field, which is all a form ever shows. */
    fieldError(field: string): string | undefined {
        return this.errors[field]?.[0];
    }

    /** Validation. The user can fix this by typing something else. */
    get isValidation(): boolean {
        return this.status === 422;
    }

    /** Signed out, or the session expired while the tab sat open. */
    get isUnauthenticated(): boolean {
        return this.status === 401 || this.status === 419;
    }

    /** Needs a password typed in the last few minutes. */
    get needsPasswordConfirmation(): boolean {
        return this.status === 423;
    }

    get isForbidden(): boolean {
        return this.status === 403;
    }

    /** Retried too fast, or a subscriber's budget is spent. */
    get isThrottled(): boolean {
        return this.status === 429;
    }

    /** Our end. Worth retrying; not worth explaining to the user in detail. */
    get isServer(): boolean {
        return this.status >= 500;
    }
}

/**
 * What to do when the server says the session is gone.
 *
 * Set once by the session provider. A module-level hook rather than an import
 * of the router, because this file must stay usable from anywhere — including
 * from code that runs before the router exists.
 */
let onUnauthenticated: (() => void) | null = null;

export function setUnauthenticatedHandler(handler: () => void): void {
    onUnauthenticated = handler;
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    if (match?.[1]) {
        return decodeURIComponent(match[1]);
    }

    // A first write made before Laravel has set the cookie. The meta tag is
    // correct as at the moment the document was rendered, which is enough.
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

type RequestOptions = {
    signal?: AbortSignal;
    params?: Record<string, string | number | boolean | null | undefined>;
    /**
     * Makes a repeated POST harmless.
     *
     * The client generates one key per *intent* and reuses it across every
     * retry of that intent. See App\Http\Middleware\Idempotency — this is what
     * stops a flaky connection turning one order into two.
     */
    idempotencyKey?: string;
};

const BASE = '/api/v1';

/**
 * The business every GET is answered for, written into the URL.
 *
 * ── Why this is here and not at the call sites ───────────────────────────────
 *
 * The server reads the open business from the session, so this parameter tells
 * it nothing it does not already know and is deliberately ignored there. It is
 * for the browser's cache, which knows only the URL.
 *
 * Responses are returned with `Cache-Control: private, max-age=15`, and without
 * this the address of "this month's revenue" is the same string whichever books
 * are open. Switch business and the refetch is a request the browser believes it
 * has already answered — so it serves the previous business's money under the
 * new business's name, without the server ever hearing about it, and the figures
 * only correct themselves once the cache expires. Which is precisely the "it
 * changes after a reload" symptom.
 *
 * Set from the session on every boot and switch — see SessionProvider. Kept in a
 * module variable rather than passed through each call because the failure mode
 * of forgetting it once is showing somebody another company's accounts, and that
 * is not a thing to leave to whoever writes the next page.
 */
let scopedBusiness: string | null = null;

export function setApiBusinessScope(businessId: string | null): void {
    scopedBusiness = businessId;
}

/**
 * The money scope every GET is answered under, written into the URL.
 *
 * Exactly the same device as `scopedBusiness` above and for exactly the same
 * reason, applied to the other thing that silently changes what a figure
 * means: the currency it is reported in, and the rates it was converted
 * through. Switching currency in settings leaves every dashboard URL
 * identical, so without this the browser answers the refetch out of its own
 * fifteen-second cache — in the currency just left — and the screen only
 * corrects itself on a reload.
 *
 * Ignored by the server, which reads the workspace's currency from the
 * session. It is here for the caches, which know only the URL.
 */
let scopedMoney: string | null = null;

export function setApiMoneyScope(scope: string | null): void {
    scopedMoney = scope;
}

async function request<T>(
    method: string,
    path: string,
    body?: unknown,
    options: RequestOptions = {},
): Promise<T> {
    const url = new URL(
        path.startsWith('/') ? `${BASE}${path}` : `${BASE}/${path}`,
        window.location.origin,
    );

    for (const [key, value] of Object.entries(options.params ?? {})) {
        if (value !== null && value !== undefined && value !== '') {
            url.searchParams.set(key, String(value));
        }
    }

    // Reads only. A POST is never served from cache, and adding it there would
    // put a business id in the audit trail of every write as though it had been
    // meaningful input.
    if (scopedBusiness !== null && (method === 'GET' || method === 'HEAD')) {
        url.searchParams.set('_business', scopedBusiness);
    }

    if (scopedMoney !== null && (method === 'GET' || method === 'HEAD')) {
        url.searchParams.set('_cur', scopedMoney);
    }

    const isWrite = method !== 'GET' && method !== 'HEAD';
    const isFormData = body instanceof FormData;

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (isWrite) {
        // Don't set Content-Type for FormData - let the browser set it with boundary
        if (!isFormData) {
            headers['Content-Type'] = 'application/json';
        }
        headers['X-XSRF-TOKEN'] = csrfToken();
    }

    if (options.idempotencyKey) {
        headers['Idempotency-Key'] = options.idempotencyKey;
    }

    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        signal: options.signal,
        headers,
        body: isWrite && body !== undefined 
            ? (isFormData ? body : JSON.stringify(body))
            : undefined,
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const text = await response.text();
    const payload: unknown = text ? safeParse(text) : null;

    if (!response.ok) {
        const shaped = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };

        const error = new ApiError(
            response.status,
            shaped.message ?? messageForStatus(response.status),
            shaped.errors ?? {},
            payload,
        );

        // Handled centrally: every screen would otherwise need the same
        // "have I been signed out" branch, and the one that forgets it shows
        // the user a confusing error instead of a sign-in form.
        if (error.isUnauthenticated) {
            onUnauthenticated?.();
        }

        throw error;
    }

    return payload as T;
}

function safeParse(text: string): unknown {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

/** A sentence for the cases where the server did not send one. */
function messageForStatus(status: number): string {
    if (status === 429) {
        return 'That is happening a bit too fast. Give it a moment.';
    }

    if (status >= 500) {
        return 'Something went wrong at our end.';
    }

    if (status === 0) {
        return 'No connection.';
    }

    return `Request failed (${status})`;
}

export const api = {
    get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, undefined, options),
    post: <T>(path: string, body?: unknown, options?: RequestOptions) =>
        request<T>('POST', path, body, options),
    patch: <T>(path: string, body?: unknown, options?: RequestOptions) =>
        request<T>('PATCH', path, body, options),
    put: <T>(path: string, body?: unknown, options?: RequestOptions) =>
        request<T>('PUT', path, body, options),
    delete: <T>(path: string, body?: unknown, options?: RequestOptions) =>
        request<T>('DELETE', path, body, options),
};

/** A fresh idempotency key, one per intent. */
export function newIdempotencyKey(): string {
    return crypto.randomUUID();
}

/** The envelope every list endpoint returns. See App\Http\Api\ApiResponse. */
export type Paginated<T> = {
    data: T[];
    meta: {
        per_page: number;
        next_cursor: string | null;
        prev_cursor: string | null;
        has_more: boolean;
    };
};

export type Envelope<T> = {
    data: T;
    meta?: Record<string, unknown>;
};
