import sharp from 'sharp';
import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const outDir = resolve(__dirname, '..', 'public', 'icons');
const blackFontSvg = resolve(__dirname, '..', 'public', 'images', 'logo-black-font.svg');

await mkdir(outDir, { recursive: true });

/**
 * Renderiza el logo black-font dentro de un canvas cuadrado con fondo
 * sólido. `safeAreaPct` determina el padding interior (necesario para
 * variantes maskable, donde Android puede recortar hasta 20%).
 */
async function renderSquare({ size, output, background, safeAreaPct }) {
    const inner = Math.round(size * (1 - safeAreaPct * 2));
    const logoBuffer = await sharp(blackFontSvg)
        .resize({ width: inner, height: inner, fit: 'inside', background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .png()
        .toBuffer();
    const meta = await sharp(logoBuffer).metadata();
    const left = Math.round((size - meta.width) / 2);
    const top = Math.round((size - meta.height) / 2);
    await sharp({
        create: { width: size, height: size, channels: 4, background },
    })
        .composite([{ input: logoBuffer, left, top }])
        .png({ compressionLevel: 9 })
        .toFile(resolve(outDir, output));
    console.log(`wrote ${output}`);
}

const white = { r: 255, g: 255, b: 255, alpha: 1 };

await renderSquare({ size: 192, output: 'icon-192.png', background: white, safeAreaPct: 0.08 });
await renderSquare({ size: 512, output: 'icon-512.png', background: white, safeAreaPct: 0.08 });
await renderSquare({ size: 180, output: 'apple-touch-icon-180.png', background: white, safeAreaPct: 0.08 });
await renderSquare({ size: 192, output: 'icon-192-maskable.png', background: white, safeAreaPct: 0.18 });
await renderSquare({ size: 512, output: 'icon-512-maskable.png', background: white, safeAreaPct: 0.18 });
