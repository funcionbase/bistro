import type { ChatSource } from '@/hooks/use-chats';

const SOURCE_LABELS: Record<string, string> = {
    whatsapp: 'WhatsApp',
    instagram: 'Instagram',
    facebook: 'Facebook',
    sms: 'SMS',
};

interface Props {
    source: ChatSource;
    className?: string;
}

export function ChatSourceBadge({ source, className }: Props) {
    const label = SOURCE_LABELS[source.toLowerCase()] ?? source;

    return (
        <span
            className={`bg-muted/40 text-muted-foreground border-border inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium ${className ?? ''}`}
            title={`Origen: ${label}`}
        >
            {label}
        </span>
    );
}
