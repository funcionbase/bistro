import { Button } from '@/components/ui/button';
import { Download, LoaderCircle, QrCode } from 'lucide-react';
import QRCode from 'qrcode';
import { useEffect, useRef, useState } from 'react';

interface MenuQrPosterProps {
    nit: string;
    commercialName: string;
    logoUrl: string | null;
    primaryColor: string;
    /** Si se pasa, el QR apunta a /menus/{nit}?table=N (modo 'menu'). */
    tableNumber?: string | null;
    /** Tamaño base del canvas en píxeles. Default 800. */
    width?: number;
    height?: number;
    /**
     * Variante del QR generado (#191 Fase 8):
     *  - 'menu' (default): codifica `/menus/{nit}` (catálogo público para
     *    consultar, sin sesión grupal ni captura de comensales).
     *  - 'table-session': codifica `/t/{qrToken}` — flujo de mesa con QR
     *    grupal (#191). El cliente captura nombre + phone CO y queda
     *    enlazado al `Contact` del CRM. Requiere `qrToken`.
     */
    mode?: 'menu' | 'table-session';
    /**
     * Token único de la mesa (string alfanumérico de 40 chars). Obligatorio
     * cuando `mode='table-session'`. Resuelve directamente la sede + mesa
     * en el backend.
     */
    qrToken?: string;
}

const DEFAULT_WIDTH = 800;
const DEFAULT_HEIGHT = 1200;

/**
 * Componente cliente que dibuja un poster con QR + branding de la empresa en un canvas.
 *
 * Reactivo: cualquier cambio de `logoUrl`, `primaryColor`, `commercialName` o `tableNumber`
 * vuelve a dibujar inmediatamente — sin guardar nada en backend ni hacer round-trip.
 *
 * El QR codifica `${origin}/menus/${nit}` (con `?table=` opcional), que apunta a la página
 * pública que valida horario, caja y menú activo. La generación del QR usa correctionLevel
 * "M" — el logo NO va dentro del QR (decisión de diseño: el logo va arriba del QR como
 * branding, no embebido).
 */
