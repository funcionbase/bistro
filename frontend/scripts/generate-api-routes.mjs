#!/usr/bin/env node
/**
 * Genera dos archivos a partir de `php artisan route:list --json` (HU #220):
 *
 *  1. `resources/js/lib/api-routes.ts` — builders tipados de rutas `api.*`:
 *       apiRoutes.orders.show(id)        -> '/api/v1/orders/123'
 *       apiRoutes.cashRegister.current() -> '/api/v1/cash-register/current'
 *
 *  2. `resources/js/lib/route-map.ts` — mapa plano `nombre -> uri template`
 *     de TODAS las rutas nombradas (web + api). Lo consume `route-compat.ts`
 *     para resolver `route('dashboard')` en el shell SPA donde Ziggy no existe.
 *
 * Uso:
 *   node scripts/generate-api-routes.mjs
 *
 * Idempotente: si la salida no cambia, no toca el archivo (evita rebuilds).
 */
import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const OUT_PATH = resolve(process.cwd(), 'src/lib/api-routes.ts');
const ROUTE_MAP_PATH = resolve(process.cwd(), 'src/lib/route-map.ts');
// El backend Laravel es un proyecto hermano (#220).
const BACKEND_DIR = resolve(process.cwd(), '../backend');

function fetchAllNamedRoutes() {
    const raw = execSync('php artisan route:list --json', {
        cwd: BACKEND_DIR,
        encoding: 'utf8',
        maxBuffer: 16 * 1024 * 1024,
    });
    return JSON.parse(raw).filter((r) => typeof r.name === 'string' && r.name !== '');
}

function fetchRoutes() {
    return fetchAllNamedRoutes().filter((r) => r.name.startsWith('api.'));
}

/**
 * Convierte 'api.orders.show' -> ['orders', 'show'].
 * El prefijo 'api.' siempre se descarta.
 */
function namePathParts(name) {
    return name
        .split('.')
        .slice(1)
        .map((seg) => seg.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase()));
}

/**
 * Extrae los parámetros de URI `{id}`, `{orderId?}` -> [{ name: 'id', optional: false }, ...].
 */
function extractParams(uri) {
    const params = [];
    const re = /\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?\}/g;
    let match;
    while ((match = re.exec(uri)) !== null) {
        params.push({ name: match[1], optional: match[2] === '?' });
    }
    return params;
}

function escapeStringLiteral(s) {
    return s.replace(/\\/g, '\\\\').replace(/`/g, '\\`').replace(/\$/g, '\\$');
}

function buildTree(routes) {
    const tree = {};
    for (const r of routes) {
        const parts = namePathParts(r.name);
        if (parts.length === 0) continue;

        let node = tree;
        for (let i = 0; i < parts.length - 1; i++) {
            const seg = parts[i];
            if (!node[seg]) node[seg] = {};
            node = node[seg];
        }
        const leaf = parts[parts.length - 1];

        if (node[leaf] && typeof node[leaf] === 'object' && '__route__' in node[leaf]) {
            continue;
        }

        const uri = '/' + r.uri.replace(/^\/?/, '');
        const params = extractParams(uri);

        node[leaf] = {
            __route__: true,
            method: r.method.split('|')[0],
            uri,
            params,
            name: r.name,
        };
    }
    return tree;
}

function emitTree(tree, depth = 1) {
    const indent = '    '.repeat(depth);
    const inner = '    '.repeat(depth + 1);
    const lines = ['{'];

    const entries = Object.entries(tree).sort(([a], [b]) => a.localeCompare(b));

    for (const [key, value] of entries) {
        const safeKey = /^[a-zA-Z_$][a-zA-Z0-9_$]*$/.test(key) ? key : JSON.stringify(key);
        if (value && value.__route__) {
            const { uri, params, name, method } = value;
            const args = params
                .map((p) => `${p.name}${p.optional ? '?' : ''}: string | number`)
                .join(', ');
            const builder = params.length === 0
                ? `\`${escapeStringLiteral(uri)}\``
                : '`' + escapeStringLiteral(uri).replace(
                      /\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\?\\\}|\{([a-zA-Z_][a-zA-Z0-9_]*)\??\}/g,
                      (_, optName, reqName) => '${' + (optName ?? reqName) + ' ?? \'\'}',
                  ) + '`';

            lines.push(
                `${inner}/** ${method} ${uri} (name: ${name}) */`,
                `${inner}${safeKey}: (${args}) => ${builder},`,
            );
        } else {
            lines.push(`${inner}${safeKey}: ${emitTree(value, depth + 1)},`);
        }
    }

    lines.push(`${indent}}`);
    return lines.join('\n');
}

function main() {
    const routes = fetchRoutes();
    const tree = buildTree(routes);

    const generated = `// THIS FILE IS AUTO-GENERATED — DO NOT EDIT BY HAND.
// Run \`node scripts/generate-api-routes.mjs\` after changing routes/api.php.
//
// Source: \`php artisan route:list --json\` filtered by name prefix \`api.\`.
// Total routes: ${routes.length}.
//
// Used by the SPA (#220) to build API URLs without depending on Ziggy.

export const apiRoutes = ${emitTree(tree)} as const;

export type ApiRoutes = typeof apiRoutes;
`;

    writeIfChanged(OUT_PATH, generated, `api-routes.ts (${routes.length} routes)`);

    generateRouteMap();
}

/**
 * Emite `route-map.ts`: mapa plano de TODAS las rutas nombradas a su URI
 * template (con `{param}`). El resolver `route()` del shell SPA lo usa.
 */
function generateRouteMap() {
    const all = fetchAllNamedRoutes();
    const map = {};
    for (const r of all) {
        // Una ruta puede aparecer varias veces (varios métodos). El primer
        // registro gana — el URI es el mismo para todos los métodos.
        if (map[r.name] === undefined) {
            map[r.name] = '/' + r.uri.replace(/^\/?/, '');
        }
    }

    const entries = Object.entries(map)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([name, uri]) => `    ${JSON.stringify(name)}: ${JSON.stringify(uri)},`)
        .join('\n');

    const generated = `// THIS FILE IS AUTO-GENERATED — DO NOT EDIT BY HAND.
// Run \`node scripts/generate-api-routes.mjs\` after changing routes.
//
// Flat map of every named route (web + api) to its URI template.
// Consumed by \`route-compat.ts\` to resolve route names in the SPA shell
// where Ziggy's global \`route()\` is not available.
// Total named routes: ${Object.keys(map).length}.

export const ROUTE_MAP: Record<string, string> = {
${entries}
};
`;

    writeIfChanged(ROUTE_MAP_PATH, generated, `route-map.ts (${Object.keys(map).length} routes)`);
}

function writeIfChanged(path, content, label) {
    let previous = '';
    try {
        previous = readFileSync(path, 'utf8');
    } catch {
        /* file does not exist yet */
    }
    if (previous === content) {
        console.log(`${label} unchanged.`);
        return;
    }
    writeFileSync(path, content, 'utf8');
    console.log(`${label} written.`);
}

main();
