import { ChatContactCard, type SharedContact } from '@/components/chats/chat-contact-card';
import type { ChatMediaPayload, ChatMediaType } from '@/hooks/use-chats';
import { ExternalLink, FileText, Mic, MapPin, Volume2, VolumeX } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    type: ChatMediaType;
    url: string | null | undefined;
    mime: string | null | undefined;
    body: string;
    /** Lo estructurado de §6.7: {lat,lng,name,address}, {contacts[]}, {file_name,size_bytes}. */
    payload?: ChatMediaPayload | null;
    /** Abre la imagen a tamaño completo. Ausente = la miniatura no es clickeable. */
    onOpenImage?: (url: string, caption?: string | null) => void;
    onWriteToContact?: (phone: string) => void;
    onSaveContact?: (contact: SharedContact) => void;
}

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Renderiza la parte multimedia de un mensaje de WhatsApp — los 9 tipos de §6.7:
 *   - image:           miniatura + lightbox + caption
 *   - video:           <video controls>
 *   - audio:           player custom con duracion + barra de progreso
 *   - nota de voz:     el mismo player, rotulado "nota de voz" (`payload.ptt`)
 *   - document:        icono + nombre + tamaño + descargar
 *   - sticker:         imagen ~120 px, SIN fondo de burbuja
 *   - location:        tarjeta con direccion + boton a Google Maps
 *   - contact:         tarjeta con acciones "Escribirle" / "Guardar en contactos"
 *   - sin media:       null (el caller renderiza el body como texto)
 *
 * La ubicacion y el contacto se leen de `media_payload` (jsonb), no del texto
 * del `body`. El parseo del string `[location] lat, lng | …` sigue existiendo
 * como fallback para los mensajes anteriores a F1, que no tienen payload.
 *
 * Si type tiene valor pero url aun es null (job de descarga en cola, o media
 * que llego sin base64) muestra un placeholder con el body literal.
 */
export function ChatMessageMedia({ type, url, body, payload, onOpenImage, onWriteToContact, onSaveContact }: Props) {
    // Ubicacion y contacto NO tienen archivo: se resuelven antes del check de
    // `url`, que si no los mandaria al placeholder "descargando…" para siempre.
    if (type === 'location' || (!type && body.startsWith('[location]'))) {
        return <LocationBlock body={body} payload={payload} />;
    }

    if (type === 'contact') {
        return (
            <ChatContactCard
                contacts={(payload?.contacts as SharedContact[] | undefined) ?? []}
                onWriteTo={onWriteToContact}
                onSave={onSaveContact}
            />
        );
    }

    if (!type) {
        return null;
    }

    if (!url) {
        return <span className="text-xs italic opacity-70">{body} (descargando…)</span>;
    }

    if (type === 'sticker') {
        // Sin fondo de burbuja: un sticker con caja alrededor se ve como un
        // error de render, no como un sticker.
        return <img src={url} alt="Sticker" className="h-30 w-30 object-contain" loading="lazy" />;
    }

    if (type === 'image') {
        const caption = typeof payload?.caption === 'string' ? payload.caption : null;
        return (
            <figure className="space-y-1">
                <button
                    type="button"
                    onClick={() => onOpenImage?.(url, caption)}
                    className="focus-visible:ring-ring block rounded-md focus-visible:ring-2 focus-visible:outline-none"
                    aria-label="Ampliar imagen"
                >
                    <img src={url} alt={caption || 'Imagen recibida'} className="max-h-64 max-w-full rounded-md object-contain" loading="lazy" />
                </button>
                {caption && <figcaption className="text-xs opacity-80">{caption}</figcaption>}
            </figure>
        );
    }

    if (type === 'video') {
        return <video src={url} controls className="max-h-64 max-w-full rounded-md" preload="metadata" />;
    }

    if (type === 'audio') {
        // Una nota de voz y un mp3 adjunto se reproducen igual pero no son lo
        // mismo: rotularlo evita que el operador crea que le mandaron un archivo.
        const isVoiceNote = payload?.ptt === true;
        return (
            <div className="space-y-1">
                {isVoiceNote && (
                    <p className="flex items-center gap-1 text-[10px] opacity-70">
                        <Mic className="h-3 w-3" />
                        Nota de voz
                    </p>
                )}
                <AudioPlayer url={url} />
            </div>
        );
    }

    if (type === 'document') {
        const fileName = (typeof payload?.file_name === 'string' ? payload.file_name : null) ?? body.replace(/^\[document\]\s*/, '') ?? 'Documento';
        const size = typeof payload?.size_bytes === 'number' ? payload.size_bytes : null;

        return (
            <a
                href={url}
                target="_blank"
                rel="noopener noreferrer"
                // 44 px de alto: descargar un adjunto con el pulgar no puede
                // depender de acertarle a una línea de texto.
                className="hover:bg-muted/50 flex min-h-11 w-[240px] max-w-full items-center gap-2 rounded-md border px-2 py-1.5 text-xs"
            >
                <FileText className="h-5 w-5 shrink-0" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{fileName || 'Documento'}</span>
                    {size !== null && <span className="block opacity-70">{formatSize(size)}</span>}
                </span>
            </a>
        );
    }

    return <span className="text-xs italic opacity-70">{body}</span>;
}

