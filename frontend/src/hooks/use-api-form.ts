import { ApiError, apiClient } from '@/lib/api-client';
import { useCallback, useEffect, useRef, useState } from 'react';

type FormErrors = Record<string, string>;

interface SubmitOptions {
    onSuccess?: () => void;
    onError?: (errors: FormErrors) => void;
    onFinish?: () => void;
    preserveScroll?: boolean;
}

/**
 * Hook de formularios de la SPA.
 *
 * Expone la API usada por las páginas (data, setData, errors, processing,
 * recentlySuccessful, reset, put/patch/post/delete) y envía vía `apiClient`
 * contra la API del backend.
 *
 * Los errores de validación 422 (FormRequest) se aplanan a
 * `Record<campo, primer-mensaje>`. Los errores no-422 (red, 500, 403) se
 * exponen en `errors.general`.
 */
export function useApiForm<T extends Record<string, unknown>>(initialData: T) {
    const initialRef = useRef<T>(initialData);
    const [data, setDataState] = useState<T>(initialData);
    const [errors, setErrors] = useState<FormErrors>({});
    const [processing, setProcessing] = useState(false);
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);
    const successTimeoutRef = useRef<number>(undefined);

    // Si el caller carga datos async y pasa una referencia nueva, reset()
    // debe volver a los últimos datos cargados, no a los del primer render.
    useEffect(() => {
        initialRef.current = initialData;
    }, [initialData]);

    // Evita el setState sobre componente desmontado del timeout de éxito.
    useEffect(() => () => window.clearTimeout(successTimeoutRef.current), []);

    const setData = useCallback((keyOrValues: keyof T | Partial<T>, value?: unknown) => {
        if (typeof keyOrValues === 'string') {
            setDataState((d) => ({ ...d, [keyOrValues]: value }));
        } else {
            setDataState((d) => ({ ...d, ...(keyOrValues as Partial<T>) }));
        }
    }, []);

    const reset = useCallback((...keys: (keyof T)[]) => {
        setDataState((d) => {
            if (keys.length === 0) {
                return initialRef.current;
            }
            const next = { ...d };
            for (const k of keys) {
                next[k] = initialRef.current[k];
            }
            return next;
        });
    }, []);

    const clearErrors = useCallback(() => setErrors({}), []);

    const submit = useCallback(
        async (method: 'post' | 'put' | 'patch' | 'delete', url: string, options?: SubmitOptions) => {
            setProcessing(true);
            setErrors({});
            try {
                if (method === 'delete') {
                    await apiClient.delete(url);
                } else {
                    await apiClient[method](url, data);
                }
                setRecentlySuccessful(true);
                window.clearTimeout(successTimeoutRef.current);
                successTimeoutRef.current = window.setTimeout(() => setRecentlySuccessful(false), 2000);
                options?.onSuccess?.();
            } catch (e) {
                if (e instanceof ApiError && e.errors) {
                    const flat: FormErrors = {};
                    for (const [field, messages] of Object.entries(e.errors)) {
                        flat[field] = messages[0] ?? '';
                    }
                    setErrors(flat);
                    options?.onError?.(flat);
                } else {
                    // Error no-422 (red, 500, 403): antes se tragaba en silencio y
                    // el form quedaba sin feedback. Se expone en `errors.general`.
                    const flat: FormErrors = {
                        general: e instanceof ApiError ? e.message : 'Error de conexión. Intenta de nuevo.',
                    };
                    setErrors(flat);
                    options?.onError?.(flat);
                }
            } finally {
                setProcessing(false);
                options?.onFinish?.();
            }
        },
        [data],
    );

    return {
        data,
        setData,
        errors,
        processing,
        recentlySuccessful,
        reset,
        clearErrors,
        post: (url: string, options?: SubmitOptions) => submit('post', url, options),
        put: (url: string, options?: SubmitOptions) => submit('put', url, options),
        patch: (url: string, options?: SubmitOptions) => submit('patch', url, options),
        delete: (url: string, options?: SubmitOptions) => submit('delete', url, options),
    };
}
