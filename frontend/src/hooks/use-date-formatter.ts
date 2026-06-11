export function useDateFormatter() {
    const formatDate = (isoDate: string): string => {
        const date = new Date(isoDate);
        const now = new Date();

        const diffMs = now.getTime() - date.getTime();
        const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
        const diffDays = Math.floor(diffHours / 24);

        if (diffHours < 1) return 'Hace poco';
        if (diffHours < 24) return `Hace ${diffHours}h`;
        if (diffDays === 1) return 'Hace 1 día';
        if (diffDays < 7) return `Hace ${diffDays} días`;

        return date.toLocaleDateString('es-CO', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            timeZone: 'America/Bogota',
        });
    };

    return formatDate;
}