function formatTime(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}

/**
 * Reproductor de audio compacto estilo WhatsApp: boton play/pause, barra de
 * progreso clickeable y duracion. Mas legible que <audio controls> nativo
 * (que cambia de aspecto entre browsers y ocupa demasiado ancho).
 */
function AudioPlayer({ url }: { url: string }) {
    const audioRef = useRef<HTMLAudioElement>(null);
    const rafRef = useRef<number | null>(null);
    const trackRef = useRef<HTMLDivElement>(null);
    // Mientras el usuario arrastra, ignoramos eventos `timeupdate` del audio
    // y mostramos la posición visual del thumb (escena WhatsApp).
    const draggingRef = useRef(false);
    const wasPlayingRef = useRef(false);
    // Cancelable cleanup de la rutina de detección de duración (evita que
    // su seek-back-to-0 sobrescriba la posición elegida por el usuario).
    const cancelDurationProbeRef = useRef<(() => void) | null>(null);
    const [playing, setPlaying] = useState(false);
    const [current, setCurrent] = useState(0);
    const [duration, setDuration] = useState(0);
    const [muted, setMuted] = useState(false);
    const [dragging, setDragging] = useState(false);

    // Algunos códecs (opus/ogg de WhatsApp, webm) reportan `duration = Infinity`
    // hasta que se hace seek al final del clip. Forzamos ese seek una sola vez,
    // pero la rutina debe ser cancelable: si el usuario interactúa antes de
    // que termine, el reset a 0 sobrescribiría su posición elegida.
    const probeInfiniteDuration = (el: HTMLAudioElement) => {
        if (el.duration !== Infinity || cancelDurationProbeRef.current) return;

        const onSeeked = () => {
            el.removeEventListener('seeked', onSeeked);
            cancelDurationProbeRef.current = null;
            if (Number.isFinite(el.duration)) setDuration(el.duration);
            // Solo regresa al inicio si el usuario aún no tomó control.
            if (!draggingRef.current) {
                el.currentTime = 0;
                setCurrent(0);
            }
        };

        cancelDurationProbeRef.current = () => {
            el.removeEventListener('seeked', onSeeked);
            cancelDurationProbeRef.current = null;
            // Recoge la duración detectada aunque cancelemos el reset.
            if (Number.isFinite(el.duration)) setDuration(el.duration);
        };

        el.addEventListener('seeked', onSeeked);
        // Valor grande pero seguro: el browser hace clamp al final real.
        el.currentTime = Number.MAX_SAFE_INTEGER;
    };

    useEffect(() => {
        const el = audioRef.current;
        if (!el) return;

        const onLoaded = () => {
            if (Number.isFinite(el.duration) && el.duration > 0) {
                setDuration(el.duration);
            } else if (el.duration === Infinity) {
                probeInfiniteDuration(el);
            }
        };
        const onTime = () => {
            if (!draggingRef.current) setCurrent(el.currentTime || 0);
        };
        const onEnd = () => {
            setPlaying(false);
            setCurrent(0);
        };
        const onPlay = () => setPlaying(true);
        const onPause = () => setPlaying(false);

        el.addEventListener('loadedmetadata', onLoaded);
        el.addEventListener('durationchange', onLoaded);
        el.addEventListener('timeupdate', onTime);
        el.addEventListener('ended', onEnd);
        el.addEventListener('play', onPlay);
        el.addEventListener('pause', onPause);

        if (el.readyState >= 1) onLoaded();

        return () => {
            cancelDurationProbeRef.current?.();
            el.removeEventListener('loadedmetadata', onLoaded);
            el.removeEventListener('durationchange', onLoaded);
            el.removeEventListener('timeupdate', onTime);
            el.removeEventListener('ended', onEnd);
            el.removeEventListener('play', onPlay);
            el.removeEventListener('pause', onPause);
        };
    }, [url]);

    useEffect(() => {
        if (!playing) {
            if (rafRef.current !== null) {
                cancelAnimationFrame(rafRef.current);
                rafRef.current = null;
            }
            return;
        }
        const tick = () => {
            const el = audioRef.current;
            if (el && !draggingRef.current) setCurrent(el.currentTime || 0);
            rafRef.current = requestAnimationFrame(tick);
        };
        rafRef.current = requestAnimationFrame(tick);
        return () => {
            if (rafRef.current !== null) {
                cancelAnimationFrame(rafRef.current);
                rafRef.current = null;
            }
        };
    }, [playing]);

    const toggle = () => {
        const el = audioRef.current;
        if (!el) return;
        if (el.paused) {
            void el.play();
        } else {
            el.pause();
        }
    };

    const toggleMute = () => {
        const el = audioRef.current;
        if (!el) return;
        const next = !el.muted;
        el.muted = next;
        setMuted(next);
    };

    const ratioFromPointer = (clientX: number) => {
        const track = trackRef.current;
        if (!track) return 0;
        const rect = track.getBoundingClientRect();
        return Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
    };

    const handlePointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
        const el = audioRef.current;
        if (!el || !duration) return;
        e.preventDefault();
        // Cancela la sonda de duración pendiente para que su reset a 0 no
        // pise la posición elegida por el usuario al soltar.
        cancelDurationProbeRef.current?.();
        draggingRef.current = true;
        wasPlayingRef.current = !el.paused;
        setDragging(true);
        // Pausamos durante el drag para que el audio no se "salte" mientras
        // el usuario está decidiendo a qué punto ir.
        if (!el.paused) el.pause();
        const ratio = ratioFromPointer(e.clientX);
        const t = ratio * duration;
        el.currentTime = t;
        setCurrent(t);
        e.currentTarget.setPointerCapture(e.pointerId);
    };

    const handlePointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
        if (!draggingRef.current) return;
        const el = audioRef.current;
        if (!el || !duration) return;
        const ratio = ratioFromPointer(e.clientX);
        const t = ratio * duration;
        el.currentTime = t;
        setCurrent(t);
    };

    const handlePointerUp = (e: React.PointerEvent<HTMLDivElement>) => {
        if (!draggingRef.current) return;
        const el = audioRef.current;
        draggingRef.current = false;
        setDragging(false);
        if (e.currentTarget.hasPointerCapture(e.pointerId)) {
            e.currentTarget.releasePointerCapture(e.pointerId);
        }
        if (!el) return;
        // Reasegura la posición: si algún `seeked` rezagado disparó otro
        // currentTime, forzamos el valor que el usuario soltó.
        const ratio = ratioFromPointer(e.clientX);
        const t = ratio * duration;
        if (Number.isFinite(t)) el.currentTime = t;
        if (wasPlayingRef.current) {
            void el.play();
        }
    };

    const progress = duration > 0 ? (current / duration) * 100 : 0;
    const displayTime = `${formatTime(current)} / ${formatTime(duration || 0)}`;

    return (
        <div className="bg-background/50 flex w-[260px] max-w-full items-center gap-2 rounded-md border px-2 py-1.5">
            <audio ref={audioRef} src={url} preload="metadata" />
            <button
                type="button"
                onClick={toggle}
                className="bg-primary text-primary-foreground inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-opacity hover:opacity-90"
                aria-label={playing ? 'Pausar' : 'Reproducir'}
            >
                {playing ? <PauseIcon /> : <PlayIcon />}
            </button>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                {/* Hit area generosa (`py-2`) para que arrastrar sea cómodo,
                    pero la barra visible queda fina (`h-1`) como en WhatsApp. */}
                <div
                    ref={trackRef}
                    onPointerDown={handlePointerDown}
                    onPointerMove={handlePointerMove}
                    onPointerUp={handlePointerUp}
                    onPointerCancel={handlePointerUp}
                    className="group relative -my-1.5 cursor-pointer touch-none py-2"
                    role="slider"
                    tabIndex={0}
                    aria-valuemin={0}
                    aria-valuemax={Math.round(duration)}
                    aria-valuenow={Math.round(current)}
                    aria-label="Posición del audio"
                    style={{ touchAction: 'none' }}
                >
                    <div className="bg-muted relative h-1 rounded-full">
                        <div className="bg-primary absolute inset-y-0 left-0 rounded-full" style={{ width: `${progress}%` }} />
                        <div
                            className={`bg-primary ring-background absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full shadow-sm ring-2 transition-transform ${
                                dragging ? 'scale-125' : 'group-hover:scale-110'
                            }`}
                            style={{ left: `${progress}%` }}
                            aria-hidden="true"
                        />
                    </div>
                </div>
                <div className="flex items-center justify-between text-[10px] opacity-70">
                    <button
                        type="button"
                        onClick={toggleMute}
                        className="-ml-0.5 inline-flex h-4 w-4 items-center justify-center rounded transition-opacity hover:opacity-100"
                        aria-label={muted ? 'Activar sonido' : 'Silenciar'}
                        aria-pressed={muted}
                        title={muted ? 'Activar sonido' : 'Silenciar'}
                    >
                        {muted ? <VolumeX className="h-3 w-3" /> : <Volume2 className="h-3 w-3" />}
                    </button>
                    <span className="font-mono tabular-nums">{displayTime}</span>
                </div>
            </div>
        </div>
    );
}

function PlayIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 fill-current" aria-hidden="true">
            <path d="M8 5.14v13.72a1 1 0 0 0 1.55.83l10.4-6.86a1 1 0 0 0 0-1.66l-10.4-6.86A1 1 0 0 0 8 5.14z" />
        </svg>
    );
}

function PauseIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 fill-current" aria-hidden="true">
            <rect x="6" y="5" width="4" height="14" rx="1" />
            <rect x="14" y="5" width="4" height="14" rx="1" />
        </svg>
    );
}

/**
 * Ubicacion recibida (§6.7).
 *
 * `media_payload` es la fuente preferida: F0 verifico que `locationMessage`
 * llega SIN `name` ni `address` en el caso crudo, asi que muchas veces solo hay
 * coordenadas y la tarjeta tiene que rotularse con ellas.
 *
 * El parseo del `body` queda como fallback para los mensajes anteriores a F1,
 * que no tienen payload y solo dejaron el texto "[location] lat, lng | …".
 */
function LocationBlock({ body, payload }: { body: string; payload?: ChatMediaPayload | null }) {
    let lat: string | undefined;
    let lng: string | undefined;
    let name: string | undefined;
    let address: string | undefined;

    if (typeof payload?.lat === 'number' && typeof payload?.lng === 'number') {
        lat = String(payload.lat);
        lng = String(payload.lng);
        name = typeof payload.name === 'string' ? payload.name : undefined;
        address = typeof payload.address === 'string' ? payload.address : undefined;
    } else {
        // Formato legacy: "[location] lat, lng" o "[location] lat, lng | nombre | direccion"
        const match = body.match(/\[location\]\s+([-\d.]+),\s*([-\d.]+)(?:\s*\|\s*(.*))?/);
        if (!match) {
            return <span className="text-xs italic opacity-70">{body}</span>;
        }
        const parts = match[3]
            ? match[3]
                  .split('|')
                  .map((s) => s.trim())
                  .filter(Boolean)
            : [];
        [, lat, lng] = match;
        [name, address] = parts;
    }

    if (!lat || !lng) {
        return <span className="text-xs italic opacity-70">{body}</span>;
    }

    const mapsUrl = `https://www.google.com/maps?q=${encodeURIComponent(lat)},${encodeURIComponent(lng)}`;
    // Static map preview vía OpenStreetMap (sin API key, sin tracking).
    // Tile size 280x140 con un marker centrado en la coordenada.
    const previewUrl = `https://staticmap.openstreetmap.de/staticmap.php?center=${lat},${lng}&zoom=15&size=280x140&markers=${lat},${lng},red-pushpin`;

    return (
        <a
            href={mapsUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="group bg-background flex w-[280px] max-w-full flex-col overflow-hidden rounded-md border"
            title="Abrir en Google Maps (nueva pestaña)"
        >
            <div className="relative">
                <img
                    src={previewUrl}
                    alt="Vista previa de la ubicación"
                    loading="lazy"
                    className="bg-muted h-[140px] w-full object-cover"
                    onError={(e) => {
                        // Si OSM no responde (raro), oculta la imagen y deja el bloque
                        // de texto debajo como fallback.
                        e.currentTarget.style.display = 'none';
                    }}
                />
                {/* Pin grande sobre el mapa para que se entienda que es la ubicacion compartida */}
                <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div className="rounded-full bg-red-500 p-1.5 shadow-md ring-2 ring-white">
                        <MapPin className="h-4 w-4 fill-white text-white" />
                    </div>
                </div>
            </div>
            <div className="flex flex-col gap-1.5 p-2">
                {(name || address) && (
                    <div className="flex flex-col text-xs">
                        {name ? <span className="truncate font-medium">{name}</span> : null}
                        {address ? <span className="truncate opacity-70">{address}</span> : null}
                    </div>
                )}
                {/* Boton-CTA explicito estilo Google Maps con logo + label "Abrir en Google Maps" */}
                <div className="bg-muted/40 group-hover:bg-muted flex items-center justify-between gap-2 rounded px-2 py-1.5 text-xs font-medium transition-colors">
                    <span className="inline-flex items-center gap-1.5">
                        <GoogleMapsIcon />
                        Abrir en Google Maps
                    </span>
                    <ExternalLink className="h-3.5 w-3.5 opacity-60 transition-opacity group-hover:opacity-100" />
                </div>
                <span className="font-mono text-[10px] opacity-50">
                    {Number(lat).toFixed(5)}, {Number(lng).toFixed(5)}
                </span>
            </div>
        </a>
    );
}

/**
 * Pin de Google Maps a color (rojo + amarillo + azul + verde) inline para no
 * cargar imagenes externas. Identifica visualmente la marca Google Maps.
 */
function GoogleMapsIcon() {
    return (
        <svg viewBox="0 0 32 32" className="h-3.5 w-3.5" aria-hidden="true">
            <path
                d="M16 2C9.93 2 5 6.93 5 13c0 7.97 9.7 16.46 10.11 16.81a1.4 1.4 0 0 0 1.78 0C17.3 29.46 27 20.97 27 13c0-6.07-4.93-11-11-11z"
                fill="#EA4335"
            />
            <circle cx="16" cy="13" r="4.5" fill="#FFFFFF" />
            <circle cx="16" cy="13" r="3.5" fill="#4285F4" />
        </svg>
    );
}
