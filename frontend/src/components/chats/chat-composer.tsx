import { QuickReplyMenu } from '@/components/chats/quick-reply-menu';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { type QuickReply, resolveQuickReplyVariables } from '@/hooks/use-quick-replies';
import { compressImage } from '@/lib/compress-image';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { cn } from '@/lib/utils';

import { BookOpen, FileText, Image as ImageIcon, Paperclip, Receipt, Send, ShoppingCart, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** Tope propio de §6.7, el mismo que valida el backend. Se avisa ANTES de subir. */
const MAX_BYTES = 16 * 1024 * 1024;

type AttachmentKind = 'image' | 'video' | 'audio' | 'document';

interface PendingAttachment {
    file: File;
    kind: AttachmentKind;
    /** ObjectURL para la previsualización. Se revoca al soltar el archivo. */
    previewUrl: string | null;
}

interface ChatComposerProps {
    disabled: boolean;
    /** Motivo por el que no se puede escribir. Se muestra como texto, no como tooltip. */
    disabledReason?: string | null;
    sending: boolean;
    onSendText: (body: string) => Promise<void>;
    onSendAttachment: (file: File, kind: AttachmentKind, caption: string) => Promise<void>;
    /** Respuestas rápidas (§8.4b punto 7). El menú se abre al escribir `/`. */
    quickReplies?: QuickReply[];
    /** Valores para resolver {{cliente}}/{{pedido}}/{{sede}} al insertar (§8.4b punto 14). */
    quickReplyVars?: { cliente?: string | null; pedido?: string | null; sede?: string | null };
    /** Link público del menú (client-side). Si viene, habilita "Enviar carta" (§8.4b punto 8). */
    menuUrl?: string | null;
    /** Mintea el link de carrito en el backend. Si viene, habilita "Enviar carrito" (§8.4b punto 8). */
    onRequestCartLink?: () => Promise<string | null>;
    /** Abre el flujo de creación de pedido con el cliente prellenado (§8.4b punto 9). */
    onCreateOrder?: () => void;
}

function kindFor(file: File): AttachmentKind {
    if (file.type.startsWith('image/')) return 'image';
    if (file.type.startsWith('video/')) return 'video';
    if (file.type.startsWith('audio/')) return 'audio';
    return 'document';
}

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Compositor de la bandeja: texto + adjuntos + productividad (§8.4).
 *
 * Tres entradas para el mismo archivo porque los tres casos son reales:
 *  - **clip** con menú (foto/video, documento) — el camino explícito;
 *  - **arrastrar y soltar** sobre la conversación;
 *  - **pegar desde el portapapeles**, que es el caso más frecuente de todos: el
 *    operador copia la foto del plato y hace Ctrl+V.
 *
 * F3b suma dos cosas: el menú `/` de respuestas rápidas (§8.4b punto 7, con
 * variables) y las acciones de carta / carrito / crear pedido (§8.4b puntos 8-9).
 *
 * El compositor queda SOBRE el teclado virtual en móvil usando `visualViewport`.
 * Sin eso el input se va debajo del teclado, que es el bug clásico de chat en
 * celular y el que hace que nadie responda desde el panel.
 */
export function ChatComposer({
    disabled,
    disabledReason,
    sending,
    onSendText,
    onSendAttachment,
    quickReplies,
    quickReplyVars,
    menuUrl,
    onRequestCartLink,
    onCreateOrder,
}: ChatComposerProps) {
    const [draft, setDraft] = useState('');
    const [attachment, setAttachment] = useState<PendingAttachment | null>(null);
    const [dragging, setDragging] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [linkBusy, setLinkBusy] = useState(false);
    const [keyboardInset, setKeyboardInset] = useState(0);

    // Menú `/` de respuestas rápidas.
    const [slashDismissed, setSlashDismissed] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);

    const inputRef = useRef<HTMLInputElement>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const documentInputRef = useRef<HTMLInputElement>(null);

    // El teclado virtual no cambia `window.innerHeight` en iOS: solo se entera
    // `visualViewport`. Sin esta compensación el compositor queda tapado.
    useEffect(() => {
        const vv = window.visualViewport;
        if (!vv) return;

        const update = () => {
            const inset = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
            setKeyboardInset(inset);
        };

        update();
        vv.addEventListener('resize', update);
        vv.addEventListener('scroll', update);
        return () => {
            vv.removeEventListener('resize', update);
            vv.removeEventListener('scroll', update);
        };
    }, []);

    // El ObjectURL se revoca al cambiar o desmontar: sin esto cada foto que el
    // operador descarta queda retenida en memoria hasta recargar la página.
    useEffect(() => {
        return () => {
            if (attachment?.previewUrl) URL.revokeObjectURL(attachment.previewUrl);
        };
    }, [attachment]);

    // El menú `/` se abre cuando el borrador es UN solo token que empieza con `/`
    // ("/", "/dir", …). Así no se dispara con "https://" ni con "3/4": solo con
    // el gesto explícito de arrancar el mensaje con una barra.
    const slashMatch = draft.match(/^\/(\S*)$/);
    const slashQuery = slashMatch ? slashMatch[1] : null;
    const slashOpen = slashQuery !== null && !slashDismissed && !attachment && !disabled;

    const filteredReplies = useMemo(() => {
        if (slashQuery === null) return [];
        const q = slashQuery.toLowerCase();
        return (quickReplies ?? []).filter((r) => q === '' || r.title.toLowerCase().includes(q) || r.body.toLowerCase().includes(q)).slice(0, 8);
    }, [slashQuery, quickReplies]);

    // Al cambiar la lista visible, el resaltado vuelve al primero para no quedar
    // apuntando a un índice que ya no existe.
    useEffect(() => {
        setActiveIndex(0);
    }, [slashQuery]);

    const insertQuickReply = useCallback(
        (reply: QuickReply) => {
            setDraft(resolveQuickReplyVariables(reply.body, quickReplyVars ?? {}));
            setSlashDismissed(true);
            inputRef.current?.focus();
        },
        [quickReplyVars],
    );

    /** Agrega texto al borrador (link de carta/carrito), respetando lo ya escrito. */
    const appendText = useCallback((text: string) => {
        setDraft((prev) => (prev.trim() ? `${prev.trimEnd()}\n${text}` : text));
        inputRef.current?.focus();
    }, []);

    const shareCart = useCallback(async () => {
        if (!onRequestCartLink) return;
        setError(null);
        setLinkBusy(true);
        try {
            const url = await onRequestCartLink();
            if (url) appendText(url);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'No se pudo generar el carrito.');
        } finally {
            setLinkBusy(false);
        }
    }, [onRequestCartLink, appendText]);

    const acceptFile = useCallback(async (file: File) => {
        setError(null);

        // Se comprime ANTES de medir: una foto de 8 MB del celular baja de los
        // 16 MB por sí sola y no tiene sentido rechazarla primero.
        const prepared = file.type.startsWith('image/') ? await compressImage(file) : file;

        if (prepared.size > MAX_BYTES) {
            setError(`«${prepared.name}» pesa ${formatSize(prepared.size)}. El máximo es 16 MB.`);
            return;
        }

        const kind = kindFor(prepared);

        setAttachment((prev) => {
            if (prev?.previewUrl) URL.revokeObjectURL(prev.previewUrl);
            return {
                file: prepared,
                kind,
                previewUrl: kind === 'image' || kind === 'video' ? URL.createObjectURL(prepared) : null,
            };
        });
    }, []);

    const clearAttachment = () => {
        setAttachment((prev) => {
            if (prev?.previewUrl) URL.revokeObjectURL(prev.previewUrl);
            return null;
        });
        setError(null);
    };

    const handlePaste = (e: React.ClipboardEvent) => {
        if (disabled) return;
        const file = Array.from(e.clipboardData?.files ?? [])[0];
        if (!file) return;
        e.preventDefault();
        void acceptFile(file);
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        setDragging(false);
        if (disabled) return;
        const file = Array.from(e.dataTransfer?.files ?? [])[0];
        if (file) void acceptFile(file);
    };

    // Navegación del menú `/` con teclado. Solo intercepta cuando el menú está
    // abierto con resultados; si no, el input se comporta normal (Enter envía).
    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (!slashOpen || filteredReplies.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex((i) => (i + 1) % filteredReplies.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex((i) => (i - 1 + filteredReplies.length) % filteredReplies.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            insertQuickReply(filteredReplies[activeIndex]);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            setSlashDismissed(true);
        }
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (disabled || busy || sending) return;

        setError(null);
        setBusy(true);
        try {
            if (attachment) {
                await onSendAttachment(attachment.file, attachment.kind, draft.trim());
                clearAttachment();
                setDraft('');
            } else if (draft.trim()) {
                await onSendText(draft);
                setDraft('');
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : 'No se pudo enviar.');
        } finally {
            setBusy(false);
        }
    };

    const working = busy || sending;
    const canSubmit = !disabled && !working && (Boolean(attachment) || draft.trim().length > 0);
    const hasActions = Boolean(menuUrl) || Boolean(onRequestCartLink) || Boolean(onCreateOrder);

    return (
        <form
            noValidate
            onSubmit={submit}
            onDragOver={(e) => {
                e.preventDefault();
                if (!disabled) setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={handleDrop}
            style={{ paddingBottom: keyboardInset ? `${keyboardInset}px` : undefined }}
            className={cn('relative border-t p-3 transition-colors', dragging && 'bg-primary/5 border-primary')}
        >
            {slashOpen && (
                <QuickReplyMenu
                    items={filteredReplies}
                    activeIndex={activeIndex}
                    onSelect={insertQuickReply}
                    onHover={setActiveIndex}
                    empty={(quickReplies ?? []).length === 0}
                />
            )}

            {dragging && <p className="text-primary mb-2 text-center text-xs">Soltá el archivo para adjuntarlo</p>}

            {error && <p className="text-destructive mb-2 text-xs">{error}</p>}

            {disabledReason && <p className="text-muted-foreground mb-2 text-xs">{disabledReason}</p>}

            {attachment && (
                <div className="bg-muted/40 mb-2 flex items-center gap-3 rounded-lg border p-2">
                    {attachment.previewUrl && attachment.kind === 'image' ? (
                        <img src={attachment.previewUrl} alt="" className="h-14 w-14 rounded object-cover" />
                    ) : attachment.previewUrl && attachment.kind === 'video' ? (
                        <video src={attachment.previewUrl} className="h-14 w-14 rounded object-cover" muted />
                    ) : (
                        <FileText className="text-muted-foreground h-8 w-8 shrink-0" />
                    )}
                    <div className="min-w-0 flex-1 text-xs">
                        <p className="truncate font-medium">{attachment.file.name}</p>
                        <p className="text-muted-foreground">{formatSize(attachment.file.size)}</p>
                        {working && (
                            <div className="bg-muted mt-1 h-1 overflow-hidden rounded-full">
                                <div className="bg-primary h-full w-1/2 animate-pulse rounded-full" />
                            </div>
                        )}
                    </div>
                    {/* 44 px de área táctil: quitar un adjunto con el pulgar no
                        puede depender de acertarle a un ícono de 12 px. */}
                    <button
                        type="button"
                        onClick={clearAttachment}
                        disabled={working}
                        className="hover:bg-muted flex h-11 w-11 shrink-0 items-center justify-center rounded"
                        aria-label="Quitar adjunto"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            )}

            <div className="flex items-end gap-2">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            disabled={disabled || working}
                            className="h-11 w-11 shrink-0"
                            // El límite se dice ANTES de intentar, no en el error.
                            title="Foto, video, audio o documento. Hasta 16 MB."
                            aria-label="Adjuntar archivo"
                        >
                            <Paperclip className="h-5 w-5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem onClick={() => fileInputRef.current?.click()}>
                            <ImageIcon className="mr-2 h-4 w-4" />
                            Foto o video
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => documentInputRef.current?.click()}>
                            <FileText className="mr-2 h-4 w-4" />
                            Documento
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                {hasActions && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={disabled || working || linkBusy}
                                className="h-11 w-11 shrink-0"
                                aria-label="Acciones rápidas"
                                title="Enviar la carta, un carrito o crear un pedido"
                            >
                                <Sparkles className="h-5 w-5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            {menuUrl && (
                                <DropdownMenuItem onClick={() => appendText(menuUrl)}>
                                    <BookOpen className="mr-2 h-4 w-4" />
                                    Enviar la carta
                                </DropdownMenuItem>
                            )}
                            {onRequestCartLink && (
                                <DropdownMenuItem onClick={() => void shareCart()} disabled={linkBusy}>
                                    <ShoppingCart className="mr-2 h-4 w-4" />
                                    {linkBusy ? 'Generando…' : 'Enviar un carrito'}
                                </DropdownMenuItem>
                            )}
                            {onCreateOrder && (
                                <DropdownMenuItem onClick={onCreateOrder}>
                                    <Receipt className="mr-2 h-4 w-4" />
                                    Crear pedido para este cliente
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}

                {/* `capture` da acceso directo a la cámara en móvil, que es de
                    donde sale la foto del plato en el caso real. */}
                <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/*,video/*"
                    capture="environment"
                    className="hidden"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) void acceptFile(file);
                        e.target.value = '';
                    }}
                />
                <input
                    ref={documentInputRef}
                    type="file"
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,audio/*"
                    className="hidden"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) void acceptFile(file);
                        e.target.value = '';
                    }}
                />

                <input
                    ref={inputRef}
                    type="text"
                    value={draft}
                    onChange={(e) => {
                        setDraft(sanitizePlainText(e.target.value, 4000, true, false));
                        setSlashDismissed(false);
                    }}
                    onKeyDown={handleKeyDown}
                    onPaste={handlePaste}
                    maxLength={4000}
                    placeholder={attachment ? 'Agregá un comentario (opcional)…' : 'Escribe un mensaje o usá / para respuestas rápidas…'}
                    className="border-input bg-background focus:ring-primary min-h-11 flex-1 rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:outline-none disabled:opacity-60"
                    disabled={disabled || working}
                />

                <Button type="submit" disabled={!canSubmit} className="h-11 shrink-0">
                    <Send className="h-4 w-4" />
                    <span className="sr-only sm:not-sr-only sm:ml-1">Enviar</span>
                </Button>
            </div>
        </form>
    );
}

export type { AttachmentKind };
