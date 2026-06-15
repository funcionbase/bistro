import { apiFetch } from '@/lib/api';
import { useCallback, useEffect, useRef, useState } from 'react';

interface UseWidgetFetchOptions {
    url: string;
    interval: number;
    enabled?: boolean;
}

interface UseWidgetFetchResult<T> {
    data: T | null;
    loading: boolean;
    error: boolean;
    retry: () => void;
}

export function useWidgetFetch<T>({ url, interval, enabled = true }: UseWidgetFetchOptions): UseWidgetFetchResult<T> {
    const [data, setData] = useState<T | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const retryCount = useRef(0);
    const isMounted = useRef(true);
    const retryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const fetchData = useCallback(
        async (isRetry = false) => {
            if (!enabled) return;
            if (!isRetry) setError(false);
            try {
                const res = await apiFetch(url);
                if (!isMounted.current) return;
                if (res.ok) {
                    const json = await res.json();
                    setData(json.data);
                    setError(false);
                    retryCount.current = 0;
                } else {
                    throw new Error(`HTTP ${res.status}`);
                }
            } catch {
                if (!isMounted.current) return;
                retryCount.current++;
                if (retryCount.current < 3) {
                    const delay = Math.pow(2, retryCount.current - 1) * 1000;
                    if (retryTimerRef.current) {
                        clearTimeout(retryTimerRef.current);
                    }
                    retryTimerRef.current = setTimeout(() => {
                        retryTimerRef.current = null;
                        if (isMounted.current) void fetchData(true);
                    }, delay);
                } else {
                    setError(true);
                }
            } finally {
                if (isMounted.current) setLoading(false);
            }
        },
        [url, enabled],
    );

    const retry = useCallback(() => {
        retryCount.current = 0;
        setLoading(true);
        setError(false);
        void fetchData();
    }, [fetchData]);

    useEffect(() => {
        isMounted.current = true;
        return () => {
            isMounted.current = false;
            if (retryTimerRef.current) {
                clearTimeout(retryTimerRef.current);
                retryTimerRef.current = null;
            }
        };
    }, []);

    useEffect(() => {
        if (!enabled) return;
        void fetchData();
        const poll = setInterval(() => void fetchData(), interval);
        return () => clearInterval(poll);
    }, [fetchData, interval, enabled]);

    return { data, loading, error, retry };
}