export function MenuQrPoster({
    nit,
    commercialName,
    logoUrl,
    primaryColor,
    tableNumber,
    width = DEFAULT_WIDTH,
    height = DEFAULT_HEIGHT,
    mode = 'menu',
    qrToken,
}: MenuQrPosterProps) {
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const [downloading, setDownloading] = useState(false);

    const targetUrl = mode === 'table-session' && qrToken ? buildTableSessionUrl(qrToken) : buildTargetUrl(nit, tableNumber);

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) return;

        let cancelled = false;
        void renderPoster(canvas, {
            width,
            height,
            url: targetUrl,
            commercialName,
            logoUrl,
            primaryColor,
            tableNumber,
        }).catch(() => {
            // Si el render falla (logo CORS-blocked, p.ej.), el canvas queda con el último
            // estado válido — no rompemos UI.
        });

        return () => {
            cancelled = true;
            void cancelled;
        };
    }, [width, height, targetUrl, commercialName, logoUrl, primaryColor, tableNumber]);

    const handleDownload = async () => {
        const canvas = canvasRef.current;
        if (!canvas) return;
        setDownloading(true);
        try {
            const blob: Blob | null = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
            if (!blob) return;

            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = buildFilename(commercialName, tableNumber);
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } finally {
            setDownloading(false);
        }
    };

    return (
        <div className="space-y-3">
            <div className="bg-muted/40 flex items-center justify-center rounded-xl p-4">
                <canvas
                    ref={canvasRef}
                    width={width}
                    height={height}
                    className="h-auto w-full max-w-xs rounded-lg shadow-sm"
                    aria-label={`QR del menú de ${commercialName}`}
                />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <a
                    href={targetUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-muted-foreground hover:text-primary flex min-w-0 items-center gap-1.5 text-xs underline-offset-2 hover:underline"
                    title="Abrir el menú público en una pestaña nueva"
                >
                    <QrCode className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate font-mono">{targetUrl}</span>
                </a>
                <Button type="button" size="sm" onClick={handleDownload} disabled={downloading}>
                    {downloading ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
                    Descargar PNG
                </Button>
            </div>
        </div>
    );
}

function buildTargetUrl(nit: string, tableNumber?: string | null): string {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const path = `/menus/${encodeURIComponent(nit)}`;
    const trimmed = (tableNumber ?? '').trim();
    if (trimmed === '') return `${origin}${path}`;
    return `${origin}${path}?table=${encodeURIComponent(trimmed)}`;
}

/**
 * Construye la URL del QR de mesa con sesión grupal (#191). El qrToken
 * resuelve sede + mesa directamente en el backend — no se pasa nit ni
 * número.
 */
function buildTableSessionUrl(qrToken: string): string {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';

    return `${origin}/t/${encodeURIComponent(qrToken)}`;
}

function buildFilename(commercialName: string, tableNumber?: string | null): string {
    const slug =
        commercialName
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[^a-zA-Z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .toLowerCase() || 'menu';
    const tablePart = tableNumber ? `-mesa-${tableNumber}` : '';
    return `qr-menu-${slug}${tablePart}.png`;
}

interface RenderOpts {
    width: number;
    height: number;
    url: string;
    commercialName: string;
    logoUrl: string | null;
    primaryColor: string;
    tableNumber?: string | null;
}

async function renderPoster(canvas: HTMLCanvasElement, opts: RenderOpts): Promise<void> {
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const { width: W, height: H, url, commercialName, logoUrl, primaryColor, tableNumber } = opts;

    // Fondo blanco.
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);

    // Banda superior con headline.
    const bannerHeight = Math.round(H * 0.18);
    ctx.fillStyle = primaryColor;
    ctx.fillRect(0, 0, W, bannerHeight);

    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = `bold ${Math.round(W * 0.07)}px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`;
    ctx.fillText('Consulta la carta aquí', W / 2, bannerHeight / 2 - W * 0.015);
    ctx.font = `${Math.round(W * 0.035)}px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`;
    ctx.fillText('Escanea con la cámara de tu celular', W / 2, bannerHeight / 2 + W * 0.04);

    // Logo (si hay) sobre el banner, centrado verticalmente al borde inferior.
    let logoBottomY = bannerHeight;
    if (logoUrl) {
        try {
            const img = await loadImage(logoUrl);
            const logoSize = Math.round(W * 0.18);
            const logoX = (W - logoSize) / 2;
            const logoY = bannerHeight - logoSize / 2;

            // Círculo blanco detrás del logo para que destaque sobre la banda.
            ctx.beginPath();
            ctx.arc(W / 2, bannerHeight, logoSize / 2 + W * 0.015, 0, Math.PI * 2);
            ctx.fillStyle = '#ffffff';
            ctx.fill();

            // Recorte circular del logo.
            ctx.save();
            ctx.beginPath();
            ctx.arc(W / 2, bannerHeight, logoSize / 2, 0, Math.PI * 2);
            ctx.clip();
            ctx.drawImage(img, logoX, logoY, logoSize, logoSize);
            ctx.restore();

            logoBottomY = bannerHeight + logoSize / 2;
        } catch {
            // Si el logo falla CORS o 404, seguimos sin él — el QR es lo importante.
        }
    }

    // Nombre comercial.
    const nameY = logoBottomY + W * 0.06;
    ctx.fillStyle = '#1f2937';
    ctx.textAlign = 'center';
    ctx.font = `bold ${Math.round(W * 0.06)}px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`;
    ctx.fillText(truncate(commercialName, 30), W / 2, nameY);

    // QR centrado.
    const qrSize = Math.round(W * 0.62);
    const qrX = (W - qrSize) / 2;
    const qrY = nameY + W * 0.04;

    const qrCanvas = document.createElement('canvas');
    await QRCode.toCanvas(qrCanvas, url, {
        width: qrSize,
        margin: 2,
        // Los módulos del QR usan el color de marca de la empresa (#company/settings).
        // `qrDarkColor` garantiza contraste suficiente sobre blanco: si el color
        // configurado es muy claro lo oscurece, porque un QR casi blanco no lo lee
        // ninguna cámara.
        color: { dark: qrDarkColor(primaryColor), light: '#ffffff' },
        errorCorrectionLevel: 'M',
    });

    // Marco con accent color alrededor del QR.
    const frameThickness = Math.max(4, Math.round(W * 0.008));
    ctx.fillStyle = primaryColor;
    ctx.fillRect(qrX - frameThickness, qrY - frameThickness, qrSize + frameThickness * 2, qrSize + frameThickness * 2);
    ctx.drawImage(qrCanvas, qrX, qrY, qrSize, qrSize);

    // Mesa badge (si aplica) en la esquina inferior derecha del marco del QR.
    if (tableNumber && tableNumber.trim() !== '') {
        const badgeText = `Mesa ${tableNumber.trim()}`;
        const badgePadding = Math.round(W * 0.025);
        ctx.font = `bold ${Math.round(W * 0.04)}px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`;
        const textWidth = ctx.measureText(badgeText).width;
        const badgeWidth = textWidth + badgePadding * 2;
        const badgeHeight = Math.round(W * 0.07);
        const badgeX = qrX + qrSize - badgeWidth + frameThickness;
        const badgeY = qrY + qrSize - badgeHeight + frameThickness;

        ctx.fillStyle = primaryColor;
        ctx.beginPath();
        roundRect(ctx, badgeX, badgeY, badgeWidth, badgeHeight, Math.round(W * 0.018));
        ctx.fill();

        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(badgeText, badgeX + badgeWidth / 2, badgeY + badgeHeight / 2);
    }

    // Footer (sin URL legible: el código QR ya la lleva, mostrarla aparte ensucia
    // el poster y permitiría a un cliente teclearla mal).
    ctx.fillStyle = primaryColor;
    ctx.font = `bold ${Math.round(W * 0.03)}px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`;
    ctx.textAlign = 'center';
    ctx.fillText('Menú siempre actualizado', W / 2, H - W * 0.045);
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
    });
}

