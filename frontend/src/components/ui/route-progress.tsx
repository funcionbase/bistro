import { useIsFetching } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useNavigation } from 'react-router-dom';

/**
 * Barra de progreso global de navegación.
 *
 * Barra fina superior que refleja:
 *  - Transiciones de ruta de React Router (`useNavigation().state`).
 *  - Cargas de datos iniciales de React Query (queries en vuelo **sin data
 *    todavía**) — el predicado `data === undefined` excluye los refetch en
 *    background (polling de dashboard / KDS / board) para que la barra no
 *    parpadee en cada tick de polling, solo en navegación real / primer load.
 *
 * Patrón "trickle": al activarse arranca y avanza asintóticamente hacia el
 * ~90% mientras dura la carga; al terminar completa a 100% y se desvanece.
 * Sin dependencias externas (NProgress) ni colores hardcoded: usa el token
 * `bg-primary` del DS.
 *
 * Se monta una sola vez en `spa-app-layout.tsx`. Es `position: fixed` y
 * `pointer-events-none`, así que su lugar en el árbol no afecta el layout.
 */
export function RouteProgress() {
    // Solo cargas iniciales (sin data): excluye refetch en background.
    const initialFetches = useIsFetching({ predicate: (query) => query.state.data === undefined });
    const navigation = useNavigation();
    const active = initialFetches > 0 || navigation.state !== 'idle';

    const [progress, setProgress] = useState(0);
    const [visible, setVisible] = useState(false);

    // Arranque + trickle mientras la carga está activa.
    useEffect(() => {
        if (!active) {
            return;
        }
        setVisible(true);
        setProgress((current) => (current < 8 ? 8 : current));
        const id = setInterval(() => {
            // Incremento decreciente: nunca alcanza el 90% solo (se reserva el
            // tramo final para el "completar" cuando la carga termina de verdad).
            setProgress((current) => (current >= 90 ? current : current + Math.max(0.5, (90 - current) * 0.08)));
        }, 200);
        return () => clearInterval(id);
    }, [active]);

    // Completar a 100% y desvanecer cuando la carga termina.
    useEffect(() => {
        if (active || !visible) {
            return;
        }
        setProgress(100);
        const id = setTimeout(() => {
            setVisible(false);
            setProgress(0);
        }, 250);
        return () => clearTimeout(id);
    }, [active, visible]);

    if (!visible) {
        return null;
    }

    return (
        <div aria-hidden="true" className="pointer-events-none fixed inset-x-0 top-0 z-[100] h-0.5">
            <div
                className="bg-primary h-full rounded-r-full shadow-[0_0_8px_var(--color-primary)] transition-[width,opacity] duration-200 ease-out"
                style={{ width: `${progress}%`, opacity: progress >= 100 ? 0 : 1 }}
            />
        </div>
    );
}
