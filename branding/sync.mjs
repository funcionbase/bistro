// Copia los recursos de marca desplegables (branding/web/**) al public/ de cada
// app. `branding/` es la ÚNICA fuente de verdad: se edita acá y las apps heredan.
// Se ejecuta en `predev`/`prebuild` del frontend y puede correrse a mano para el
// backend (`node branding/sync.mjs`). El copiado es un merge no destructivo: solo
// pisa los archivos que existen en web/, deja intacto el resto de public/.
import { cp } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const web = join(here, 'web');
const targets = [join(here, '..', 'frontend', 'public'), join(here, '..', 'backend', 'public')];

for (const target of targets) {
    await cp(web, target, { recursive: true });
    console.log(`[branding] synced → ${target}`);
}
