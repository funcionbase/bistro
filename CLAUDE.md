# CLAUDE.md — Reglas operativas del proyecto

## Índice — según la actividad, consultá:

| Si vas a... | Leé antes |
|---|---|
| Escribir/modificar código (planificación, issues, bugs, versionado) | `.claude/workflow.md` |
| Tocar frontend (React/Tailwind, componentes, DS) | `.claude/frontend.md` |
| Tocar inputs de texto de usuario (forms, persistencia, render) | `.claude/sanitizacion.md` |
| Refinar issues/HUs, tocar permisos/roles/`backend/constants/` | `.claude/rbac.md` |
| Tocar dinero (pagos, refunds, receipts, DIAN) o documentos legales | `.claude/contabilidad.md` |
| Auditar seguridad / buscar vulnerabilidades | `.claude/revision-seguridad.md` |
| Escribir PHP/Laravel | `.claude/stack-laravel.md` |

Siempre aplican, sin importar la actividad: `.claude/contabilidad.md` en código que toca dinero, `.claude/sanitizacion.md` en código que persiste texto de usuario.


---

## Tono (aplica a toda respuesta)

Puntual, directo, laboral. Español neutro, tuteo. Sin relleno ni preámbulos ("¡Claro que sí!").

**Regla central**: toda modificación se comunica con **motivo + solución** — qué cambió, por qué, cómo se resolvió. Nunca solo "listo" o "hecho".

- Honestidad antes que agradar: si algo es mala idea, decilo con el riesgo concreto ("eso rompe X en pdn porque Y").
- Errores propios: reconocerlos sin drama, con el fix.

Ejemplo: ❌ "Listo, ya quedó." → ✅ "Moví la validación a `StoreOrderRequest`: el `validate()` inline saltaba la sanitización del trait; ahora pasa por `SanitizesInput`."

**No aplica a** (convención propia): código, comentarios técnicos, strings de UI/API/errores, commits, `docs/wiki/`, `legal/`, `backend/CLAUDE.md`.
