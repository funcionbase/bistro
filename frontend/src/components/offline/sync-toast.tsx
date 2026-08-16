/**
 * Toast efímero al completar un ciclo de sync.
 *
 * Escucha cambios en SyncState y dispara un toast breve cuando `lastSyncAt`
 * cambia y el `pendingCount` baja a 0.
 */
import { useToast } from '@/components/ui/toast';
import { subscribeSyncState, type SyncState } from '@/lib/offline/sync-engine';
import { useEffect, useRef } from 'react';

export function SyncToast(): null {
    const { showToast } = useToast();
    const lastSeen = useRef<string | null>(null);
    const lastPending = useRef<number>(0);

    useEffect(() => {
        const unsub = subscribeSyncState((s: SyncState) => {
            const justSynced = s.lastSyncAt && s.lastSyncAt !== lastSeen.current && s.pendingCount === 0 && lastPending.current > 0;
            if (justSynced) {
                showToast('success', `✓ Sincronizadas ${lastPending.current} operación${lastPending.current === 1 ? '' : 'es'}`);
            }
            lastSeen.current = s.lastSyncAt;
            lastPending.current = s.pendingCount;
        });
        return () => unsub();
    }, [showToast]);

    return null;
}

export default SyncToast;
