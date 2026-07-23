import { Button } from '@/components/ui/button';

import { MessageCircle, UserPlus } from 'lucide-react';

export interface SharedContact {
    name?: string | null;
    phones?: string[] | null;
}

interface ChatContactCardProps {
    contacts: SharedContact[];
    /** Abre (o crea) la conversación con ese número dentro de la empresa. */
    onWriteTo?: (phone: string) => void;
    /** Guarda el contacto en el CRM. Ausente si el operador no tiene `chats.update`. */
    onSave?: (contact: SharedContact) => void;
}

/**
 * Tarjeta de contacto compartido (§6.7).
 *
 * El vCard crudo NO se renderiza ni se guarda: el backend ya lo parseó a nombre
 * y teléfonos. Mostrar el vCard sería volcar texto de un tercero en la UI.
 *
 * Las dos acciones son las que convierten el mensaje en algo útil: escribirle,
 * o guardarlo como cliente. Sin ellas la tarjeta es un texto que hay que copiar
 * a mano.
 */
export function ChatContactCard({ contacts, onWriteTo, onSave }: ChatContactCardProps) {
    if (contacts.length === 0) {
        return <span className="text-xs italic opacity-70">Contacto sin datos</span>;
    }

    return (
        <div className="flex w-[260px] max-w-full flex-col gap-2">
            {contacts.map((contact, index) => {
                const phone = contact.phones?.[0] ?? null;

                return (
                    <div key={`${contact.name ?? 'contacto'}-${index}`} className="bg-background/60 rounded-md border p-2">
                        <p className="truncate text-sm font-medium">{contact.name || 'Contacto sin nombre'}</p>

                        {(contact.phones ?? []).map((p) => (
                            <p key={p} className="text-muted-foreground truncate font-mono text-xs">
                                {p}
                            </p>
                        ))}

                        <div className="mt-2 flex flex-wrap gap-1">
                            {phone && onWriteTo && (
                                <Button size="sm" variant="outline" className="h-8" onClick={() => onWriteTo(phone)}>
                                    <MessageCircle className="mr-1 h-3.5 w-3.5" />
                                    Escribirle
                                </Button>
                            )}
                            {onSave && (
                                <Button size="sm" variant="ghost" className="h-8" onClick={() => onSave(contact)}>
                                    <UserPlus className="mr-1 h-3.5 w-3.5" />
                                    Guardar en contactos
                                </Button>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
