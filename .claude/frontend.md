# Frontend — componentes reutilizables

> REGLA OBLIGATORIA. Consultar antes de CUALQUIER cambio en frontend (React/Inertia/Tailwind).

1. **Revisa componentes existentes ANTES de escribir markup nuevo**. DS v3.1 documentado en `frontend/FRONTEND_UI_GUIDELINES.md` §6.2 y §11. Buscar en:
   - `frontend/src/components/ui/` (primitives shadcn + DS: PageHeader, DashboardPanel, FilterBar, DetailRow, KpiCell, Alert, Badge, Button, ConfirmDialog, Card, Dialog, Input, Label, Select, Checkbox, Skeleton, etc.).
   - `frontend/src/components/` (feature compartida: RoleBadge, EmptyState, etc.).
2. **Si existe, úsalo tal cual**. No duplicar markup. Prop nuevo → añadir al componente compartido, no inventar variante local.
3. **Si NO existe, créalo reutilizable**:
   - En `components/ui/` si es primitive del DS; en `components/` si es feature compartida.
   - Props tipadas (TS), JSDoc breve, tokens del DS (`bg-card`, `text-muted-foreground`, `border-border`, `var(--color-status-*)`), NUNCA hex hardcoded ni `bg-red-50`, `text-blue-500`, etc.
   - Si introduce patrón visual nuevo, actualizar `FRONTEND_UI_GUIDELINES.md` (§6.2 o §11).
4. **Nunca markup artesanal duplicado**: header con `<h1>` + flex, `<div fixed inset-0 z-50 bg-black/50>` para modal, banner `bg-amber-50` hardcoded, empty state pelado. Si lo ves, refactor.
5. **Verificación pre-commit obligatoria**: `npx tsc --noEmit` limpio, `npx eslint <archivos>` limpio, cero colores hardcoded en paletas semánticas.

**Excepción**: typos, ajustes de copy, `data-*` attributes.

## Deploy de frontend (Cloudflare)

**Siempre desde la rama `main`** (nunca desde una feature branch ni con cambios sin commitear).

1. **Preferencia — deploy local con wrangler**: desde `frontend`, `npm run build` + `npx wrangler deploy`. No depende de la cola de Workers Builds (wrangler ya quedó autenticado vía OAuth en esta máquina). Verificar contra `https://bistro.example.com/` después de deployar.
2. **Fallback — Cloudflare Workers Builds**: si el deploy local falla (auth vencida, build roto local), el push a `main` dispara el build remoto (watch path `frontend/**`). Revisar el build history en el dashboard (Workers → `bistro` → Deployments → View build history) si no se refleja en minutos.
