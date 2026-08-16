import { useCallback, useState } from 'react';

import { ApiError, api, newIdempotencyKey } from '@/lib/api';

type Method = 'post' | 'patch' | 'put' | 'delete';

type SubmitOptions<TResult> = {
    onSuccess?: (result: TResult) => void | Promise<void>;
    onError?: (error: ApiError) => void;
    onFinish?: () => void;
    /** Fields to blank once the request settles — passwords, always. */
    reset?: string[];
    /** Makes a retried POST harmless. See App\Http\Middleware\Idempotency. */
    idempotent?: boolean;
};

/**
 * A form bound to an API endpoint.
 *
 * Holds the values, the per-field errors the server sent back, and whether a
 * request is in flight — which is everything a form needs and nothing more.
 *
 * ── Why the errors come from the server ──────────────────────────────────────
 *
 * There is no client-side validation library here, and that is deliberate.
 * Every rule would have to exist twice — once in PHP where it is enforced and
 * once in TypeScript where it is displayed — and the two drift within a month.
 * The version that drifts is the one the user sees, so they are told a value is
 * fine and then told it is not.
 *
 * The server validates; the client displays what it said. HTML `required` and
 * `type="email"` still catch the obvious cases without a round trip, which is
 * the only duplication worth having because the browser maintains it for free.
 */
export function useApiForm<TValues extends Record<string, unknown>>(initial: TValues) {
    const [data, setData] = useState<TValues>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const set = useCallback(<K extends keyof TValues>(key: K, value: TValues[K]) => {
        setData((current) => ({ ...current, [key]: value }));

        // Clear the error on the field being corrected, not on every field. A
        // form that blanks all its complaints the moment you touch one of them
        // hides the others until you submit again.
        setErrors((current) => {
            if (!(key in current)) {
                return current;
            }

            const next = { ...current };
            delete next[key as string];

            return next;
        });
    }, []);

    const reset = useCallback((...fields: (keyof TValues)[]) => {
        setData((current) => {
            if (fields.length === 0) {
                return initial;
            }

            const next = { ...current };

            for (const field of fields) {
                next[field] = initial[field];
            }

            return next;
        });
    }, [initial]);

    const submit = useCallback(
        async <TResult>(method: Method, path: string, options: SubmitOptions<TResult> = {}) => {
            setProcessing(true);
            setErrors({});
            setMessage(null);

            try {
                const result = await api[method]<TResult>(path, data, {
                    idempotencyKey: options.idempotent ? newIdempotencyKey() : undefined,
                });

                await options.onSuccess?.(result);

                return result;
            } catch (caught) {
                if (caught instanceof ApiError) {
                    // Laravel returns every message for every field; a form
                    // shows one per field, so the first is taken and the rest
                    // are left for when that one is fixed.
                    setErrors(
                        Object.fromEntries(
                            Object.entries(caught.errors).map(([field, list]) => [field, list[0] ?? '']),
                        ),
                    );

                    // A message with no field attached — a throttle, a
                    // conflict — still has to be shown somewhere.
                    if (Object.keys(caught.errors).length === 0) {
                        setMessage(caught.message);
                    }

                    options.onError?.(caught);
                }

                return undefined;
            } finally {
                setProcessing(false);

                if (options.reset?.length) {
                    // Passwords are dropped whether the attempt succeeded or
                    // failed. Left in state they survive in memory, in React
                    // DevTools and in anything that serialises component state
                    // — for as long as the tab is open.
                    setData((current) => {
                        const next = { ...current };

                        for (const field of options.reset ?? []) {
                            next[field as keyof TValues] = initial[field as keyof TValues];
                        }

                        return next;
                    });
                }

                options.onFinish?.();
            }
        },
        [data, initial],
    );

    return {
        data,
        set,
        setData,
        errors,
        setErrors,
        message,
        processing,
        reset,
        submit,
        post: <TResult>(path: string, options?: SubmitOptions<TResult>) =>
            submit<TResult>('post', path, options),
        patch: <TResult>(path: string, options?: SubmitOptions<TResult>) =>
            submit<TResult>('patch', path, options),
    };
}
