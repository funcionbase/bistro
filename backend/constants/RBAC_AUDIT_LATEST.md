# RBAC Audit — snapshot más reciente

> Snapshot manual del comando `php artisan rbac:audit`. Útil como
> referencia humana entre PRs. Se actualiza cuando el baseline cambia
> (rutas nuevas, cierre de BRECHAs, slugs nuevos).

## Metadata

- **Fecha**: 2026-05-19
- **Commit base**: `f8dc7578` (rama `feature/215-rbac-audit-depuration`, HU #215)
- **Comando**: `php artisan rbac:audit --skip-catalog`
- **PR**: #216

## Resumen

| Estado | Cantidad |
|---|---:|
| Total rutas mutadoras | 170 |
| OK (con `permission:`) | 149 |
| ALLOW-LIST (público legítimo) | 17 |
| BYPASS (bot.jwt / table.guest) | 4 |
| **BRECHA** | **0** |
| INVALID-SLUG | 0 |
| INVALID-ACTION | 0 |

**Catálogo**: 74 slugs activos, todos con `PermissionTemplate` asociado.
Sin drift.

## Allow-list activa

Ver `bistro/backend/config/rbac.php` (sección `audit.public_routes`). 17
entries cubriendo:

- Webhooks externos firmados (HMAC): WhatsApp, CSP-report.
- Tokens únicos por correo: WhatsApp verification reject.
- Endpoints públicos del cliente final: menu scan, loyalty lookup/redeem.
- Carrito en construcción: migrate-jwt (cart JWT propio).
- Cart con cupones operados por staff: apply-coupon, active-auto-apply.
- Self-service del usuario autenticado: `DELETE /me`.
- Auth + onboarding sin rol asignado: enrollment.*, auth.select-company,
  auth.switch-company, auth.switch-branch, auth.logout.
- Chat reasignación entre sedes: auth compuesta en controller
  (slug + acceso a sede destino).

## Bypass middlewares activos

- `bot.jwt` — Bot interno (KDS, etc.) con JWT propio.
- `table.guest` — Sesión de mesa con QR vía `ResolveTableGuest`.

## Re-generar este snapshot

```bash
cd application
php artisan rbac:audit --skip-catalog  # tabla legible
php artisan rbac:audit --json          # JSON completo
```

Hoy NO corre en CI — mientras no haya PDN ni colaboradores externos, el
chequeo se hace local antes de cada PR. Cuando se reactive, el workflow
correría `--fail-on-gap` en cada PR a `develop`/`main` (ver historial
git de #215).
