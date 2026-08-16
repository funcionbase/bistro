# bistro — frontend

SPA React 19 + Vite + Tailwind v4 + React Router 7 + TanStack Query.

## Desarrollo

```bash
npm install
cp .env.example .env
npm run dev
```

El prebuild sincroniza el branding desde `../branding/sync.mjs` y
`vite.config.ts` lee la versión del backend desde `../backend/composer.json`.

## Deploy

Cloudflare Workers Builds (integración Git sobre `main`, root `frontend/`,
watch `frontend/**`): cada push a `main` que toque `frontend/**` dispara
`npm ci && npm run build` + `npx wrangler deploy`. Sirve en
`bistro.example.com`. Fallback manual: `npx wrangler deploy` desde acá.

Las variables `VITE_*` de producción viven en `.env.production` (commiteado).
La IaC y los deploys del backend viven en
[`apps-bistro-co`](https://github.com/cristianmarint/apps-bistro-co).