/**
 * Color oscuro para los módulos del QR. Usa el color de marca de la empresa,
 * pero si su luminancia es demasiado alta (poco contraste sobre fondo blanco)
 * lo oscurece manteniendo el matiz, para que el QR siga siendo escaneable.
 */
function qrDarkColor(hex: string): string {
    const rgb = hexToRgb(hex);
    if (!rgb) return '#111827';
    const { r, g, b } = rgb;
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    if (luminance <= 0.55) return normalizeHex(hex);
    const factor = 0.55 / luminance;
    return rgbToHex(Math.round(r * factor), Math.round(g * factor), Math.round(b * factor));
}

function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
    const match = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
    if (!match) return null;
    const int = parseInt(match[1], 16);
    return { r: (int >> 16) & 255, g: (int >> 8) & 255, b: int & 255 };
}

function rgbToHex(r: number, g: number, b: number): string {
    const clamp = (v: number) => Math.max(0, Math.min(255, v)).toString(16).padStart(2, '0');
    return `#${clamp(r)}${clamp(g)}${clamp(b)}`;
}

function normalizeHex(hex: string): string {
    return hex.trim().startsWith('#') ? hex.trim() : `#${hex.trim()}`;
}

function truncate(text: string, max: number): string {
    if (text.length <= max) return text;
    return text.slice(0, max - 1) + '…';
}

function roundRect(ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number): void {
    const radius = Math.min(r, w / 2, h / 2);
    ctx.moveTo(x + radius, y);
    ctx.arcTo(x + w, y, x + w, y + h, radius);
    ctx.arcTo(x + w, y + h, x, y + h, radius);
    ctx.arcTo(x, y + h, x, y, radius);
    ctx.arcTo(x, y, x + w, y, radius);
    ctx.closePath();
}
