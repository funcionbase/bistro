import { apiFetch } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useState } from 'react';

interface AvailabilityToggleProps {
    menuId: string;
    categoryId: string;
    itemId: string;
    available: boolean;
    onToggle?: (available: boolean) => void;
}

export default function AvailabilityToggle({ menuId, categoryId, itemId, available, onToggle }: AvailabilityToggleProps) {
    const [optimisticAvailable, setOptimisticAvailable] = useState(available);
    const [loading, setLoading] = useState(false);

    async function handleToggle() {
        const next = !optimisticAvailable;
        setOptimisticAvailable(next);
        setLoading(true);

        try {
            const res = await apiFetch(`/api/v1/menus/${menuId}/categories/${categoryId}/items/${itemId}/availability`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ available: next }),
            });

            if (!res.ok) {
                setOptimisticAvailable(!next);
            } else {
                onToggle?.(next);
            }
        } catch {
            setOptimisticAvailable(!next);
        } finally {
            setLoading(false);
        }
    }

    return (
        <button
            type="button"
            role="switch"
            aria-checked={optimisticAvailable}
            disabled={loading}
            onClick={handleToggle}
            className={cn(
                'focus:ring-ring relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus:ring-2 focus:ring-offset-2 focus:outline-none disabled:opacity-60',
                optimisticAvailable ? 'bg-[color:var(--color-status-safe)]' : 'bg-muted-foreground/40',
            )}
        >
            <span
                className={cn(
                    'bg-background inline-block h-3.5 w-3.5 transform rounded-full shadow-sm transition-transform',
                    optimisticAvailable ? 'translate-x-[18px]' : 'translate-x-1',
                )}
            />
        </button>
    );
}
