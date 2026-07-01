# Branding — fuente única de la marca bistro

Todos los recursos de marca (favicon, íconos PWA, apple-touch) viven **acá**. Las
apps (`frontend`, `backend`) **heredan** una copia vía `sync.mjs`; nunca se editan
los assets dentro de `*/public/` a mano.

## Estructura

| Ruta | Qué es |
|------|--------|
| `bistro-logo-dark.svg` / `bistro-logo-white.svg` | Logo "b" (FlexyFont) en tinta `#1E232E` y blanco de marca `#f6f5f3`. Wordmark suelto, fondo transparente. |
| `icon-source.svg` | Ícono app: "b" blanca sobre cuadrado redondeado `#1E232E`. Master del favicon/PWA (purpose `any`). |
| `icon-maskable.svg` | Variante full-bleed cuadrada (safe-zone) para íconos `maskable` y apple-touch. |
| `favicon.svg` | Igual a `icon-source.svg`; es el `<link rel="icon" type="image/svg+xml">`. |
| `web/**` | **Set desplegable** que heredan las apps: `favicon.ico`, `favicon.svg`, `icons/*.png`, `icons/icon-source.svg`. |
| `generate-icons.py` | Regenera los SVG master desde `FlexyFont.otf` (fontTools). |
| `sync.mjs` | Copia `web/**` a `frontend/public` y `backend/public`. |

## Cambiar la marca

1. Edita el/los SVG master acá (o corre `python generate-icons.py` tras tocar el glifo).
2. Regenera el `web/**` rasterizado (ver "Regenerar rasters").
3. `node sync.mjs` → propaga a las dos apps.

Para cambios que solo tocan SVG/ICO ya presentes en `web/`, basta editar en `web/`
y correr `sync.mjs`.

## Herencia automática en build

- **Frontend**: `predev` y `prebuild` en `frontend/package.json` corren `sync.mjs`,
  así que `npm run dev` / `npm run build` siempre traen la marca fresca.
- **Backend**: sirve `public/` estático; las copias van commiteadas. Tras editar
  branding, corre `node branding/sync.mjs` para actualizar `backend/public`.

## Regenerar rasters (PNG/ICO)

No hay rasterizador SVG nativo en el entorno. Los PNG de `web/icons/` se generan
rasterizando `icon-source.svg` / `icon-maskable.svg` con el canvas del navegador
(500→512/192/180/48/32/16) y el `.ico` se ensambla embebiendo los PNG 16/32/48.
Ver el historial de `generate-icons.py`; si cambia el ícono, repetir ese paso y
volver a `sync.mjs`.
