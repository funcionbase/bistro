interface CourierAvatarProps {
    name: string;
    image?: string;
    size?: 'sm' | 'md' | 'lg';
}

const SIZES = {
    sm: 'h-6 w-6 text-xs',
    md: 'h-8 w-8 text-sm',
    lg: 'h-10 w-10 text-base',
};

// Paleta categórica del DS (5 tonos) — el color identifica una persona, no
// comunica estado. Ver FRONTEND_UI_GUIDELINES.md §21 (rotación de avatar).
function colorFromName(name: string): string {
    const colors = [
        'bg-[color:var(--color-category-violet)]',
        'bg-[color:var(--color-category-cyan)]',
        'bg-[color:var(--color-category-pink)]',
        'bg-[color:var(--color-category-amber)]',
        'bg-[color:var(--color-category-green)]',
    ];
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    return colors[Math.abs(hash) % colors.length];
}

export function CourierAvatar({ name, image, size = 'md' }: CourierAvatarProps) {
    const initial = name.trim().charAt(0).toUpperCase();
    const sizeClass = SIZES[size];
    const colorClass = colorFromName(name);

    if (image) {
        return <img src={image} alt={name} className={`${sizeClass} shrink-0 rounded-full object-cover`} />;
    }

    return (
        <span
            className={`${sizeClass} ${colorClass} inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white`}
            title={name}
        >
            {initial}
        </span>
    );
}
