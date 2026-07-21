# Revisión de ciberseguridad — búsqueda de bugs y gaps

> REGLA OBLIGATORIA. Consultar cuando el usuario pida: revisión de seguridad, "busca bugs", "busca gaps", "audita X", review de PR con foco seguridad, o análisis de vulnerabilidades de un módulo/endpoint/feature.

## Alcance y entregable

- **Entregable = hallazgos, no fixes**. Reportar primero: severidad, `archivo:línea`, escenario de explotación concreto (quién, con qué rol, qué payload, qué obtiene) y fix propuesto. Aplicar fixes solo si el usuario lo pide o si aplican las reglas de bugs proactivos de `.claude/workflow.md`.
- Ranking: **Crítico** (fuga cross-tenant, dinero, RCE/inyección) → **Alto** (bypass de permiso, IDOR, XSS almacenado) → **Medio** (rate limiting, info disclosure, race) → **Bajo** (hardening, defensa en profundidad).
- Cero hallazgos inventados: cada uno con evidencia en código. Si es hipótesis sin confirmar, marcarlo "PLAUSIBLE — verificar".
- Skills disponibles: `/security-review` (branch actual) y `/code-review` — usarlas cuando el pedido sea sobre un diff/PR; esta guía aplica encima como checklist de dominio.

## Checklist de superficie de ataque (específico de este proyecto)

### 1. Aislamiento multi-tenant y multi-sede (lo más crítico)
- Toda query de datos de empresa filtra por `company_nit` (vía `EnsureCompanyAccess` / scope) — buscar queries que reciban ID y no validen pertenencia.
- Datos operativos respetan `BranchScope` + `EnsureBranchAccess`; `?branch=all` solo con `AllowConsolidatedBranches` + `metrics.view_all_branches`.
- **IDOR**: las PK son UUID (`HasUuids`) — un UUID adivinable/filtrado NO es autorización. Route model binding debe validar ownership (empresa/sede), no solo existencia. Nunca castear `route('id')` a `(int)`.
- Endpoints que listan/exportan: ¿pueden devolver filas de otra empresa u otra sede sin `branch_users`?

### 2. RBAC y autorización
- Rutas sensibles con stack completo: `jwt` + `company.access` + `branch.access` + `permission:<slug>,<action>` (mapa en `backend/constants/MIDDLEWARE_MAP.md`). Endpoint nuevo sin `permission:` = hallazgo.
- `is_system=true` cubre owner+admin+employee y los 3 bypasean TODO por diseño — solo es bug en checks que deben ser owner-exclusivos (owner real = `name == config('roles.role_names.owner')`).
- Frontend que oculta un botón NO es control: verificar que el backend valida igual (cliente = zona hostil).
- Checklist completo de escenarios: `backend/constants/RBAC_CHECKLIST.md` y `.claude/rbac.md`.

### 3. Inputs y XSS (política completa en `.claude/sanitizacion.md`)
- `$request->validate(...)` inline para texto libre = hallazgo (debe ser FormRequest + `SanitizesInput` + `SafePlainText`).
- Mismatch `maxLength` (frontend) vs `maxBytes` (backend); `max:` en chars en vez de bytes.
- Render: `{!! !!}` en Blade, `dangerouslySetInnerHTML` en JSX, markdown fuera de `components/ui/markdown.tsx`.
- Salidas especiales: PDF, comanda térmica (strip ESC/POS `\x1B`/`\x1D`), email, SMS — texto de usuario escapado en cada una.
- Inventario de columnas críticas: `docs/wiki/SECURITY_INPUT_HANDLING.md`.

### 4. Endpoints públicos (QR de sede/mesa, pedidos sin auth, enrollment)
- Rate limiting con backend `redis` (en `file` el techo real es N × deseado — ver `.claude/infra-aws.md`).
- Precios y catálogo SIEMPRE del menú activo en BD, nunca del payload del cliente.
- ¿Qué puede enumerar un anónimo? (mesas, sedes, menús de otra empresa, session tokens de mesa).
- Métricas de escaneo: no confiar en headers manipulables para lógica de negocio.

### 5. Dinero (invariantes en `.claude/contabilidad.md`)
- Mutación financiera sin `DB::transaction` + `lockForUpdate` = race de doble cobro/doble refund.
- Receipts inmutables: cualquier endpoint que haga `UPDATE`/`DELETE` sobre `payment_receipts` o `invoices` = crítico.
- Refund sin `reference` en card/transfer, montos que confían en el cliente, `discount_amount` restado dos veces.

### 6. Webhooks e integraciones
- Whitelist de `NormalizeStrings` solo `/api/v1/webhooks/whatsapp` y `/api/v1/csp-report` — nada más se salta la normalización.
- Validación de firma/origen del webhook; idempotencia con `Cache::lock` (store compartido) contra replay y doble entrega.

### 7. Concurrencia N-instance
- Locks en `file`/`array` no protegen nada entre instancias. Double-submit en cierres de caja, jobs duplicados sin `onOneServer()`. Checklist en `.claude/infra-aws.md`.

### 8. Secretos, logs y disclosure
- Credenciales/API keys hardcodeadas, `.env` commiteado, tokens en logs o en responses de error (stacktraces en pdn).
- PII (teléfonos, nombres de clientes) en logs de CloudWatch/Papertrail sin necesidad.
- Respuestas de error que revelan existencia de recursos de otra empresa (404 vs 403 consistente).

### 9. Auditoría
- Acción sensible (dinero, permisos, borrado, acceso cross-branch) sin `AuditService::log(...)` con metadata reconstructible = gap (catálogo en `backend/constants/AUDIT_EVENTS.md`).

## Formato del reporte

```
## Revisión de seguridad — <alcance>

### Críticos
1. **<título>** — `archivo.php:NN`
   - Escenario: <rol/actor> hace <request concreto> y obtiene <impacto>.
   - Fix: <propuesta en 1-2 líneas>.

### Altos / Medios / Bajos
...

### Verificado sin hallazgos
- <áreas revisadas que quedaron limpias — para que el review sea auditable>
```

- Si el review es de un issue/PR, publicar el resumen como comentario de GitHub según `.claude/workflow.md` §3.
- Hallazgo crítico en pdn → avisarle al usuario de inmediato, antes de terminar el reporte completo.
